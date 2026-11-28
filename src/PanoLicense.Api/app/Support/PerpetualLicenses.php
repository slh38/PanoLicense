<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class PerpetualLicenses
{
    public static function enabled(): bool
    {
        return config('panolicense.perpetual_enabled') === true
            && (! app()->environment('production')
                || config('panolicense.perpetual_production_approved') === true);
    }

    public static function ensureEnabled(): void
    {
        if (! self::enabled()) {
            throw ValidationException::withMessages(['license_type' => 'Ömür boyu lisans dağıtımı henüz açılmadı.']);
        }
    }
}
