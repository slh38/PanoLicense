<?php

namespace App\Services;

use App\Enums\LicenseStatus;
use App\Models\Customer;
use App\Models\Device;
use App\Models\License;
use App\Models\Product;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * İstemci (PanoPOS) tarafındaki lisans işlemleri. Kota ve demo tekilliği için kritik bölümler
 * satır kilidi altında tek transaction'da yürür; aynı anda gelen istekler limiti aşamaz.
 */
class ClientLicenseService
{
    /**
     * Demo kaydı. Bir cihaz bir ürün için yalnız bir kez demo alır: aynı parmak izi tekrar gelirse
     * (yeniden kurulum dahil) yeni 14 gün açılmaz, mevcut kayıt döner.
     *
     * @return array{0: License, 1: Device, 2: bool} [lisans, cihaz, yeni mi]
     */
    public function registerDemo(Product $product, array $device, array $customer, ?string $installerUsername, ?string $ip): array
    {
        return DB::transaction(function () use ($product, $device, $customer, $installerUsername, $ip) {
            // Aynı cihazdan eşzamanlı iki demo isteğini sıraya sokar (demo trafiği düşüktür).
            Product::whereKey($product->id)->lockForUpdate()->first();

            $existing = Device::query()
                ->where('fingerprint_hash', $device['fingerprint'])
                ->whereHas('license', fn ($q) => $q->where('product_id', $product->id))
                ->with('license')
                ->latest('id')
                ->first();

            if ($existing) {
                $this->touch($existing, $device, $ip, force: true);

                return [$existing->license, $existing, false];
            }

            $installerUsername = $installerUsername !== null ? mb_strtolower(trim($installerUsername)) : null;
            $installer = $installerUsername
                ? User::where('username', $installerUsername)->where('is_active', true)->first()
                : null;

            $newCustomer = Customer::create([...$customer, 'created_by' => null]);
            $today = Carbon::today();

            $license = License::create([
                'license_key' => $this->uniqueKey($product->key_prefix),
                'customer_id' => $newCustomer->id,
                'product_id' => $product->id,
                'device_limit' => 1,
                'starts_at' => $today,
                'expires_at' => $today->copy()->addDays(config('panolicense.demo_days')),
                'status' => LicenseStatus::Demo,
                'source' => 'demo',
                'created_by' => null,
                'installer_username' => $installerUsername ?: null,
                'installed_by' => $installer?->id,
            ]);

            $newDevice = $this->createDevice($license, $device, $ip);

            Audit::log('license.demo_register', $license, [
                'customer' => $newCustomer->name,
                'installer' => $installerUsername ?: null,
                'installer_matched' => (bool) $installer,
                'device' => $device['name'] ?? null,
            ], userId: null);

            return [$license, $newDevice, true];
        });
    }

    /**
     * Lisansa cihaz ekler. Limit kontrolü lisans satırı kilitliyken yapılır.
     * Aynı cihaz tekrar gelirse yeni yer tüketmez.
     */
    public function activate(Product $product, string $licenseKey, array $device, ?string $ip): array
    {
        return DB::transaction(function () use ($product, $licenseKey, $device, $ip) {
            $license = License::where('license_key', $licenseKey)->lockForUpdate()->first();
            $this->assertUsable($license, $product);

            $existing = $license->devices()->where('fingerprint_hash', $device['fingerprint'])->first();
            if ($existing?->isActive()) {
                $this->touch($existing, $device, $ip, force: true);

                return [$license, $existing, false];
            }

            $activeCount = $license->activeDevices()->count();
            if ($activeCount >= $license->device_limit) {
                throw ClientLicenseException::deviceLimitExceeded($license->device_limit);
            }

            if ($existing) {
                // Daha önce serbest bırakılmış cihaz geri geliyor: yeniden yer tüketir.
                $existing->forceFill([
                    'status' => Device::STATUS_ACTIVE, 'released_at' => null, 'released_by' => null,
                ])->save();
                $this->touch($existing, $device, $ip, force: true);
                $result = $existing;
            } else {
                $result = $this->createDevice($license, $device, $ip);
            }

            Audit::log('license.device_activate', $license, [
                'device' => $device['name'] ?? null,
                'active_devices' => $activeCount + 1,
                'device_limit' => $license->device_limit,
            ], userId: null);

            return [$license, $result, true];
        });
    }

    /** Durum sorgusu. Kilit yok; tek indeksli sorgu, last_seen_at en fazla saatte bir yazılır. */
    public function check(Product $product, string $licenseKey, array $device, ?string $ip): array
    {
        $license = License::where('license_key', $licenseKey)->first();
        if (! $license) {
            throw ClientLicenseException::licenseNotFound();
        }
        if ($license->product_id !== $product->id) {
            throw ClientLicenseException::productMismatch();
        }

        $found = $license->devices()->where('fingerprint_hash', $device['fingerprint'])->first();
        if (! $found?->isActive()) {
            throw ClientLicenseException::deviceNotActivated();
        }

        $this->touch($found, $device, $ip);

        return [$license, $found];
    }

    private function assertUsable(?License $license, Product $product): void
    {
        if (! $license) {
            throw ClientLicenseException::licenseNotFound();
        }
        if ($license->product_id !== $product->id) {
            throw ClientLicenseException::productMismatch();
        }
        if ($license->status === LicenseStatus::Revoked) {
            throw ClientLicenseException::revoked();
        }
        if ($license->expires_at->lt(Carbon::today())) {
            throw ClientLicenseException::expired($license->status === LicenseStatus::Demo);
        }
    }

    private function createDevice(License $license, array $device, ?string $ip): Device
    {
        $now = now();

        return Device::create([
            'license_id' => $license->id,
            'fingerprint_hash' => $device['fingerprint'],
            'name' => $device['name'] ?? null,
            'app_version' => $device['app_version'] ?? null,
            'status' => Device::STATUS_ACTIVE,
            'last_ip' => $ip,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
        ]);
    }

    private function touch(Device $device, array $data, ?string $ip, bool $force = false): void
    {
        $stale = $device->last_seen_at->lt(now()->subMinutes(Device::SEEN_WRITE_INTERVAL_MINUTES));
        if (! $force && ! $stale) {
            return;
        }

        $device->forceFill([
            'last_seen_at' => now(),
            'last_ip' => $ip,
            'name' => $data['name'] ?? $device->name,
            'app_version' => $data['app_version'] ?? $device->app_version,
        ])->save();
    }

    private function uniqueKey(string $prefix): string
    {
        do {
            $key = License::generateKey($prefix);
        } while (License::where('license_key', $key)->exists());

        return $key;
    }
}
