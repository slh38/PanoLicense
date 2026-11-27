<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ürün modülü (ör. variant). Kod imzalı belgeye girer ve ürün yazılımı bu koda bakar; değişmez.
 * Pasif modül hiçbir lisansın geçerli modüllerinde yer almaz.
 */
class Module extends Model
{
    protected $fillable = ['product_id', 'code', 'name', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
