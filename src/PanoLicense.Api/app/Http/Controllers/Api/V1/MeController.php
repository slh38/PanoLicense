<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MeController extends Controller
{
    /**
     * Oturumdaki kullanıcı ve panelde menü/buton görünürlüğü için yetki listesi.
     * Asıl yetki kontrolü her uçta sunucuda yapılır.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $abilities = collect(Permission::cases())->map(fn (Permission $p) => $p->value)
            ->merge(Permission::ADMIN_ONLY)
            ->filter(fn (string $ability) => Gate::forUser($user)->allows($ability))
            ->values();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'role' => $user->role->value,
            'permissions' => $abilities,
        ]);
    }
}
