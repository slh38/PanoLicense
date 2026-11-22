<?php

use App\Http\Controllers\Api\V1\AuthController;
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

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', MeController::class);
    Route::get('/dashboard', DashboardController::class);

    Route::get('/products', [ProductController::class, 'index']);

    Route::get('/customers', [CustomerController::class, 'index'])->middleware('can:customer.view');

    Route::get('/licenses', [LicenseController::class, 'index'])->middleware('can:license.view');
    Route::post('/licenses', [LicenseController::class, 'store'])->middleware('can:license.create');
    Route::get('/licenses/{license}', [LicenseController::class, 'show'])->middleware('can:license.view');
    Route::post('/licenses/{license}/extend', [LicenseController::class, 'extend'])->middleware('can:license.extend');
    Route::post('/licenses/{license}/revoke', [LicenseController::class, 'revoke'])->middleware('can:license.revoke');

    Route::middleware('can:user.manage')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::patch('/users/{user}', [UserController::class, 'update']);
    });
});
