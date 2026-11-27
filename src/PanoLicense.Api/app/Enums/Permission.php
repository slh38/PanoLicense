<?php

namespace App\Enums;

/**
 * User rolüne tek tek verilebilen yetkiler. Gate adı olarak kullanılır:
 * Gate::allows(Permission::LicenseCreate->value) veya ->middleware('can:license.create').
 * Yeni yetki eklemek yalnız buraya bir case eklemektir; tablo yapısı değişmez.
 */
enum Permission: string
{
    case LicenseView = 'license.view';
    case LicenseCreate = 'license.create';
    case LicenseExtend = 'license.extend';
    case LicenseRevoke = 'license.revoke';
    case CustomerView = 'customer.view';
    case CustomerManage = 'customer.manage';
    case DeviceView = 'device.view';
    case DeviceRelease = 'device.release';
    case AuditView = 'audit.view';

    /**
     * Yalnız Admin'e ait işlemler; User'a verilemez.
     * license.list_all: tüm lisansları filtresiz listeleme (User yalnız arama + süresi dolacaklar).
     * license.modules: lisansın paketini değiştirme ve lisansa özel ek modül verme/kaldırma.
     */
    public const ADMIN_ONLY = ['user.manage', 'product.manage', 'settings.manage', 'license.list_all', 'license.offline_policy', 'license.modules'];

    /** Yeni User'a varsayılan yetkiler (onaylı: yeni lisans, süresi dolacaklar, arama). */
    public static function userDefaults(): array
    {
        return [self::LicenseView, self::LicenseCreate, self::CustomerView];
    }

    /** Panelde gösterilecek Türkçe ad. */
    public function label(): string
    {
        return match ($this) {
            self::LicenseView => 'Lisans arama ve süresi dolacakları görme',
            self::LicenseCreate => 'Yeni lisans verme',
            self::LicenseExtend => 'Lisans süresi uzatma',
            self::LicenseRevoke => 'Lisans iptal etme',
            self::CustomerView => 'Firmaları görme',
            self::CustomerManage => 'Firma düzenleme',
            self::DeviceView => 'Cihazları görme',
            self::DeviceRelease => 'Cihaz serbest bırakma',
            self::AuditView => 'Logları görme',
        };
    }
}
