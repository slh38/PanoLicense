<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    /** Veritabanına dokunmaz; yalnız uygulamanın ayakta olduğunu söyler. */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'version' => config('panolicense.version'),
        ]);
    }
}
