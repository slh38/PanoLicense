<?php

namespace App\Enums;

/** Panel kullanıcı rolleri. Admin her yetkiye sahiptir; User yalnız kendisine verilen yetkilere. */
enum UserRole: string
{
    case Admin = 'admin';
    case User = 'user';
}
