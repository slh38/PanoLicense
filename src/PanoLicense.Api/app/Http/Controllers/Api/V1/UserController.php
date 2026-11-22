<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Kullanıcı yönetimi (yalnız Admin). Panelden yalnız User rolü oluşturulur;
 * Admin hesabı sunucuda `php artisan panolicense:create-admin` ile açılır.
 * Kullanıcı silinmez, pasife alınır.
 */
class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::with('permissions')->orderBy('name')->get()->map(fn (User $u) => $this->present($u));

        return response()->json([
            'data' => $users,
            'available_permissions' => collect(Permission::cases())->map(fn (Permission $p) => [
                'value' => $p->value,
                'label' => $p->label(),
                'default' => in_array($p, Permission::userDefaults(), true),
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[a-z0-9._-]+$/', 'unique:users,username'],
            'password' => ['required', 'string', Password::min(10)->letters()->numbers()],
            'permissions' => ['array'],
            'permissions.*' => [new Enum(Permission::class)],
        ], ['username.regex' => 'Kullanıcı adı yalnız küçük harf, rakam, nokta, tire ve alt çizgi içerebilir.']);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'username' => $data['username'],
                'password' => $data['password'],
                'role' => UserRole::User,
                'is_active' => true,
            ]);
            $this->syncPermissions($user, $data['permissions'] ?? []);
            Audit::log('user.create', $user, ['username' => $user->username, 'permissions' => $data['permissions'] ?? []]);

            return $user;
        });

        return response()->json($this->present($user->load('permissions')), 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'nullable', 'string', Password::min(10)->letters()->numbers()],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => [new Enum(Permission::class)],
        ]);

        if ($user->is($request->user()) && array_key_exists('is_active', $data) && ! $data['is_active']) {
            throw ValidationException::withMessages(['is_active' => 'Kendi hesabınızı pasife alamazsınız.']);
        }
        if ($user->isAdmin() && array_key_exists('permissions', $data)) {
            throw ValidationException::withMessages(['permissions' => 'Admin her yetkiye zaten sahiptir.']);
        }

        DB::transaction(function () use ($user, $data) {
            $changes = array_filter([
                'name' => $data['name'] ?? null,
                'is_active' => $data['is_active'] ?? null,
            ], fn ($v) => $v !== null);
            if (! empty($data['password'])) {
                $changes['password'] = $data['password'];
            }
            $user->update($changes);

            if (array_key_exists('permissions', $data)) {
                $this->syncPermissions($user, $data['permissions']);
            }

            Audit::log('user.update', $user, [
                'fields' => array_keys(array_diff_key($changes, ['password' => 1])),
                'password_changed' => ! empty($data['password']),
                'permissions' => $data['permissions'] ?? null,
            ]);
        });

        return response()->json($this->present($user->fresh('permissions')));
    }

    private function syncPermissions(User $user, array $permissions): void
    {
        $user->permissions()->delete();
        foreach (array_unique($permissions) as $permission) {
            $user->permissions()->create(['permission' => $permission, 'granted_by' => auth()->id()]);
        }
    }

    private function present(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'username' => $u->username,
            'role' => $u->role->value,
            'is_active' => $u->is_active,
            'last_login_at' => $u->last_login_at?->toIso8601String(),
            // Enum sırasıyla (panelde tutarlı görünüm)
            'permissions' => collect(Permission::cases())
                ->filter(fn (Permission $p) => $u->permissions->contains('permission', $p))
                ->map(fn (Permission $p) => $p->value)->values(),
        ];
    }
}
