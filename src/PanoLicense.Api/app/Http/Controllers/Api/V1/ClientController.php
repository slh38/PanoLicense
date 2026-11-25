<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\License;
use App\Models\Product;
use App\Services\ClientLicenseException;
use App\Services\ClientLicenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * PanoPOS gibi istemcilerin çağırdığı uçlar (oturum yok, IP başına sınırlı).
 * F3a: imzasız durum bilgisi döner. F3b'de yanıta imzalı lisans belgesi eklenecek.
 *
 * Hata yanıtı: {"error": "<kod>", "message": "..."} — kodlar ClientLicenseException'da.
 */
class ClientController extends Controller
{
    public function __construct(private readonly ClientLicenseService $service)
    {
    }

    public function demo(Request $request): JsonResponse
    {
        $data = $request->validate([
            ...$this->deviceRules(),
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
        $data = $request->validate([...$this->deviceRules(), 'license_key' => ['required', 'string', 'max:40']]);

        return $this->respond(function () use ($data, $request) {
            [$license, $device, $created] = $this->service->activate(
                $this->product($data), strtoupper(trim($data['license_key'])), $data['device'], $request->ip(),
            );

            return response()->json($this->payload($license, $device) + ['created' => $created], $created ? 201 : 200);
        });
    }

    public function check(Request $request): JsonResponse
    {
        $data = $request->validate([...$this->deviceRules(), 'license_key' => ['required', 'string', 'max:40']]);

        return $this->respond(function () use ($data, $request) {
            [$license, $device] = $this->service->check(
                $this->product($data), strtoupper(trim($data['license_key'])), $data['device'], $request->ip(),
            );

            return response()->json($this->payload($license, $device));
        });
    }

    private function deviceRules(): array
    {
        return [
            'product_code' => ['required', 'string', Rule::exists('products', 'code')->where('is_active', true)],
            'device' => ['required', 'array'],
            'device.fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
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
            'offline_grace_days' => config('panolicense.offline_grace_days'),
            'server_time' => now()->toIso8601String(),
        ];
    }

    private function respond(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (ClientLicenseException $e) {
            return response()->json(['error' => $e->errorCode, 'message' => $e->getMessage()], $e->status);
        }
    }
}
