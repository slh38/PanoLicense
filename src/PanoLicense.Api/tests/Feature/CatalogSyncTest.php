<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\License;
use App\Models\Module;
use App\Models\Package;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Başlangıç kataloğu: onaylı matris, tekrar çalıştırmada mükerrer kayıt yok, Admin düzenlemeleri ezilmez. */
class CatalogSyncTest extends TestCase
{
    use RefreshDatabase;

    private const MATRIX = [
        'LITE' => ['quick_sale'],
        'PANO' => ['advanced_pricing', 'advanced_reports', 'bank', 'multi_warehouse', 'quick_sale', 'shift', 'variant'],
        'RESTAURANT' => ['advanced_pricing', 'advanced_reports', 'bank', 'mobile_waiter', 'multi_warehouse', 'restaurant', 'shift'],
        'PRO' => ['advanced_pricing', 'advanced_reports', 'bank', 'customer_display', 'erp_integration', 'mobile_waiter',
            'multi_branch', 'multi_warehouse', 'production', 'quick_sale', 'restaurant', 'shift', 'variant'],
    ];

    private function sync(array $options = []): void
    {
        $this->artisan('panolicense:catalog-sync', $options)->assertSuccessful();
    }

    private function matrix(): array
    {
        $product = Product::where('code', 'PANOPOS')->firstOrFail();

        return $product->packages()->with('modules')->get()
            ->mapWithKeys(fn (Package $p) => [$p->code => $p->modules->pluck('code')->sort()->values()->all()])->all();
    }

    public function test_applies_approved_matrix_and_pro_demo(): void
    {
        $this->sync();

        $this->assertSame(self::MATRIX, $this->matrix());
        $product = Product::where('code', 'PANOPOS')->firstOrFail();
        $this->assertSame(16, $product->modules()->count());
        $this->assertSame('PRO', $product->demoPackage->code);
        foreach (['fiscal_pos', 'e_invoice', 'cloud_backup', 'production'] as $code) {
            $this->assertStringContainsString('Planlanan', Module::where('code', $code)->value('description'));
        }
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalog.sync']);
    }

    public function test_running_again_creates_nothing_and_keeps_admin_changes(): void
    {
        $this->sync();
        $variant = Module::where('code', 'variant')->firstOrFail();
        $pano = Package::where('code', 'PANO')->firstOrFail();
        // Admin düzenlemeleri: ad, açıklama, pasiflik, eşleşme kaldırma/ekleme, paket limiti.
        $variant->update(['name' => 'Varyant (özel ad)', 'description' => 'Geliştirildi', 'is_active' => false]);
        $pano->modules()->detach($variant->id);
        $pano->modules()->attach(Module::where('code', 'restaurant')->value('id'));
        $pano->update(['default_device_limit' => 3, 'name' => 'Pano Plus']);
        Product::where('code', 'PANOPOS')->update(['demo_package_id' => $pano->id]);
        $before = [Module::count(), Package::count(), \DB::table('package_module')->count(), AuditLog::where('action', 'catalog.sync')->count()];

        $this->sync();

        $this->assertSame($before, [Module::count(), Package::count(), \DB::table('package_module')->count(), AuditLog::where('action', 'catalog.sync')->count()]);
        $variant->refresh();
        $this->assertSame(['Varyant (özel ad)', 'Geliştirildi', false], [$variant->name, $variant->description, $variant->is_active]);
        $pano->refresh();
        $this->assertSame([3, 'Pano Plus'], [$pano->default_device_limit, $pano->name]);
        $this->assertNotContains('variant', $this->matrix()['PANO']);
        $this->assertContains('restaurant', $this->matrix()['PANO']);
        $this->assertSame($pano->id, Product::where('code', 'PANOPOS')->value('demo_package_id'));
    }

    public function test_attach_missing_restores_defaults_without_removing_extra_links(): void
    {
        $this->sync();
        $pano = Package::where('code', 'PANO')->firstOrFail();
        $pano->modules()->detach(Module::where('code', 'variant')->value('id'));
        $pano->modules()->attach(Module::where('code', 'restaurant')->value('id'));

        $this->sync(['--attach-missing' => true]);

        $this->assertContains('variant', $this->matrix()['PANO']);
        $this->assertContains('restaurant', $this->matrix()['PANO']);
    }

    public function test_existing_modules_keep_their_links_and_new_modules_are_linked(): void
    {
        // Ön koşul: daha önce elle eklenmiş bir modül (ör. yerel deneme) ve migration'ın boş paketleri.
        $product = Product::where('code', 'PANOPOS')->firstOrFail();
        $product->modules()->create(['code' => 'shift', 'name' => 'Vardiya (elle)']);
        $product->modules()->create(['code' => 'table_order', 'name' => 'Deneme']);

        $this->sync();

        $this->assertNotContains('shift', $this->matrix()['PANO']); // var olan modülün eşleşmesine dokunulmaz
        $this->assertContains('variant', $this->matrix()['PANO']);  // yeni oluşan modül eşlenir
        $this->assertSame('Vardiya (elle)', Module::where('code', 'shift')->value('name'));
        $this->assertTrue(Module::where('code', 'table_order')->exists()); // hiçbir şey silinmez
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->sync(['--dry-run' => true]);

        $this->assertSame(0, Module::count());
        $this->assertSame(0, \DB::table('package_module')->count());
        $this->assertSame(0, AuditLog::where('action', 'catalog.sync')->count());
    }

    public function test_license_document_carries_package_modules_from_catalog(): void
    {
        $this->sync();
        $product = Product::where('code', 'PANOPOS')->firstOrFail();
        $license = License::create([
            'license_key' => License::generateKey('PAN'),
            'customer_id' => \App\Models\Customer::create(['name' => 'Katalog Lite'])->id,
            'product_id' => $product->id, 'package_id' => Package::where('code', 'LITE')->value('id'),
            'device_limit' => 1, 'starts_at' => '2026-11-28', 'expires_at' => '2030-11-28', 'status' => 'active',
        ]);

        $response = $this->clientPost('/api/v1/client/activate', [
            'product_code' => 'PANOPOS', 'license_key' => $license->license_key,
            'device' => ['fingerprint' => hash('sha256', 'katalog-lite')],
        ])->assertCreated()->json();
        $payload = json_decode(base64_decode(strtr($response['license_document']['payload'], '-_', '+/')), true);

        $this->assertSame('LITE', $payload['package']);
        $this->assertSame(['quick_sale'], $payload['modules']);
    }
}
