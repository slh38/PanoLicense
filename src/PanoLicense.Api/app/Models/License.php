<?php

namespace App\Models;

use App\Enums\LicenseStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class License extends Model
{
    /** "Süresi dolacak" penceresi (gün). Onaylı karar: 30 gün. */
    public const EXPIRING_WINDOW_DAYS = 30;

    protected $attributes = ['offline_grace_days' => 7];

    protected $fillable = [
        'license_key', 'customer_id', 'product_id', 'package_id', 'device_limit',
        'starts_at', 'expires_at', 'status', 'source', 'notes', 'created_by',
        'installer_username', 'installed_by', 'offline_grace_days',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'expires_at' => 'date',
            'revoked_at' => 'datetime',
            'approved_at' => 'datetime',
            'status' => LicenseStatus::class,
            'device_limit' => 'integer',
            'offline_grace_days' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Demo her durumda standarttır; bilinmeyen değer özel offline hak üretemez. */
    public function offlineGraceDays(): int
    {
        return $this->status !== LicenseStatus::Demo && $this->offline_grace_days === 365 ? 365 : 7;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /** Lisansa özel ek modüller (yalnız Admin verir/kaldırır; paket değişse de korunur). */
    public function addonModules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'license_module')->withPivot(['granted_by', 'created_at']);
    }

    /**
     * Geçerli modüller = paket modülleri + lisansa özel ek modüller; yalnız aktif modüller, koda göre sıralı.
     * İmzalı belgeye bu liste girer.
     * @return Collection<int, Module>
     */
    public function effectiveModules(): Collection
    {
        $this->loadMissing(['package.modules', 'addonModules']);

        return ($this->package?->modules ?? collect())
            ->concat($this->addonModules)
            ->filter(fn (Module $m) => $m->is_active && $m->product_id === $this->product_id)
            ->unique('id')
            ->sortBy('code')
            ->values();
    }

    /** @return list<string> */
    public function effectiveModuleCodes(): array
    {
        return $this->effectiveModules()->pluck('code')->all();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /** Kurulumu yapan personel (PanoPOS'a yazılan kullanıcı adı panel kullanıcısıyla eşleştiyse). */
    public function installer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'installed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function activeDevices(): HasMany
    {
        return $this->devices()->where('status', Device::STATUS_ACTIVE);
    }

    public function isUsable(): bool
    {
        return $this->status !== LicenseStatus::Revoked && ! $this->expires_at->lt(Carbon::today());
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

    /** Panelde gösterilen durum: revoked > demo/demo_expired > expired > expiring > active. */
    public function displayState(): string
    {
        if ($this->status === LicenseStatus::Revoked) {
            return 'revoked';
        }

        $today = Carbon::today();
        if ($this->status === LicenseStatus::Demo) {
            return $this->expires_at->lt($today) ? 'demo_expired' : 'demo';
        }
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
