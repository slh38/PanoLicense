<?php

namespace App\Enums;

/**
 * Veritabanında saklanan durum. "Süresi doldu/dolacak" tarihten hesaplanır, saklanmaz.
 * demo: PanoPOS kurulumundan gelen, onay bekleyen 14 günlük kayıt.
 */
enum LicenseStatus: string
{
    case Demo = 'demo';
    case Active = 'active';
    case Revoked = 'revoked';
}
