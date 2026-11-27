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
    /** P-256 açık anahtarını normalize eder; kimlik SHA-256(SPKI DER)'dir. */
    public function deviceKey(array $device): array
    {
        $key = @openssl_pkey_get_public($device['public_key'] ?? '');
        $details = $key ? openssl_pkey_get_details($key) : false;
        if (! $details || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
            throw new ClientLicenseException('invalid_device_key', 422, 'P-256 açık anahtarı gerekli.');
        }
        $pem = $details['key'];
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem), true);

        return [...$device, 'public_key' => $pem, 'public_key_hash' => hash('sha256', $der)];
    }

    /** Challenge rastgele ve 120 saniyeliktir; mesaj aynen UTF-8 olarak imzalanır. */
    public function challenge(Product $product, string $operation, string $licenseKey, array $device): array
    {
        $device = $this->deviceKey($device);
        $id = bin2hex(random_bytes(32));
        $expires = now()->addSeconds(120)->startOfSecond();
        $message = json_encode([
            'purpose' => 'panolicense-device-proof-v1',
            'challenge_id' => $id, 'operation' => $operation,
            'product' => $product->code, 'license_key' => $licenseKey,
            'fingerprint' => $device['fingerprint'], 'device_key_hash' => $device['public_key_hash'],
            'expires_at' => $expires->toIso8601ZuluString(),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        DB::table('device_challenges')->insert([
            'id' => $id, 'operation' => $operation, 'product_id' => $product->id,
            'license_key' => $licenseKey, 'fingerprint_hash' => $device['fingerprint'],
            'public_key_hash' => $device['public_key_hash'], 'message' => $message,
            'expires_at' => $expires, 'used_at' => null,
        ]);

        return ['challenge_id' => $id, 'message' => $message, 'expires_at' => $expires->toIso8601ZuluString()];
    }

    /** Atomik tüketim: eşzamanlı tekrarların yalnız biri geçer; iş kuralı reddinde de tüketilir. */
    public function prove(Product $product, string $operation, string $licenseKey, array $device, array $proof): array
    {
        $device = $this->deviceKey($device);
        $challenge = DB::table('device_challenges')->where('id', $proof['challenge_id'])
            ->where('operation', $operation)->where('product_id', $product->id)
            ->where('license_key', $licenseKey)->where('fingerprint_hash', $device['fingerprint'])
            ->where('public_key_hash', $device['public_key_hash'])
            ->whereNull('used_at')->where('expires_at', '>', now())->first();
        $signature = base64_decode($proof['signature'], true);
        if (! $challenge || $signature === false ||
            @openssl_verify($challenge->message, $signature, $device['public_key'], OPENSSL_ALGO_SHA256) !== 1) {
            throw new ClientLicenseException('invalid_device_proof', 403, 'Cihaz sahiplik kanıtı geçersiz veya süresi dolmuş.');
        }
        $consumed = DB::table('device_challenges')->where('id', $challenge->id)
            ->whereNull('used_at')->where('expires_at', '>', now())->update(['used_at' => now()]);
        if ($consumed !== 1) {
            throw new ClientLicenseException('invalid_device_proof', 403, 'Challenge daha önce kullanılmış.');
        }

        return $device;
    }

    private function assertDeviceKey(Device $stored, array $device): void
    {
        if (! $stored->public_key_hash) {
            throw new ClientLicenseException('device_reenrollment_required', 403, 'Eski cihaz kaydı yetkili tarafından serbest bırakılıp yeniden aktive edilmeli.');
        }
        if (! hash_equals($stored->public_key_hash, $device['public_key_hash'])) {
            throw new ClientLicenseException('device_key_mismatch', 403, 'Cihaz anahtarı kayıtla eşleşmiyor.');
        }
    }

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
                ->where(fn ($q) => $q->where('fingerprint_hash', $device['fingerprint'])
                    ->orWhere('public_key_hash', $device['public_key_hash']))
                ->whereHas('license', fn ($q) => $q->where('product_id', $product->id))
                ->with('license')
                ->latest('id')
                ->first();

            if ($existing) {
                $this->assertDeviceKey($existing, $device);
                if ($existing->fingerprint_hash !== $device['fingerprint']) {
                    throw new ClientLicenseException('device_key_mismatch', 403, 'Anahtar başka parmak izine bağlı.');
                }
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
                'package_id' => $product->demo_package_id, // onaylı: demo Pro paketiyle açılır
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
                'device_key_hash' => $device['public_key_hash'],
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
                $this->assertDeviceKey($existing, $device);
                $this->touch($existing, $device, $ip, force: true);

                return [$license, $existing, false];
            }

            if ($existing && $existing->public_key_hash) {
                $this->assertDeviceKey($existing, $device);
            }
            $sameKey = $license->devices()->where('public_key_hash', $device['public_key_hash'])->first();
            if ($sameKey && $sameKey->id !== $existing?->id) {
                throw new ClientLicenseException('device_key_mismatch', 403, 'Anahtar başka cihaz kaydına bağlı.');
            }
            $activeCount = $license->activeDevices()->count();
            if ($activeCount >= $license->device_limit) {
                throw ClientLicenseException::deviceLimitExceeded($license->device_limit);
            }

            if ($existing) {
                // Daha önce serbest bırakılmış cihaz geri geliyor: yeniden yer tüketir.
                $existing->forceFill([
                    'status' => Device::STATUS_ACTIVE, 'released_at' => null, 'released_by' => null,
                    'public_key' => $device['public_key'], 'public_key_hash' => $device['public_key_hash'],
                ])->save();
                $this->touch($existing, $device, $ip, force: true);
                $result = $existing;
            } else {
                $result = $this->createDevice($license, $device, $ip);
            }

            Audit::log('license.device_activate', $license, [
                'device_key_hash' => $device['public_key_hash'],
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

        $this->assertDeviceKey($found, $device);
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
            'public_key' => $device['public_key'], 'public_key_hash' => $device['public_key_hash'],
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
