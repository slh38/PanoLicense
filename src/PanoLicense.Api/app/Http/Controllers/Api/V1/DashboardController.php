<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LicenseStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\LicenseResource;
use App\Models\Customer;
use App\Models\Device;
use App\Models\License;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    /**
     * Admin: genel sayaçlar + son lisanslar. User: süresi dolacak sayısı + kendi verdiği lisanslar.
     * Tümü basit, indeksli COUNT sorgularıdır.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = Carbon::today();
        $isAdminView = Gate::allows('license.list_all');

        $expiring = License::expiring()->count();
        // Personelin kendi işleri: verdiği lisanslar + kurduğu demolar
        $mine = License::where(fn ($q) => $q->where('created_by', $user->id)->orWhere('installed_by', $user->id));

        $response = [
            'scope' => $isAdminView ? 'all' : 'own',
            'expiring_window_days' => License::EXPIRING_WINDOW_DAYS,
            'stats' => [
                'expiring' => $expiring,
                'created_by_me' => License::where('created_by', $user->id)->count(),
                'demo_pending' => License::where('status', LicenseStatus::Demo)
                    ->where('expires_at', '>=', $today)->count(),
            ],
            'recent' => LicenseResource::collection(
                ($isAdminView ? License::query() : $mine)
                    ->with(['customer', 'product', 'creator', 'installer', 'approver'])
                    ->latest('id')->limit(8)->get()
            ),
        ];

        if ($isAdminView) {
            $response['stats'] += [
                'active' => License::where('status', LicenseStatus::Active)->where(fn ($q) => $q->where('expires_at', '>=', $today)->orWhere(fn ($p) => $p->where('license_type', 'perpetual')->whereNull('expires_at')))->count(),
                'customers' => Customer::where('is_active', true)->count(),
                'devices' => Device::where('status', Device::STATUS_ACTIVE)->count(),
                'inactive' => License::where('status', LicenseStatus::Revoked)
                    ->orWhere(fn ($q) => $q->where('status', LicenseStatus::Active)->where('expires_at', '<', $today))
                    ->count(),
            ];
            $response['distribution'] = License::query()
                ->join('products', 'products.id', '=', 'licenses.product_id')
                ->leftJoin('packages', 'packages.id', '=', 'licenses.package_id')
                ->where('licenses.status', LicenseStatus::Active)
                ->where(fn ($q) => $q->where('licenses.expires_at', '>=', $today)->orWhere(fn ($p) => $p->where('licenses.license_type', 'perpetual')->whereNull('licenses.expires_at')))
                ->groupBy('products.name', 'packages.name')
                ->selectRaw("CONCAT(products.name, ' ', COALESCE(packages.name, '(paketsiz)')) as label, count(*) as value")
                ->get();
        }

        return response()->json($response);
    }
}
