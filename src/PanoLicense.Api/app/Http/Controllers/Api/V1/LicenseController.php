<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LicenseStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\LicenseResource;
use App\Models\Customer;
use App\Models\Device;
use App\Models\License;
use App\Models\Product;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LicenseController extends Controller
{
    /**
     * Listeleme kuralları:
     *  - scope=expiring: süresi 30 gün içinde dolacak / dolmuş aktif lisanslar (license.view)
     *  - q=...: firma adı veya lisans koduyla arama, tüm lisanslarda (license.view)
     *  - scope=demo: PanoPOS kurulumundan gelen, onay bekleyen demolar (license.view)
     *  - filtresiz tam liste yalnız Admin (license.list_all)
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'min:2', 'max:100'],
            'scope' => ['nullable', Rule::in(['expiring', 'demo', 'all'])],
        ]);
        $search = isset($data['q']) ? trim($data['q']) : null;
        $scope = $data['scope'] ?? null;

        if (! $search && ! in_array($scope, ['expiring', 'demo'], true)) {
            Gate::authorize('license.list_all');
        }

        $query = License::query()
            ->with(['customer', 'product', 'creator', 'installer', 'approver'])
            ->withCount('activeDevices');

        if ($scope === 'expiring') {
            $query->expiring()->orderBy('expires_at');
        } elseif ($scope === 'demo') {
            $query->where('status', LicenseStatus::Demo)->latest('id');
        } else {
            $query->latest('id');
        }

        if ($search) {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(function ($q) use ($like) {
                $q->where('license_key', 'like', $like)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like));
            });
        }

        return LicenseResource::collection($query->paginate(25));
    }

    public function show(License $license): LicenseResource
    {
        return new LicenseResource($this->loadDetail($license));
    }

    /**
     * Demo onayı: eksik firma bilgileri tamamlanır, cihaz limiti ve süre verilir, lisans aktif olur.
     * Onaylayan "lisansı veren" olarak kaydedilir; kurulumu yapan bilgisi korunur.
     */
    public function approve(Request $request, License $license): LicenseResource
    {
        $data = $request->validate([
            'customer' => ['required', 'array'],
            'customer.name' => ['required', 'string', 'min:2', 'max:150'],
            'customer.city' => ['nullable', 'string', 'max:100'],
            'customer.phone' => ['nullable', 'string', 'max:30'],
            'customer.email' => ['nullable', 'email', 'max:150'],
            'customer.tax_number' => ['nullable', 'string', 'max:20'],
            'device_limit' => ['required', 'integer', 'min:1', 'max:1000'],
            'starts_at' => ['required', 'date'],
            'expires_at' => ['required', 'date', 'after:starts_at', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $userId = $request->user()->id;

        try {
            DB::transaction(function () use ($license, $data, $userId) {
                $locked = License::whereKey($license->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== LicenseStatus::Demo) {
                    throw ValidationException::withMessages(['license' => 'Bu kayıt demo değil; zaten onaylanmış veya iptal edilmiş.']);
                }

                $locked->customer->update($data['customer']);
                $this->ensureNoActiveLicense($locked->customer_id, $locked->product_id);

                $locked->forceFill([
                    'status' => LicenseStatus::Active,
                    'device_limit' => $data['device_limit'],
                    'starts_at' => $data['starts_at'],
                    'expires_at' => $data['expires_at'],
                    'notes' => $data['notes'] ?? $locked->notes,
                    'created_by' => $userId,
                    'approved_by' => $userId,
                    'approved_at' => now(),
                ])->save();

                Audit::log('license.approve_demo', $locked, [
                    'customer' => $locked->customer->name,
                    'installer' => $locked->installer_username,
                    'device_limit' => $data['device_limit'],
                    'expires_at' => $data['expires_at'],
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'active_key')) {
                throw $this->activeLicenseError();
            }
            throw $e;
        }

        return new LicenseResource($this->loadDetail($license->fresh()));
    }

    /** Cihazı serbest bırakır (kayıt silinmez); lisansta yeni cihaza yer açılır. */
    public function releaseDevice(Request $request, License $license, Device $device): LicenseResource
    {
        abort_unless($device->license_id === $license->id, 404);
        if (! $device->isActive()) {
            throw ValidationException::withMessages(['device' => 'Cihaz zaten serbest bırakılmış.']);
        }

        DB::transaction(function () use ($device, $license, $request) {
            $device->forceFill([
                'status' => Device::STATUS_RELEASED,
                'released_at' => now(),
                'released_by' => $request->user()->id,
            ])->save();
            Audit::log('license.device_release', $license, ['device' => $device->name]);
        });

        return new LicenseResource($this->loadDetail($license));
    }

    private function loadDetail(License $license): License
    {
        return $license->load(['customer', 'product', 'creator', 'revoker', 'installer', 'approver', 'devices'])
            ->loadCount('activeDevices');
    }

    /** Yeni lisans: mevcut firmaya veya aynı işlemde oluşturulan yeni firmaya. Tek transaction. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'required_without:customer', 'integer', Rule::exists('customers', 'id')->where('is_active', true)],
            'customer' => ['nullable', 'required_without:customer_id', 'array'],
            'customer.name' => ['required_with:customer', 'string', 'min:2', 'max:150'],
            'customer.city' => ['nullable', 'string', 'max:100'],
            'customer.phone' => ['nullable', 'string', 'max:30'],
            'customer.email' => ['nullable', 'email', 'max:150'],
            'customer.tax_number' => ['nullable', 'string', 'max:20'],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('is_active', true)],
            'device_limit' => ['required', 'integer', 'min:1', 'max:1000'],
            'starts_at' => ['required', 'date'],
            'expires_at' => ['required', 'date', 'after:starts_at'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $userId = $request->user()->id;
        $product = Product::findOrFail($data['product_id']);

        try {
            $license = DB::transaction(function () use ($data, $userId, $product) {
                $customer = isset($data['customer_id'])
                    ? Customer::findOrFail($data['customer_id'])
                    : Customer::create([...$data['customer'], 'created_by' => $userId]);

                if ($customer->wasRecentlyCreated) {
                    Audit::log('customer.create', $customer, ['name' => $customer->name]);
                }

                $this->ensureNoActiveLicense($customer->id, $product->id);

                $license = License::create([
                    'license_key' => $this->uniqueKey($product->key_prefix),
                    'customer_id' => $customer->id,
                    'product_id' => $product->id,
                    'device_limit' => $data['device_limit'],
                    'starts_at' => $data['starts_at'],
                    'expires_at' => $data['expires_at'],
                    'status' => LicenseStatus::Active,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $userId,
                ]);

                Audit::log('license.create', $license, [
                    'customer' => $customer->name,
                    'product' => $product->code,
                    'device_limit' => $license->device_limit,
                    'expires_at' => $data['expires_at'],
                ]);

                return $license;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Eşzamanlı iki istek aynı firma + ürüne lisans vermeye çalışırsa index yakalar.
            if (str_contains($e->getMessage(), 'active_key')) {
                throw $this->activeLicenseError();
            }
            throw $e;
        }

        return (new LicenseResource($license->load(['customer', 'product', 'creator'])))
            ->response()->setStatusCode(201);
    }

    public function extend(Request $request, License $license): LicenseResource
    {
        $data = $request->validate([
            'expires_at' => ['required', 'date', 'after:'.$license->expires_at->toDateString()],
        ], ['expires_at.after' => 'Yeni bitiş tarihi mevcut bitiş tarihinden sonra olmalıdır.']);

        $this->ensureActive($license);
        $old = $license->expires_at->toDateString();

        DB::transaction(function () use ($license, $data, $old) {
            $license->update(['expires_at' => $data['expires_at']]);
            Audit::log('license.extend', $license, ['from' => $old, 'to' => $data['expires_at']]);
        });

        return new LicenseResource($this->loadDetail($license->fresh()));
    }

    /** İptal: kayıt silinmez; kim, ne zaman, neden saklanır. */
    public function revoke(Request $request, License $license): LicenseResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        // Aktif lisans veya onaylanmayacak demo iptal edilebilir.
        if ($license->status === LicenseStatus::Revoked) {
            throw ValidationException::withMessages(['license' => 'Lisans zaten iptal edilmiş.']);
        }

        DB::transaction(function () use ($license, $data, $request) {
            $license->forceFill([
                'status' => LicenseStatus::Revoked,
                'revoked_at' => now(),
                'revoked_by' => $request->user()->id,
                'revoke_reason' => $data['reason'],
            ])->save();
            Audit::log('license.revoke', $license, ['reason' => $data['reason']]);
        });

        return new LicenseResource($this->loadDetail($license->fresh()));
    }

    private function ensureNoActiveLicense(int $customerId, int $productId): void
    {
        $exists = License::where('customer_id', $customerId)
            ->where('product_id', $productId)
            ->where('status', LicenseStatus::Active)
            ->exists();

        if ($exists) {
            throw $this->activeLicenseError();
        }
    }

    private function activeLicenseError(): ValidationException
    {
        return ValidationException::withMessages([
            'customer_id' => 'Bu firmanın bu ürün için zaten aktif bir lisansı var. Süre uzatma için yöneticiye başvurun.',
        ]);
    }

    private function ensureActive(License $license): void
    {
        if ($license->status !== LicenseStatus::Active) {
            throw ValidationException::withMessages(['license' => 'Süre uzatma yalnız aktif lisansta yapılır (demo önce onaylanmalı).']);
        }
    }

    private function uniqueKey(string $prefix): string
    {
        do {
            $key = License::generateKey($prefix);
        } while (License::where('license_key', $key)->exists());

        return $key;
    }
}
