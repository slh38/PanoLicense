<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\License;
use App\Models\Module;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Support\LicenseSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Product $panopos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->panopos = Product::where('code', 'PANOPOS')->firstOrFail();
    }

    private function pkg(string $code): Package
    {
        return Package::where('product_id', $this->panopos->id)->where('code', $code)->firstOrFail();
    }

    private function module(string $code, ?Product $product = null): Module
    {
        return ($product ?? $this->panopos)->modules()->create(['code' => $code, 'name' => ucfirst($code)]);
    }

    private function createLicense(string $package = 'PRO', string $customer = 'Katalog Market'): License
    {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/licenses', [
            'customer' => ['name' => $customer], 'product_id' => $this->panopos->id,
            'package_id' => $this->pkg($package)->id, 'device_limit' => 2,
            'starts_at' => '2026-11-28', 'expires_at' => '2030-11-28',
        ])->assertCreated()->assertJsonPath('data.package.code', $package)->json('data.id');

        return License::findOrFail($id);
    }

    /** Cihaz online kontrol yapar; imzalı belgenin payload'u döner. */
    private function signedPayload(License $license, string $operation = 'activate'): array
    {
        $response = $this->clientPost("/api/v1/client/{$operation}", [
            'product_code' => 'PANOPOS', 'license_key' => $license->license_key,
            'device' => ['fingerprint' => hash('sha256', 'katalog-pc')],
        ])->assertSuccessful()->json();
        $env = $response['license_document'];
        $bytes = base64_decode(strtr($env['payload'], '-_', '+/'));
        $this->assertSame(1, openssl_verify($bytes, base64_decode(strtr($env['sig'], '-_', '+/')),
            LicenseSigner::fromConfig()->publicKeyPem(), OPENSSL_ALGO_SHA256));

        return json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_panopos_starts_with_four_packages_and_pro_demo(): void
    {
        $codes = $this->panopos->packages()->pluck('code')->all();
        $this->assertSame(['LITE', 'PANO', 'RESTAURANT', 'PRO'], $codes);
        $this->assertSame($this->pkg('PRO')->id, $this->panopos->demo_package_id);

        $this->actingAs($this->admin)->getJson('/api/v1/products')
            ->assertOk()->assertJsonPath('0.packages.3.code', 'PRO');
    }

    public function test_admin_manages_catalog_and_codes_are_immutable(): void
    {
        $this->actingAs($this->admin);
        $productId = $this->postJson('/api/v1/catalog/products', ['code' => 'MEZBAHA', 'name' => 'Mezbaha', 'key_prefix' => 'MEZ'])
            ->assertCreated()->json('data.id');
        $module = $this->postJson("/api/v1/catalog/products/{$productId}/modules", ['code' => 'kesimhane', 'name' => 'Kesimhane'])
            ->assertCreated()->json('data.id');
        $package = $this->postJson("/api/v1/catalog/products/{$productId}/packages", [
            'code' => 'STANDART', 'name' => 'Standart', 'default_device_limit' => 3, 'module_ids' => [$module],
        ])->assertCreated()->assertJsonPath('data.module_ids', [$module])->json('data.id');

        $this->patchJson("/api/v1/catalog/products/{$productId}", ['code' => 'BASKA', 'name' => 'Mezbaha Yönetim'])->assertOk()
            ->assertJsonPath('data.code', 'MEZBAHA')->assertJsonPath('data.name', 'Mezbaha Yönetim');
        $this->patchJson("/api/v1/catalog/packages/{$package}", ['module_ids' => []])->assertOk()->assertJsonPath('data.module_ids', []);

        // Kod biçimi, tekrar ve başka ürünün modülü reddedilir.
        $this->postJson('/api/v1/catalog/products', ['code' => 'mezbaha', 'name' => 'X1', 'key_prefix' => 'MEZ'])
            ->assertUnprocessable()->assertJsonValidationErrors(['code', 'key_prefix']);
        $foreign = $this->module('variant')->id;
        $this->patchJson("/api/v1/catalog/packages/{$package}", ['module_ids' => [$foreign]])
            ->assertUnprocessable()->assertJsonValidationErrors(['module_ids.0']);

        $this->assertSame(['catalog.module_create', 'catalog.package_create', 'catalog.package_update', 'catalog.product_create', 'catalog.product_update'],
            AuditLog::where('action', 'like', 'catalog.%')->distinct()->orderBy('action')->pluck('action')->all());
    }

    public function test_user_cannot_manage_catalog_or_change_package_and_modules_even_with_injected_permission(): void
    {
        $license = $this->createLicense();
        $user = User::factory()->create();
        foreach (['license.create', 'license.extend', 'license.view'] as $p) {
            $user->permissions()->create(['permission' => $p]);
        }
        \DB::table('user_permissions')->insert([
            ['user_id' => $user->id, 'permission' => 'product.manage'],
            ['user_id' => $user->id, 'permission' => 'license.modules'],
        ]);

        $this->actingAs($user);
        $this->getJson('/api/v1/catalog')->assertForbidden();
        $this->postJson('/api/v1/catalog/products', ['code' => 'MOBILYA', 'name' => 'Mobilya', 'key_prefix' => 'MOB'])->assertForbidden();
        $this->postJson("/api/v1/licenses/{$license->id}/package", ['package_id' => $this->pkg('LITE')->id])->assertForbidden();
        $this->putJson("/api/v1/licenses/{$license->id}/modules", ['module_ids' => []])->assertForbidden();
        $this->getJson('/api/v1/products')->assertOk(); // lisans formu için okuma serbest
    }

    public function test_license_requires_active_package_of_the_same_product(): void
    {
        $this->actingAs($this->admin);
        $other = Product::create(['code' => 'MOBILYA', 'name' => 'Mobilya', 'key_prefix' => 'MOB']);
        $foreign = $other->packages()->create(['code' => 'TEMEL', 'name' => 'Temel']);
        $body = ['customer' => ['name' => 'Paket Firması'], 'product_id' => $this->panopos->id, 'device_limit' => 1,
            'starts_at' => '2026-11-28', 'expires_at' => '2027-11-28'];

        $this->postJson('/api/v1/licenses', $body)->assertUnprocessable()->assertJsonValidationErrors(['package_id']);
        $this->postJson('/api/v1/licenses', $body + ['package_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors(['package_id']);
        $this->pkg('LITE')->update(['is_active' => false]);
        $this->postJson('/api/v1/licenses', $body + ['package_id' => $this->pkg('LITE')->id])->assertUnprocessable();
    }

    public function test_signed_document_contains_package_plus_addon_modules_and_changes_apply_on_next_check(): void
    {
        $variant = $this->module('variant');
        $shift = $this->module('shift');
        $backup = $this->module('backup');
        $inactive = $this->module('legacy');
        $inactive->update(['is_active' => false]);
        $this->pkg('LITE')->modules()->sync([$variant->id]);
        $this->pkg('PRO')->modules()->sync([$variant->id, $shift->id, $inactive->id]);

        $license = $this->createLicense('PRO');
        $this->putJson("/api/v1/licenses/{$license->id}/modules", ['module_ids' => [$backup->id]])->assertOk()
            ->assertJsonPath('data.modules.0.code', 'backup')->assertJsonPath('data.modules.0.source', 'license');

        $doc = $this->signedPayload($license);
        $this->assertSame('PRO', $doc['package']);
        $this->assertSame(['backup', 'shift', 'variant'], $doc['modules']); // pasif modül yok

        // Paket değişimi: cihaz limiti ve ek modüller değişmez; yeni belge sonraki kontrolde gelir.
        $this->postJson("/api/v1/licenses/{$license->id}/package", ['package_id' => $this->pkg('LITE')->id])->assertOk()
            ->assertJsonPath('data.package.code', 'LITE')->assertJsonPath('data.device_limit', 2)
            ->assertJsonPath('data.addon_module_ids', [$backup->id]);
        $doc = $this->signedPayload($license, 'check');
        $this->assertSame('LITE', $doc['package']);
        $this->assertSame(['backup', 'variant'], $doc['modules']);

        $this->putJson("/api/v1/licenses/{$license->id}/modules", ['module_ids' => []])->assertOk();
        $this->assertSame(['variant'], $this->signedPayload($license, 'check')['modules']);

        $logs = AuditLog::whereIn('action', ['license.package_change', 'license.modules_change'])->orderBy('id')->get();
        $this->assertEquals(['from' => 'PRO', 'to' => 'LITE'], $logs->firstWhere('action', 'license.package_change')->data);
        $this->assertEquals([['added' => ['backup'], 'removed' => []], ['added' => [], 'removed' => ['backup']]],
            $logs->where('action', 'license.modules_change')->pluck('data')->values()->all());
        $this->assertSame($this->admin->id, $logs->first()->user_id);
    }

    public function test_demo_uses_pro_package_and_approval_can_choose_package(): void
    {
        $response = $this->clientPost('/api/v1/client/demo', [
            'product_code' => 'PANOPOS', 'device' => ['fingerprint' => hash('sha256', 'demo-katalog')],
            'customer' => ['name' => 'Demo Katalog'],
        ])->assertCreated()->json();
        $payload = json_decode(base64_decode(strtr($response['license_document']['payload'], '-_', '+/')), true);
        $this->assertSame('PRO', $payload['package']);

        $license = License::where('license_key', $response['license_key'])->firstOrFail();
        $this->actingAs($this->admin)->postJson("/api/v1/licenses/{$license->id}/approve", [
            'customer' => ['name' => 'Demo Katalog'], 'device_limit' => 1,
            'starts_at' => now()->toDateString(), 'expires_at' => now()->addYear()->toDateString(),
            'package_id' => $this->pkg('PANO')->id,
        ])->assertOk()->assertJsonPath('data.package.code', 'PANO')->assertJsonPath('data.status', 'active');
    }

    public function test_addon_module_must_belong_to_license_product_and_revoked_license_is_locked(): void
    {
        $license = $this->createLicense();
        $other = Product::create(['code' => 'MOBILYA', 'name' => 'Mobilya', 'key_prefix' => 'MOB']);
        $foreign = $this->module('kesim', $other);

        $this->putJson("/api/v1/licenses/{$license->id}/modules", ['module_ids' => [$foreign->id]])
            ->assertUnprocessable()->assertJsonValidationErrors(['module_ids.0']);

        $license->update(['status' => 'revoked']);
        $this->postJson("/api/v1/licenses/{$license->id}/package", ['package_id' => $this->pkg('LITE')->id])->assertUnprocessable();
    }
}
