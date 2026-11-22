<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** İstek başına tek sorgu: yetkiler ilk kontrolde yüklenir, sonra bellekten okunur. */
    private ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
        ];
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(UserPermission::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function hasPermission(Permission $permission): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->isAdmin()) {
            return true;
        }

        $this->permissionCache ??= $this->permissions()->get()
            ->map(fn (UserPermission $p) => $p->permission->value)
            ->all();

        return in_array($permission->value, $this->permissionCache, true);
    }
}
