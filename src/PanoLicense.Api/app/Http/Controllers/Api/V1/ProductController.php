<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Models\Package;
use App\Models\Product;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    /** Lisans formu için: aktif ürünler, aktif paketleri ve paketlerin aktif modülleri (ön izleme). */
    public function index(): JsonResponse
    {
        $products = Product::where('is_active', true)->orderBy('name')
            ->with(['packages' => fn ($q) => $q->where('is_active', true), 'packages.modules' => fn ($q) => $q->where('is_active', true)])
            ->get();

        return response()->json($products->map(fn (Product $p) => [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'packages' => $p->packages->map(fn (Package $pk) => [
                'id' => $pk->id,
                'code' => $pk->code,
                'name' => $pk->name,
                'default_device_limit' => $pk->default_device_limit,
                'modules' => $pk->modules->sortBy('code')->values()->map(fn (Module $m) => ['code' => $m->code, 'name' => $m->name]),
            ])->values(),
        ]));
    }
}
