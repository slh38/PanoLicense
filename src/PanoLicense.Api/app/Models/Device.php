<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Device extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_RELEASED = 'released';

    /** last_seen_at en fazla bu aralıkta yazılır (her kontrolde DB yazımı olmasın). */
    public const SEEN_WRITE_INTERVAL_MINUTES = 60;

    protected $fillable = [
        'license_id', 'fingerprint_hash', 'name', 'app_version', 'status',
        'last_ip', 'first_seen_at', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
