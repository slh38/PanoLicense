<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Ürüne ait paket (ör. PanoPOS Lite/Pano/Restaurant/Pro). Kod değişmez; pasifleştirilir, silinmez. */
class Package extends Model
{
    protected $fillable = ['product_id', 'code', 'name', 'default_device_limit', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'default_device_limit' => 'integer', 'sort_order' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'package_module');
    }
}
