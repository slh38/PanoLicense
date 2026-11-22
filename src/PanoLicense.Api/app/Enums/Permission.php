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

    /** Yalnız Admin'e ait işlemler; User'a verilemez. */
    public const ADMIN_ONLY = ['user.manage', 'product.manage', 'settings.manage'];
}
