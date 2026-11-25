<?php

namespace App\Services;

use RuntimeException;

/**
 * İstemciye (PanoPOS) makine tarafından okunabilir hata kodu ile dönen lisans hatası.
 * Kodlar sabittir; istemci bunlara göre davranır, mesaj yalnız insan içindir.
 */
class ClientLicenseException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status, string $message)
    {
        parent::__construct($message);
    }

    public static function licenseNotFound(): self
    {
        return new self('license_not_found', 404, 'Lisans bulunamadı.');
    }

    public static function productMismatch(): self
    {
        return new self('product_mismatch', 422, 'Lisans bu ürüne ait değil.');
    }

    public static function revoked(): self
    {
        return new self('license_revoked', 403, 'Lisans iptal edilmiş.');
    }

    public static function expired(bool $demo): self
    {
        return $demo
            ? new self('demo_expired', 403, 'Demo süresi dolmuş.')
            : new self('license_expired', 403, 'Lisans süresi dolmuş.');
    }

    public static function deviceLimitExceeded(int $limit): self
    {
        return new self('device_limit_exceeded', 409, "Cihaz limiti dolu ({$limit}).");
    }

    public static function deviceNotActivated(): self
    {
        return new self('device_not_activated', 403, 'Bu cihaz lisansa kayıtlı değil veya serbest bırakılmış.');
    }
}
