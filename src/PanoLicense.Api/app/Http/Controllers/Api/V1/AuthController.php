<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Sanctum SPA girişi: önce GET /sanctum/csrf-cookie, sonra POST /api/v1/auth/login.
 * Oturum HttpOnly cookie'de tutulur; token üretilmez.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $attempt = [
            'username' => mb_strtolower(trim($credentials['username'])),
            'password' => $credentials['password'],
            'is_active' => true,
        ];

        if (! Auth::guard('web')->attempt($attempt)) {
            Audit::log('auth.login_failed', null, ['username' => $attempt['username']], userId: null);

            // Kullanıcı var mı / pasif mi bilgisini sızdırmayan tek mesaj.
            throw ValidationException::withMessages([
                'username' => 'Kullanıcı adı veya parola hatalı.',
            ]);
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::guard('web')->user();
        $user->forceFill(['last_login_at' => now()])->save();
        Audit::log('auth.login', $user);

        return response()->json(['ok' => true]);
    }

    public function logout(Request $request): JsonResponse
    {
        Audit::log('auth.logout', $request->user());

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }
}
