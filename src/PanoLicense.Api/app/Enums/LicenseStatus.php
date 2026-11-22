<?php

namespace App\Enums;

/** Veritabanında saklanan durum. "Süresi doldu/dolacak" tarihten hesaplanır, saklanmaz. */
enum LicenseStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
}
