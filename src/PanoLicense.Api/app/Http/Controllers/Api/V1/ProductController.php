<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Product::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name'])
        );
    }
}
