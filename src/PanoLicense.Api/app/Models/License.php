<?php

namespace App\Models;

use App\Enums\LicenseStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class License extends Model
{
    /** "Süresi dolacak" penceresi (gün). Onaylı karar: 30 gün. */
    public const EXPIRING_WINDOW_DAYS = 30;

    protected $fillable = [
        'license_key', 'customer_id', 'product_id', 'device_limit',
        'starts_at', 'expires_at', 'status', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'expires_at' => 'date',
            'revoked_at' => 'datetime',
            'status' => LicenseStatus::class,
            'device_limit' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /**
     * Aktif olup süresi önümüzdeki N gün içinde dolacak veya son N gün içinde dolmuş lisanslar.
     */
    public function scopeExpiring(Builder $query, int $days = self::EXPIRING_WINDOW_DAYS): Builder
    {
        $today = Carbon::today();

        return $query->where('status', LicenseStatus::Active)
            ->whereBetween('expires_at', [$today->copy()->subDays($days), $today->copy()->addDays($days)]);
    }

    /** Panelde gösterilen durum: revoked > expired > expiring > active. */
    public function displayState(): string
    {
        if ($this->status === LicenseStatus::Revoked) {
            return 'revoked';
        }

        $today = Carbon::today();
        if ($this->expires_at->lt($today)) {
            return 'expired';
        }

        return $this->expires_at->lte($today->copy()->addDays(self::EXPIRING_WINDOW_DAYS)) ? 'expiring' : 'active';
    }

    /** Okunması kolay, tahmin edilemez anahtar: PAN-8F3D-4E21-9C7A (çakışmaya karşı unique index var). */
    public static function generateKey(string $prefix): string
    {
        $groups = array_map(fn () => strtoupper(bin2hex(random_bytes(2))), range(1, 3));

        return Str::upper($prefix).'-'.implode('-', $groups);
    }
}
