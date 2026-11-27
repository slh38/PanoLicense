<?php

namespace App\Support;

use App\Models\Module;
use App\Models\Package;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * config/catalog.php başlangıç kataloğunu uygular. Güvenli ve tekrar çalıştırılabilir:
 *  - Yalnız eksik ürün/paket/modül eklenir; mevcut ad, açıklama, aktiflik, limit, sıra ezilmez.
 *  - Paket–modül eşleşmesi yalnız paket veya modül BU çalıştırmada oluşturulduysa eklenir; böylece Admin'in
 *    sonradan kaldırdığı eşleşme geri gelmez. `attachMissing` açıkça istenirse eksik eşleşmeler de eklenir.
 *  - Hiçbir kayıt veya eşleşme silinmez; lisanslara ve ek modüllere dokunulmaz.
 *  - Ürünün demo paketi yalnız boşsa ayarlanır.
 */
class CatalogSync
{
    /** @var list<string> */
    public array $log = [];

    public function __construct(private readonly array $catalog, private readonly bool $attachMissing = false)
    {
    }

    /** @return array{products:int, packages:int, modules:int, links:int} */
    public function run(bool $dryRun = false): array
    {
        $counts = ['products' => 0, 'packages' => 0, 'modules' => 0, 'links' => 0];

        $apply = function () use (&$counts) {
            foreach ($this->catalog['products'] ?? [] as $code => $def) {
                $product = Product::where('code', $code)->first();
                if (! $product) {
                    $product = Product::create(['code' => $code, 'name' => $def['name'], 'key_prefix' => $def['key_prefix'], 'is_active' => true]);
                    $counts['products']++;
                    $this->log[] = "ürün eklendi: {$code}";
                }

                $newModules = [];
                $modules = [];
                foreach ($def['modules'] as $moduleCode => [$name, $description]) {
                    $module = Module::where('product_id', $product->id)->where('code', $moduleCode)->first();
                    if (! $module) {
                        $module = $product->modules()->create(['code' => $moduleCode, 'name' => $name, 'description' => $description, 'is_active' => true]);
                        $newModules[$module->id] = true;
                        $counts['modules']++;
                        $this->log[] = "modül eklendi: {$code}/{$moduleCode}";
                    }
                    $modules[$moduleCode] = $module;
                }

                foreach ($def['packages'] as $packageCode => [$name, $sort, $moduleCodes]) {
                    $package = Package::where('product_id', $product->id)->where('code', $packageCode)->first();
                    $packageIsNew = false;
                    if (! $package) {
                        $package = $product->packages()->create([
                            'code' => $packageCode, 'name' => $name, 'sort_order' => $sort, 'default_device_limit' => 1, 'is_active' => true,
                        ]);
                        $packageIsNew = true;
                        $counts['packages']++;
                        $this->log[] = "paket eklendi: {$code}/{$packageCode}";
                    }

                    $linked = $package->modules()->pluck('modules.id')->all();
                    $attach = [];
                    foreach ($moduleCodes as $moduleCode) {
                        $module = $modules[$moduleCode] ?? throw new \InvalidArgumentException("Katalogda tanımsız modül: {$moduleCode}");
                        if (in_array($module->id, $linked, true)) {
                            continue;
                        }
                        if ($packageIsNew || isset($newModules[$module->id]) || $this->attachMissing) {
                            $attach[] = $module->id;
                            $this->log[] = "eşleşme eklendi: {$packageCode} + {$moduleCode}";
                        }
                    }
                    if ($attach) {
                        $package->modules()->attach($attach);
                        $counts['links'] += count($attach);
                    }
                }

                if ($product->demo_package_id === null && isset($def['demo_package'])) {
                    $product->update(['demo_package_id' => $product->packages()->where('code', $def['demo_package'])->value('id')]);
                    $this->log[] = "demo paketi ayarlandı: {$code}/{$def['demo_package']}";
                }
            }

            if (array_sum($counts) > 0) {
                Audit::log('catalog.sync', null, $counts, userId: null);
            }
        };

        if ($dryRun) {
            DB::beginTransaction();
            try {
                $apply();
            } finally {
                DB::rollBack(); // kuru çalıştırma: değişiklikleri raporla, yazma
            }
        } else {
            DB::transaction($apply);
        }

        return $counts;
    }
}
