<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Device;
use App\Models\License;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ClientActivationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    private function license(int $limit = 2, string $expires = '2030-01-01', string $status = 'active'): License
    {
        $c = Customer::create(['name' => 'Ada Market', 'created_by' => $this->admin->id]);

        return License::create([
            'license_key' => 'PAN-1111-2222-3333', 'customer_id' => $c->id, 'product_id' => Product::first()->id,
            'device_limit' => $limit, 'starts_at' => '2026-01-01', 'expires_at' => $expires,
            'status' => $status, 'created_by' => $this->admin->id,
        ]);
    }

    private function activate(string $seed, string $key = 'pan-1111-2222-3333'): \Illuminate\Testing\TestResponse
    {
        return $this->clientPost('/api/v1/client/activate', [
            'product_code' => 'PANOPOS',
            'license_key' => $key,
            'device' => ['fingerprint' => hash('sha256', $seed), 'name' => strtoupper($seed)],
        ]);
    }

    public function test_devices_are_activated_up_to_the_limit(): void
    {
        $this->license(2);

        $this->activate('kasa-1')->assertCreated()->assertJson(['state' => 'active', 'created' => true]);
        $this->activate('kasa-2')->assertCreated();
        $this->activate('kasa-3')->assertStatus(409)->assertJson(['error' => 'device_limit_exceeded']);

        $this->assertSame(2, Device::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'license.device_activate']);
    }

    public function test_same_device_does_not_consume_a_second_slot(): void
    {
        $this->license(1);

        $this->activate('kasa-1')->assertCreated();
        $this->activate('kasa-1')->assertOk()->assertJson(['created' => false]);
        $this->assertSame(1, Device::count());
    }

    public function test_released_device_frees_a_slot_and_is_kept(): void
    {
        $license = $this->license(1);
        $this->activate('kasa-1')->assertCreated();
        $device = Device::first();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/licenses/{$license->id}/devices/{$device->id}/release")
            ->assertOk()->assertJsonPath('data.devices.0.status', 'released');

        $this->activate('kasa-2')->assertCreated();
        $this->assertSame(2, Device::count()); // serbest bırakılan silinmedi

        // Serbest bırakılan cihaz artık kontrolde reddedilir
        $this->clientPost('/api/v1/client/check', [
            'product_code' => 'PANOPOS', 'license_key' => 'PAN-1111-2222-3333',
            'device' => ['fingerprint' => hash('sha256', 'kasa-1')],
        ])->assertForbidden()->assertJson(['error' => 'device_not_activated']);
    }

    public function test_revoked_expired_and_unknown_licenses_cannot_activate(): void
    {
        $this->activate('kasa-1', 'PAN-0000-0000-0000')->assertNotFound()->assertJson(['error' => 'license_not_found']);

        $license = $this->license(5, '2026-01-02');
        Carbon::setTestNow('2026-02-01');
        $this->activate('kasa-1')->assertForbidden()->assertJson(['error' => 'license_expired']);

        $license->update(['status' => 'revoked', 'expires_at' => '2030-01-01']);
        $this->activate('kasa-1')->assertForbidden()->assertJson(['error' => 'license_revoked']);
    }

    public function test_check_updates_last_seen_at_most_hourly(): void
    {
        $this->license(1);
        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->activate('kasa-1')->assertCreated();
        $body = ['product_code' => 'PANOPOS', 'license_key' => 'PAN-1111-2222-3333', 'device' => ['fingerprint' => hash('sha256', 'kasa-1')]];

        Carbon::setTestNow('2026-11-25 10:20:00');
        $this->clientPost('/api/v1/client/check', $body)->assertOk();
        $this->assertSame('10:00', Device::first()->last_seen_at->format('H:i'));

        Carbon::setTestNow('2026-11-25 11:30:00');
        $this->clientPost('/api/v1/client/check', $body)->assertOk();
        $this->assertSame('11:30', Device::first()->last_seen_at->format('H:i'));
    }

    public function test_user_without_release_permission_cannot_release(): void
    {
        $license = $this->license(1);
        $this->activate('kasa-1');
        $user = User::factory()->create();
        $user->permissions()->create(['permission' => 'license.view']);

        $this->actingAs($user)
            ->postJson("/api/v1/licenses/{$license->id}/devices/".Device::first()->id.'/release')
            ->assertForbidden();
    }
}
