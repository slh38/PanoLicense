<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = ['code', 'name', 'key_prefix', 'is_active', 'demo_package_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function packages(): HasMany
    {
        return $this->hasMany(Package::class)->orderBy('sort_order')->orderBy('id');
    }

    public function modules(): HasMany
    {
        return $this->hasMany(Module::class)->orderBy('code');
    }

    /** PanoPOS kurulumundaki demonun açıldığı paket (onaylı: Pro). */
    public function demoPackage(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'demo_package_id');
    }
}
