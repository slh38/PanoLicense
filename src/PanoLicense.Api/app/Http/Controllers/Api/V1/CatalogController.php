<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Models\Package;
use App\Models\Product;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Ürün / paket / modül kataloğu (yalnız Admin: product.manage).
 * Kodlar imzalı belgeye ve ürün yazılımına girdiği için oluşturulduktan sonra değişmez; adlar değişebilir.
 * Kayıtlar silinmez, pasifleştirilir. Her değişiklik denetim kaydına yazılır.
 */
class CatalogController extends Controller
{
    private const CODE = '/^[A-Z][A-Z0-9_]{1,29}$/';
    private const MODULE_CODE = '/^[a-z][a-z0-9_]{1,39}$/';

    /** Tüm katalog (pasifler dahil): ürünler → paketler (module_ids) + modüller. */
    public function index(): JsonResponse
    {
        $products = Product::orderBy('name')->with(['packages.modules:id', 'modules'])->get();

        return response()->json(['data' => $products->map(fn (Product $p) => $this->product($p))]);
    }

    public function storeProduct(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:'.self::CODE, 'unique:products,code'],
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'key_prefix' => ['required', 'string', 'regex:/^[A-Z]{3,5}$/', 'unique:products,key_prefix'],
        ], $this->messages());

        $product = DB::transaction(function () use ($data) {
            $product = Product::create([...$data, 'is_active' => true]);
            Audit::log('catalog.product_create', $product, ['code' => $product->code, 'name' => $product->name]);
            return $product;
        });

        return response()->json(['data' => $this->product($product->load(['packages.modules:id', 'modules']))], 201);
    }

    public function updateProduct(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'demo_package_id' => ['sometimes', 'nullable', 'integer',
                Rule::exists('packages', 'id')->where('product_id', $product->id)->where('is_active', true)],
        ]);

        DB::transaction(function () use ($product, $data) {
            $product->fill($data);
            $changes = $product->getDirty();
            if ($changes) {
                $product->save();
                Audit::log('catalog.product_update', $product, ['code' => $product->code, 'changes' => $changes]);
            }
        });

        return response()->json(['data' => $this->product($product->fresh()->load(['packages.modules:id', 'modules']))]);
    }

    public function storePackage(Request $request, Product $product): JsonResponse
    {
        $data = $this->validatePackage($request, $product, creating: true);

        $package = DB::transaction(function () use ($product, $data) {
            $package = $product->packages()->create([
                'code' => $data['code'], 'name' => $data['name'],
                'default_device_limit' => $data['default_device_limit'] ?? 1,
                'sort_order' => $data['sort_order'] ?? 0, 'is_active' => true,
            ]);
            $package->modules()->sync($data['module_ids'] ?? []);
            Audit::log('catalog.package_create', $package, [
                'product' => $product->code, 'code' => $package->code, 'modules' => $this->codes($data['module_ids'] ?? []),
            ]);
            return $package;
        });

        return response()->json(['data' => $this->package($package->load('modules:id'))], 201);
    }

    public function updatePackage(Request $request, Package $package): JsonResponse
    {
        $data = $this->validatePackage($request, $package->product, creating: false);

        DB::transaction(function () use ($package, $data) {
            $package->fill(collect($data)->except('module_ids')->all());
            $changes = $package->getDirty();
            $package->save();
            $log = $changes ? ['changes' => $changes] : [];
            if (array_key_exists('module_ids', $data)) {
                $result = $package->modules()->sync($data['module_ids'] ?? []);
                if ($result['attached'] || $result['detached']) {
                    $log['modules_added'] = $this->codes($result['attached']);
                    $log['modules_removed'] = $this->codes($result['detached']);
                }
            }
            if ($log) {
                Audit::log('catalog.package_update', $package, ['code' => $package->code, ...$log]);
            }
        });

        return response()->json(['data' => $this->package($package->fresh()->load('modules:id'))]);
    }

    public function storeModule(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:'.self::MODULE_CODE,
                Rule::unique('modules', 'code')->where('product_id', $product->id)],
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
        ], $this->messages());

        $module = DB::transaction(function () use ($product, $data) {
            $module = $product->modules()->create([...$data, 'is_active' => true]);
            Audit::log('catalog.module_create', $module, ['product' => $product->code, 'code' => $module->code]);
            return $module;
        });

        return response()->json(['data' => $this->module($module)], 201);
    }

    public function updateModule(Request $request, Module $module): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($module, $data) {
            $module->fill($data);
            $changes = $module->getDirty();
            if ($changes) {
                $module->save();
                Audit::log('catalog.module_update', $module, ['code' => $module->code, 'changes' => $changes]);
            }
        });

        return response()->json(['data' => $this->module($module->fresh())]);
    }

    private function validatePackage(Request $request, Product $product, bool $creating): array
    {
        $rules = [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:100'],
            'default_device_limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'module_ids' => ['sometimes', 'array'],
            // Paket yalnız kendi ürününün modüllerini içerebilir.
            'module_ids.*' => ['integer', 'distinct', Rule::exists('modules', 'id')->where('product_id', $product->id)],
        ];
        if ($creating) {
            $rules['code'] = ['required', 'string', 'regex:'.self::CODE,
                Rule::unique('packages', 'code')->where('product_id', $product->id)];
        } else {
            $rules['is_active'] = ['sometimes', 'boolean'];
        }

        $data = $request->validate($rules, $this->messages());
        if (! $creating && ($data['is_active'] ?? true) === false && $product->demo_package_id === $request->route('package')?->id) {
            throw ValidationException::withMessages(['is_active' => 'Demo paketi pasifleştirilemez; önce ürünün demo paketini değiştirin.']);
        }

        return $data;
    }

    private function codes(array $moduleIds): array
    {
        return $moduleIds ? Module::whereIn('id', $moduleIds)->orderBy('code')->pluck('code')->all() : [];
    }

    private function messages(): array
    {
        return [
            'code.regex' => 'Kod büyük harfle başlamalı; yalnız A-Z, 0-9 ve _ içerebilir (modüllerde küçük harf).',
            'key_prefix.regex' => 'Anahtar ön eki 3-5 büyük harf olmalıdır (ör. MEZ).',
        ];
    }

    private function product(Product $p): array
    {
        return [
            'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'key_prefix' => $p->key_prefix,
            'is_active' => $p->is_active, 'demo_package_id' => $p->demo_package_id,
            'packages' => $p->packages->map(fn (Package $pk) => $this->package($pk))->values(),
            'modules' => $p->modules->map(fn (Module $m) => $this->module($m))->values(),
        ];
    }

    private function package(Package $pk): array
    {
        return [
            'id' => $pk->id, 'product_id' => $pk->product_id, 'code' => $pk->code, 'name' => $pk->name,
            'default_device_limit' => $pk->default_device_limit, 'sort_order' => $pk->sort_order,
            'is_active' => $pk->is_active, 'module_ids' => $pk->modules->pluck('id')->sort()->values(),
        ];
    }

    private function module(Module $m): array
    {
        return [
            'id' => $m->id, 'product_id' => $m->product_id, 'code' => $m->code, 'name' => $m->name,
            'description' => $m->description, 'is_active' => $m->is_active,
        ];
    }
}
