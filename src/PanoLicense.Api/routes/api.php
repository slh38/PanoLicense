<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\LicenseController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

// Tüm uçlar /api/v1 altındadır (bootstrap/app.php: apiPrefix).

Route::get('/health', HealthController::class)->middleware('throttle:api');

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

// İstemci (PanoPOS) uçları: oturum yok, IP başına sınırlı. Ayrıntı: docs/CLIENT_API.md
Route::prefix('client')->group(function () {
    Route::post('/challenge', [ClientController::class, 'challenge'])->middleware('throttle:license-client');
    Route::post('/demo', [ClientController::class, 'demo'])->middleware('throttle:license-demo');
    Route::post('/activate', [ClientController::class, 'activate'])->middleware('throttle:license-client');
    Route::post('/check', [ClientController::class, 'check'])->middleware('throttle:license-client');
});

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', MeController::class);
    Route::get('/dashboard', DashboardController::class);

    Route::get('/products', [ProductController::class, 'index']);

    // Katalog yönetimi (yalnız Admin): kodlar değişmez, kayıtlar silinmez.
    Route::middleware('can:product.manage')->prefix('catalog')->group(function () {
        Route::get('/', [CatalogController::class, 'index']);
        Route::post('/products', [CatalogController::class, 'storeProduct']);
        Route::patch('/products/{product}', [CatalogController::class, 'updateProduct']);
        Route::post('/products/{product}/packages', [CatalogController::class, 'storePackage']);
        Route::patch('/packages/{package}', [CatalogController::class, 'updatePackage']);
        Route::post('/products/{product}/modules', [CatalogController::class, 'storeModule']);
        Route::patch('/modules/{module}', [CatalogController::class, 'updateModule']);
    });

    Route::get('/customers', [CustomerController::class, 'index'])->middleware('can:customer.view');

    Route::get('/licenses', [LicenseController::class, 'index'])->middleware('can:license.view');
    Route::post('/licenses', [LicenseController::class, 'store'])->middleware('can:license.create');
    Route::get('/licenses/{license}', [LicenseController::class, 'show'])->middleware('can:license.view');
    Route::post('/licenses/{license}/offline-policy', [LicenseController::class, 'offlinePolicy'])->middleware('can:license.offline_policy');
    // Paket değiştirme ve lisansa özel ek modüller: yalnız Admin. Cihaz limiti ve ek modüller pakete bağlı değişmez.
    Route::post('/licenses/{license}/package', [LicenseController::class, 'changePackage'])->middleware('can:license.modules');
    Route::put('/licenses/{license}/modules', [LicenseController::class, 'syncModules'])->middleware('can:license.modules');
    Route::post('/licenses/{license}/convert-perpetual', [LicenseController::class, 'convertToPerpetual'])->middleware('can:license.perpetual');
    Route::post('/licenses/{license}/extend', [LicenseController::class, 'extend'])->middleware('can:license.extend');
    Route::post('/licenses/{license}/revoke', [LicenseController::class, 'revoke'])->middleware('can:license.revoke');
    // Demo onayı = lisans vermek: lisans verme yetkisi olan onaylar.
    Route::post('/licenses/{license}/approve', [LicenseController::class, 'approve'])->middleware('can:license.create');
    Route::post('/licenses/{license}/devices/{device}/release', [LicenseController::class, 'releaseDevice'])->middleware('can:device.release');

    Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware('can:audit.view');

    Route::middleware('can:user.manage')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::patch('/users/{user}', [UserController::class, 'update']);
    });
});
