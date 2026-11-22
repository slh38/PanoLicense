<?php

use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MeController;
use Illuminate\Support\Facades\Route;

// Tüm uçlar /api/v1 altındadır (bootstrap/app.php: apiPrefix).

Route::get('/health', HealthController::class)->middleware('throttle:api');

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('/me', MeController::class);
});
