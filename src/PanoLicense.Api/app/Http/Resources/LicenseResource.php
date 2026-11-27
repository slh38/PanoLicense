<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\License */
class LicenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $person = fn (?User $u) => $u ? ['id' => $u->id, 'name' => $u->name, 'username' => $u->username] : null;

        return [
            'id' => $this->id,
            'license_key' => $this->license_key,
            'customer' => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'city' => $this->customer->city,
                'phone' => $this->customer->phone,
                'email' => $this->customer->email,
                'tax_number' => $this->customer->tax_number,
            ],
            'product' => ['id' => $this->product->id, 'name' => $this->product->name],
            'package' => $this->whenLoaded('package', fn () => $this->package
                ? ['id' => $this->package->id, 'code' => $this->package->code, 'name' => $this->package->name]
                : null),
            // Detayda: geçerli modüller (paket + ek) ve kaynağı. İmzalı belgeye aynı kodlar girer.
            'modules' => $this->when(
                $this->relationLoaded('addonModules') && $this->relationLoaded('package'),
                fn () => $this->effectiveModules()->map(fn ($m) => [
                    'id' => $m->id, 'code' => $m->code, 'name' => $m->name,
                    'source' => $this->package?->modules->contains('id', $m->id) ? 'package' : 'license',
                ])->values(),
            ),
            'addon_module_ids' => $this->whenLoaded('addonModules', fn () => $this->addonModules->pluck('id')->sort()->values()),
            'offline_grace_days' => $this->offlineGraceDays(),
            'device_limit' => $this->device_limit,
            'starts_at' => $this->starts_at->toDateString(),
            'expires_at' => $this->expires_at->toDateString(),
            'status' => $this->status->value,
            'state' => $this->displayState(),
            'source' => $this->source,
            'notes' => $this->notes,
            // Lisansı veren: panelden verildiyse oluşturan, demodan geldiyse onaylayan. Demo onaysa null.
            'created_by' => $person($this->creator),
            // Kurulumu yapan: PanoPOS'a yazılan kullanıcı adı; eşleşen panel kullanıcısı varsa o da.
            'installer' => $this->source === 'demo' ? [
                'username' => $this->installer_username,
                'user' => $person($this->installer),
            ] : null,
            'approved_by' => $person($this->approver),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revoked_by' => $this->whenLoaded('revoker', fn () => $person($this->revoker)),
            'revoke_reason' => $this->revoke_reason,
            'active_device_count' => $this->whenCounted('activeDevices'),
            'devices' => $this->whenLoaded('devices', fn () => $this->devices->map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'app_version' => $d->app_version,
                'status' => $d->status,
                'fingerprint_short' => substr($d->fingerprint_hash, 0, 12),
                'first_seen_at' => $d->first_seen_at->toIso8601String(),
                'last_seen_at' => $d->last_seen_at->toIso8601String(),
                'released_at' => $d->released_at?->toIso8601String(),
            ])),
        ];
    }
}
