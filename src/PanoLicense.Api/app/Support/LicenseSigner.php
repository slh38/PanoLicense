<?php

namespace App\Support;

use App\Models\Device;
use App\Models\License;
use RuntimeException;

/**
 * İmzalı lisans belgesi üretir (ECDSA P-256 / SHA-256, "ES256").
 *
 * Zarf: {"v":1,"kid":"k1","alg":"ES256","payload":"<base64url JSON>","sig":"<base64url DER imza>"}
 * İmza, payload'un çözülmüş ham baytları üzerindedir; istemci önce imzayı doğrular, sonra JSON'u okur.
 * Böylece JSON sıralama/biçim farkları imzayı bozmaz.
 *
 * Özel anahtar yalnız sunucudadır (LICENSE_SIGNING_KEY_PATH, public dizini dışında, 600).
 * İstemci yalnız açık anahtarı gömer. Sözleşme: docs/CLIENT_API.md
 */
class LicenseSigner
{
    public const ALG = 'ES256';
    public const VERSION = 1;

    private \OpenSSLAsymmetricKey $key;

    public function __construct(private readonly string $keyId, string $privateKeyPem)
    {
        $key = openssl_pkey_get_private($privateKeyPem);
        $details = $key ? openssl_pkey_get_details($key) : false;

        if (! $key || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
            throw new SigningUnavailableException('İmza anahtarı okunamadı veya P-256 değil.');
        }
        $this->key = $key;
    }

    /** config/panolicense.php → signing.key_path, signing.key_id */
    public static function fromConfig(): self
    {
        $path = config('panolicense.signing.key_path');
        if (! $path || ! is_readable($path)) {
            throw new SigningUnavailableException('İmza anahtarı yapılandırılmamış (LICENSE_SIGNING_KEY_PATH).');
        }

        return new self((string) config('panolicense.signing.key_id'), (string) file_get_contents($path));
    }

    /**
     * Cihaza bağlı lisans belgesi. valid_until: istemcinin sunucuya ulaşmadan çalışabileceği son an
     * (son doğrulama + offline süre; lisans bitişini geçemez).
     */
    public function document(License $license, Device $device): array
    {
        $issuedAt = now()->utc()->startOfSecond();
        $graceEnd = $issuedAt->copy()->addDays($license->offlineGraceDays());
        // Lisans, bitiş gününün sonuna kadar geçerlidir (sunucu saat dilimi UTC).
        $licenseEnd = $license->expires_at->copy()->utc()->addDay()->startOfDay();

        $license->loadMissing(['customer', 'product']);
        // Paket/modül değişiklikleri her yeni belgede güncel okunur (sonraki başarılı online kontrolde uygulanır).
        $license->load(['package.modules', 'addonModules']);

        return $this->sign([
            'v' => self::VERSION,
            'license_key' => $license->license_key,
            'product' => $license->product->code,
            'customer' => $license->customer->name,
            'status' => $license->status->value,
            'state' => $license->displayState(),
            'offline_grace_days' => $license->offlineGraceDays(),
            'package' => $license->package?->code,
            // Geçerli modüllerin tamamı: paket + lisansa özel ek modüller (aktif, koda göre sıralı).
            'modules' => $license->effectiveModuleCodes(),
            'device_limit' => $license->device_limit,
            'fingerprint' => $device->fingerprint_hash,
            'device_key_hash' => $device->public_key_hash,
            'device_status' => $device->status,
            'expires_at' => $license->expires_at->toDateString(),
            'issued_at' => $issuedAt->toIso8601ZuluString(),
            'valid_until' => ($graceEnd->lt($licenseEnd) ? $graceEnd : $licenseEnd)->toIso8601ZuluString(),
        ]);
    }

    public function sign(array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (! openssl_sign($json, $signature, $this->key, OPENSSL_ALGO_SHA256)) {
            throw new SigningUnavailableException('Belge imzalanamadı.');
        }

        return [
            'v' => self::VERSION,
            'kid' => $this->keyId,
            'alg' => self::ALG,
            'payload' => self::base64url($json),
            'sig' => self::base64url($signature),
        ];
    }

    public function publicKeyPem(): string
    {
        return openssl_pkey_get_details($this->key)['key'];
    }

    /**
     * Yeni P-256 anahtar çifti: [özel PEM, açık PEM]. Windows PHP'de openssl.cnf gerektiğinden
     * ilk deneme başarısızsa PHP ile gelen yapılandırma dosyasıyla tekrar denenir.
     */
    public static function generateKeyPair(): array
    {
        $options = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
        $key = @openssl_pkey_new($options);

        if (! $key) {
            $cnf = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf';
            $key = is_file($cnf) ? openssl_pkey_new($options + ['config' => $cnf]) : false;
            $exportOptions = is_file($cnf) ? ['config' => $cnf] : [];
        }
        if (! $key || ! openssl_pkey_export($key, $privatePem, null, $exportOptions ?? [])) {
            throw new RuntimeException('Anahtar üretilemedi: '.openssl_error_string());
        }

        return [$privatePem, openssl_pkey_get_details($key)['key']];
    }

    public static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
