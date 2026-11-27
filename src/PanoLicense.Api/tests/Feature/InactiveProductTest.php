<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Device;
use App\Models\License;
use App\Models\Product;
use App\Models\User;
use App\Support\LicenseSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InactiveProductTest extends TestCase
{
    use RefreshDatabase;

    private function license(int $days = 7): License
    {
        $product = Product::where('code', 'PANOPOS')->firstOrFail();
        return License::create([
            'license_key' => License::generateKey('PAN'),
            'customer_id' => Customer::create(['name' => 'Pasif Ürün Test'])->id,
            'product_id' => $product->id,
            'package_id' => $product->packages()->where('code', 'PRO')->value('id'),
            'device_limit' => 2, 'starts_at' => '2026-01-01', 'expires_at' => '2030-12-31',
            'status' => 'active', 'offline_grace_days' => $days,
        ]);
    }

    private function body(License $license, string $device = 'pc-1'): array
    {
        return ['product_code' => 'PANOPOS', 'license_key' => $license->license_key,
            'device' => ['fingerprint' => hash('sha256', $device)]];
    }

    private function deactivate(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->patchJson('/api/v1/catalog/products/'.Product::where('code', 'PANOPOS')->value('id'), ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);
    }

    public function test_inactive_product_blocks_new_license_demo_and_demo_challenge(): void
    {
        $license = $this->license();
        $demo = ['product_code' => 'PANOPOS', 'device' => ['fingerprint' => hash('sha256', 'new-demo')],
            'customer' => ['name' => 'Yeni Demo']];
        $signedDemo = $this->proofBody('demo', $demo); // pasifleştirmeden önce alınan kanıt da aşamaz.
        $this->deactivate();
        $this->postJson('/api/v1/licenses', [
            'customer' => ['name' => 'Yeni Lisans'], 'product_id' => $license->product_id,
            'package_id' => $license->package_id, 'device_limit' => 1,
            'starts_at' => '2026-11-27', 'expires_at' => '2030-12-31',
        ])->assertUnprocessable()->assertJsonValidationErrors('product_id');
        $this->postJson('/api/v1/client/challenge', [
            ...$demo, 'operation' => 'demo', 'device' => $signedDemo['device'],
        ])->assertUnprocessable()->assertJsonValidationErrors('product_code');
        $this->postJson('/api/v1/client/demo', $signedDemo)
            ->assertUnprocessable()->assertJsonValidationErrors('product_code');
        $this->assertSame(1, License::count());
        $this->assertSame(1, Customer::count());
        $this->assertSame(0, Device::count());
    }

    public function test_existing_license_activates_new_devices_and_repeats_without_extra_quota_when_product_is_inactive(): void
    {
        $license = $this->license();
        $this->deactivate();
        $this->clientPost('/api/v1/client/activate', $this->body($license))->assertCreated();
        $this->clientPost('/api/v1/client/activate', $this->body($license))->assertOk()->assertJson(['created' => false]);
        $this->clientPost('/api/v1/client/activate', $this->body($license, 'pc-2'))->assertCreated();
        $this->clientPost('/api/v1/client/activate', $this->body($license, 'pc-3'))
            ->assertStatus(409)->assertJson(['error' => 'device_limit_exceeded']);
        $this->assertSame(2, Device::count());
        $this->assertSame('active', $license->fresh()->status->value);
    }

    public function test_check_renews_both_offline_policies_and_preserves_package_modules_and_signature(): void
    {
        foreach ([7, 365] as $days) {
            Product::where('code', 'PANOPOS')->update(['is_active' => true]);
            Carbon::setTestNow('2026-11-27 10:00:00');
            $license = $this->license($days);
            $module = $license->product->modules()->firstOrCreate(['code' => 'variant'], ['name' => 'Varyant']);
            $license->package->modules()->sync([$module->id]);
            $body = $this->body($license, 'pc-'.$days);
            $this->clientPost('/api/v1/client/activate', $body)->assertCreated();
            $this->deactivate();
            Carbon::setTestNow('2026-11-28 10:00:00');
            $envelope = $this->clientPost('/api/v1/client/check', $body)->assertOk()
                ->assertJsonPath('offline_grace_days', $days)->assertJsonPath('status', 'active')->json('license_document');
            $bytes = base64_decode(strtr($envelope['payload'], '-_', '+/'));
            $this->assertSame(1, openssl_verify($bytes, base64_decode(strtr($envelope['sig'], '-_', '+/')),
                LicenseSigner::fromConfig()->publicKeyPem(), OPENSSL_ALGO_SHA256));
            $doc = json_decode($bytes, true);
            $this->assertSame('2026-11-28T10:00:00Z', $doc['issued_at']);
            $this->assertSame(now()->addDays($days)->toIso8601ZuluString(), $doc['valid_until']);
            $this->assertSame('PRO', $doc['package']);
            $this->assertSame(['variant'], $doc['modules']);
            $this->assertSame('active', $license->fresh()->status->value);
        }
    }

    public function test_inactive_product_does_not_bypass_revocation_expiry_or_device_proof(): void
    {
        Carbon::setTestNow('2026-11-27 10:00:00');
        $license = $this->license();
        $this->clientPost('/api/v1/client/activate', $this->body($license))->assertCreated();
        $this->deactivate();
        $attacker = $this->proofBody('check', $this->body($license), LicenseSigner::generateKeyPair());
        $this->postJson('/api/v1/client/check', $attacker)->assertForbidden()->assertJson(['error' => 'device_key_mismatch']);
        $license->update(['expires_at' => '2026-11-26']);
        $this->clientPost('/api/v1/client/activate', $this->body($license, 'expired'))
            ->assertForbidden()->assertJson(['error' => 'license_expired']);
        $license->update(['status' => 'revoked']);
        $this->clientPost('/api/v1/client/activate', $this->body($license, 'revoked'))
            ->assertForbidden()->assertJson(['error' => 'license_revoked']);
        $this->clientPost('/api/v1/client/check', $this->body($license))->assertOk()->assertJsonPath('state', 'revoked');
    }
}