<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\License;
use App\Models\Product;
use App\Services\ClientLicenseException;
use App\Services\ClientLicenseService;
use App\Support\LicenseSigner;
use App\Support\SigningUnavailableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * PanoPOS gibi istemcilerin çağırdığı uçlar (oturum yok, IP başına sınırlı).
 * Başarılı her yanıt, cihaza bağlı imzalı lisans belgesi (license_document) içerir; istemci 7 günlük
 * offline çalışmayı bu belgeyle yapar. İmza anahtarı yoksa uç 503 ile kapanır (imzasız belge verilmez).
 *
 * Hata yanıtı: {"error": "<kod>", "message": "..."} — kodlar ClientLicenseException'da.
 */
class ClientController extends Controller
{
    private ?LicenseSigner $signer = null;

    public function __construct(private readonly ClientLicenseService $service)
    {
    }

    public function challenge(Request $request): JsonResponse
    {
        $data = $request->validate([
            ...$this->deviceRules(requireActiveProduct: $request->input('operation') === 'demo'),
            'operation' => ['required', Rule::in(['demo', 'activate', 'check'])],
            'license_key' => ['required_unless:operation,demo', 'nullable', 'string', 'max:40'],
        ]);
        return $this->respond(fn () => response()->json($this->service->challenge(
            $this->product($data), $data['operation'],
            $data['operation'] === 'demo' ? '' : strtoupper(trim($data['license_key'])),
            $data['device'],
        )));
    }

    private function proofRules(): array
    {
        return [
            'proof.challenge_id' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'proof.signature' => ['required', 'string', 'max:128'],
        ];
    }

    public function demo(Request $request): JsonResponse
    {
        $data = $request->validate([
            ...$this->deviceRules(requireActiveProduct: true), ...$this->proofRules(),
            'customer' => ['required', 'array'],
            'customer.name' => ['required', 'string', 'min:2', 'max:150'],
            'customer.city' => ['nullable', 'string', 'max:100'],
            'customer.phone' => ['nullable', 'string', 'max:30'],
            'customer.email' => ['nullable', 'email', 'max:150'],
            'customer.tax_number' => ['nullable', 'string', 'max:20'],
            // İsteğe bağlı: kurulumu yapan personelin lisans sunucusundaki kullanıcı adı. Boşsa son kullanıcı.
            'installer_username' => ['nullable', 'string', 'max:50'],
        ]);

        return $this->respond(function () use ($data, $request) {
            $data['device'] = $this->service->prove($this->product($data), 'demo', '', $data['device'], $data['proof']);
            [$license, $device, $created] = $this->service->registerDemo(
                $this->product($data),
                $data['device'],
                $this->only($data['customer'], ['name', 'city', 'phone', 'email', 'tax_number']),
                $data['installer_username'] ?? null,
                $request->ip(),
            );

            return response()->json($this->payload($license, $device) + ['created' => $created], $created ? 201 : 200);
        });
    }

    public function activate(Request $request): JsonResponse
    {
        $data = $request->validate([...$this->deviceRules(), ...$this->proofRules(), 'license_key' => ['required', 'string', 'max:40']]);

        return $this->respond(function () use ($data, $request) {
            $data['device'] = $this->service->prove($this->product($data), 'activate', strtoupper(trim($data['license_key'])), $data['device'], $data['proof']);
            [$license, $device, $created] = $this->service->activate(
                $this->product($data), strtoupper(trim($data['license_key'])), $data['device'], $request->ip(),
            );

            return response()->json($this->payload($license, $device) + ['created' => $created], $created ? 201 : 200);
        });
    }

    public function check(Request $request): JsonResponse
    {
        $data = $request->validate([...$this->deviceRules(), ...$this->proofRules(), 'license_key' => ['required', 'string', 'max:40']]);

        return $this->respond(function () use ($data, $request) {
            $data['device'] = $this->service->prove($this->product($data), 'check', strtoupper(trim($data['license_key'])), $data['device'], $data['proof']);
            [$license, $device] = $this->service->check(
                $this->product($data), strtoupper(trim($data['license_key'])), $data['device'], $request->ip(),
            );

            return response()->json($this->payload($license, $device));
        });
    }

    private function deviceRules(bool $requireActiveProduct = false): array
    {
        // Pasifleştirme yeni demo/lisans satışını durdurur; mevcut lisansın yenilenmesini durdurmaz.
        $productRule = Rule::exists('products', 'code');
        if ($requireActiveProduct) {
            $productRule->where('is_active', true);
        }

        return [
            'offline_grace_days' => ['prohibited'],
            'product_code' => ['required', 'string', $productRule],
            'device' => ['required', 'array'],
            'device.fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'device.public_key' => ['required', 'string', 'max:1024'],
            'device.name' => ['nullable', 'string', 'max:100'],
            'device.app_version' => ['nullable', 'string', 'max:30'],
        ];
    }

    private function product(array $data): Product
    {
        return Product::where('code', $data['product_code'])->firstOrFail();
    }

    private function only(array $data, array $keys): array
    {
        return array_intersect_key($data, array_flip($keys));
    }

    /** İstemciye dönen lisans durumu. state: demo | demo_expired | active | expiring | expired | revoked */
    private function payload(License $license, Device $device): array
    {
        $license->loadMissing('customer');

        return [
            'license_key' => $license->license_key,
            'state' => $license->displayState(),
            'status' => $license->status->value,
            'expires_at' => $license->expires_at->toDateString(),
            'device_limit' => $license->device_limit,
            'device_status' => $device->status,
            'customer_name' => $license->customer->name,
            'offline_grace_days' => $license->offlineGraceDays(),
            'server_time' => now()->toIso8601String(),
            'license_document' => $this->signer->document($license, $device),
        ];
    }

    private function respond(callable $action): JsonResponse
    {
        try {
            // Anahtar, veritabanında iz bırakmadan önce yüklenir: yanlış yapılandırmada yan etki olmaz.
            $this->signer = LicenseSigner::fromConfig();

            return $action();
        } catch (ClientLicenseException $e) {
            return response()->json(['error' => $e->errorCode, 'message' => $e->getMessage()], $e->status);
        } catch (SigningUnavailableException $e) {
            Log::critical('Lisans imza anahtarı kullanılamıyor: '.$e->getMessage());

            return response()->json([
                'error' => 'signing_unavailable',
                'message' => 'Lisans sunucusu geçici olarak belge üretemiyor.',
            ], 503);
        }
    }
}
