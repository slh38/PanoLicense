<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\License;
use App\Models\Product;
use App\Models\User;
use App\Support\LicenseSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DeviceProofTest extends TestCase
{
    use RefreshDatabase;

    private function body(string $key, string $seed = 'pc-1'): array
    {
        return ['product_code' => 'PANOPOS', 'license_key' => $key,
            'device' => ['fingerprint' => hash('sha256', $seed)]];
    }

    private function demo(): array
    {
        $body = ['product_code' => 'PANOPOS', 'device' => ['fingerprint' => hash('sha256', 'pc-1')],
            'customer' => ['name' => 'Test Market']];
        $key = $this->clientPost('/api/v1/client/demo', $body)->assertCreated()->json('license_key');
        return $this->body($key);
    }

    public function test_copied_fingerprint_and_license_cannot_check_activate_or_retrieve_demo_with_another_key(): void
    {
        $body = $this->demo();
        $attacker = LicenseSigner::generateKeyPair();
        foreach (['check', 'activate', 'demo'] as $operation) {
            $request = $body;
            $request['customer'] = ['name' => 'Test Market'];
            $request = $this->proofBody($operation, $request, $attacker);
            $this->postJson('/api/v1/client/'.$operation, $request)->assertForbidden()
                ->assertJson(['error' => 'device_key_mismatch']);
        }
        $this->assertSame(1, Device::count());
    }

    public function test_challenge_is_single_use_and_bound_to_operation_product_license_and_device(): void
    {
        $body = $this->demo();
        $request = $this->proofBody('check', $body);
        $this->postJson('/api/v1/client/check', $request)->assertOk();
        $this->postJson('/api/v1/client/check', $request)->assertForbidden()->assertJson(['error' => 'invalid_device_proof']);

        $request = $this->proofBody('check', $body);
        $this->postJson('/api/v1/client/activate', $request)->assertForbidden()->assertJson(['error' => 'invalid_device_proof']);
        $changed = $request;
        $changed['license_key'] = 'PAN-AAAA-BBBB-CCCC';
        $this->postJson('/api/v1/client/check', $changed)->assertForbidden()->assertJson(['error' => 'invalid_device_proof']);
        $changed = $request;
        $changed['device']['fingerprint'] = hash('sha256', 'different');
        $this->postJson('/api/v1/client/check', $changed)->assertForbidden()->assertJson(['error' => 'invalid_device_proof']);
        Product::create(['name' => 'Other', 'code' => 'OTHER', 'key_prefix' => 'OTH', 'is_active' => true]);
        $changed = $request;
        $changed['product_code'] = 'OTHER';
        $this->postJson('/api/v1/client/check', $changed)->assertForbidden()->assertJson(['error' => 'invalid_device_proof']);
        $this->postJson('/api/v1/client/check', $request)->assertOk();
    }

    public function test_proof_needs_private_key_and_expires_at_exact_deadline(): void
    {
        Carbon::setTestNow('2026-11-27 10:00:00');
        $body = $this->demo();
        $request = $this->proofBody('check', $body);
        $forged = $request;
        [$private] = LicenseSigner::generateKeyPair();
        openssl_sign('forged', $signature, $private, OPENSSL_ALGO_SHA256);
        $forged['proof']['signature'] = base64_encode($signature);
        $this->postJson('/api/v1/client/check', $forged)->assertForbidden();
        Carbon::setTestNow('2026-11-27 10:02:00');
        $this->postJson('/api/v1/client/check', $request)->assertForbidden()->assertJson(['error' => 'invalid_device_proof']);
    }

    public function test_legacy_active_record_cannot_be_claimed_but_authorized_release_allows_proven_enrollment(): void
    {
        $body = $this->demo();
        $device = Device::first();
        $device->update(['public_key' => null, 'public_key_hash' => null]);
        foreach (['check', 'activate'] as $operation) {
            $this->clientPost('/api/v1/client/'.$operation, $body)->assertForbidden()
                ->assertJson(['error' => 'device_reenrollment_required']);
        }
        $this->clientPost('/api/v1/client/demo', [
            'product_code' => 'PANOPOS', 'device' => $body['device'], 'customer' => ['name' => 'Test Market'],
        ])->assertForbidden()->assertJson(['error' => 'device_reenrollment_required']);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->postJson("/api/v1/licenses/{$device->license_id}/devices/{$device->id}/release")->assertOk();
        $this->clientPost('/api/v1/client/activate', $body)->assertCreated();
        $this->clientPost('/api/v1/client/activate', $body)->assertOk()->assertJson(['created' => false]);
        $this->assertSame(1, Device::count());
        $this->assertNotNull($device->fresh()->public_key_hash);
    }

    public function test_wrong_product_is_rejected_even_with_a_valid_proof(): void
    {
        $body = $this->demo();
        Product::create(['name' => 'Other', 'code' => 'OTHER', 'key_prefix' => 'OTH', 'is_active' => true]);
        $body['product_code'] = 'OTHER';
        $this->clientPost('/api/v1/client/check', $body)->assertUnprocessable()->assertJson(['error' => 'product_mismatch']);
    }

    public function test_same_key_cannot_create_a_second_demo_with_a_changed_fingerprint(): void
    {
        $this->demo();
        $body = ['product_code' => 'PANOPOS', 'device' => ['fingerprint' => hash('sha256', 'changed')],
            'customer' => ['name' => 'Test Market']];
        $request = $this->proofBody('demo', $body, $this->deviceTestKey(hash('sha256', 'pc-1')));
        $this->postJson('/api/v1/client/demo', $request)->assertForbidden()->assertJson(['error' => 'device_key_mismatch']);
        $this->assertSame(1, License::count());
    }

    public function test_proven_new_key_still_consumes_quota_and_cannot_replace_a_released_bound_key(): void
    {
        $body = $this->demo();
        $other = $this->body($body['license_key'], 'pc-2');
        $this->clientPost('/api/v1/client/activate', $other)->assertStatus(409)->assertJson(['error' => 'device_limit_exceeded']);
        $device = Device::first();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->postJson("/api/v1/licenses/{$device->license_id}/devices/{$device->id}/release")->assertOk();
        $request = $this->proofBody('activate', $body, LicenseSigner::generateKeyPair());
        $this->postJson('/api/v1/client/activate', $request)->assertForbidden()->assertJson(['error' => 'device_key_mismatch']);
        $this->clientPost('/api/v1/client/activate', $other)->assertCreated();
        $this->clientPost('/api/v1/client/activate', $body)->assertStatus(409);
        $this->assertSame(1, Device::where('status', 'active')->count());
    }
    public function test_unsigned_requests_and_invalid_curves_are_rejected(): void
    {
        $body = $this->demo();
        $this->postJson('/api/v1/client/check', $body)->assertUnprocessable();
        $body['device']['public_key'] = 'invalid';
        $body['operation'] = 'check';
        $this->postJson('/api/v1/client/challenge', $body)->assertUnprocessable()->assertJson(['error' => 'invalid_device_key']);
    }
}