<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class Audit
{
    /**
     * Denetim kaydı yazar. Gizli değer (parola vb.) $data içine konmaz.
     */
    public static function log(string $action, ?Model $subject = null, array $data = [], ?int $userId = null): void
    {
        AuditLog::create([
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'data' => $data ?: null,
            'ip_address' => request()?->ip(),
        ]);
    }
}
