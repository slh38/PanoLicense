<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\License */
class LicenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'license_key' => $this->license_key,
            'customer' => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'city' => $this->customer->city,
            ],
            'product' => ['id' => $this->product->id, 'name' => $this->product->name],
            'device_limit' => $this->device_limit,
            'starts_at' => $this->starts_at->toDateString(),
            'expires_at' => $this->expires_at->toDateString(),
            'status' => $this->status->value,
            'state' => $this->displayState(),
            'notes' => $this->notes,
            // Lisansı kim verdi — her rol görür (onaylı gereksinim).
            'created_by' => ['id' => $this->creator->id, 'name' => $this->creator->name],
            'created_at' => $this->created_at->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revoked_by' => $this->whenLoaded('revoker', fn () => $this->revoker
                ? ['id' => $this->revoker->id, 'name' => $this->revoker->name] : null),
            'revoke_reason' => $this->revoke_reason,
        ];
    }
}
