<?php

namespace App\Models;

use App\Enums\Permission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPermission extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['permission', 'granted_by'];

    protected function casts(): array
    {
        return ['permission' => Permission::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
