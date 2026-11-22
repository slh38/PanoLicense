<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    /** Firma arama/listeleme (lisans formundaki seçim ve Müşteriler sayfası). */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        $query = Customer::query()
            ->where('is_active', true)
            ->withCount(['licenses as active_license_count' => fn ($q) => $q->where('status', 'active')])
            ->with('creator:id,name')
            ->orderBy('name');

        if (! empty($data['q'])) {
            $query->where('name', 'like', '%'.addcslashes(trim($data['q']), '\\%_').'%');
        }

        return response()->json($query->paginate(25)->through(fn (Customer $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'city' => $c->city,
            'phone' => $c->phone,
            'active_license_count' => $c->active_license_count,
            'created_by' => $c->creator?->name,
        ]));
    }
}
