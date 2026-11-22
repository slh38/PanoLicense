<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Yalnız bilinen proxy'lere güvenilir (ör. Cloudflare IP aralıkları); liste boşsa hiçbirine.
        // Herkese güvenmek ("*") X-Forwarded-For ile IP sahteciliğine ve rate limit atlatmaya açar.
        $proxies = config('panolicense.trusted_proxies');
        if ($proxies !== []) {
            TrustProxies::at($proxies);
        }

        $this->configureAuthorization();
        $this->configureRateLimiting();
    }

    /**
     * Yetki modeli: pasif kullanıcı hiçbir şey yapamaz, Admin her şeyi yapar,
     * User yalnız kendisine verilen Permission değerlerini kullanır.
     */
    private function configureAuthorization(): void
    {
        Gate::before(function (User $user) {
            if (! $user->is_active) {
                return false;
            }

            return $user->isAdmin() ? true : null;
        });

        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user) => $user->hasPermission($permission));
        }

        // Yalnız Admin işlemleri: Gate::before Admin'i geçirir, diğer herkes reddedilir.
        foreach (Permission::ADMIN_ONLY as $ability) {
            Gate::define($ability, fn () => false);
        }
    }

    private function configureRateLimiting(): void
    {
        // Panel API'si: oturumlu kullanıcı başına, yoksa IP başına.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));

        // Panel girişi (F2): kaba kuvvet denemelerine karşı sıkı sınır.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        // İstemci (PanoPOS) aktivasyon ve doğrulama uçları (F3).
        RateLimiter::for('license-client', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
    }
}
