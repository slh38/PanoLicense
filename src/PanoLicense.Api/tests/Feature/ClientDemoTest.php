<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\Device;
use App\Models\License;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ClientDemoTest extends TestCase
{
    use RefreshDatabase;

    private function fp(string $seed = 'pc-1'): string
    {
        return hash('sha256', $seed);
    }

    private function demo(array $override = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/client/demo', array_replace_recursive([
            'product_code' => 'PANOPOS',
            'device' => ['fingerprint' => $this->fp(), 'name' => 'KASA-1', 'app_version' => '1.0.0'],
            'customer' => ['name' => 'Ada Market', 'city' => 'Kayseri', 'phone' => '0352 000 00 00'],
        ], $override));
    }

    private function staff(string $username = 'i.simsek'): User
    {
        $u = User::factory()->create(['name' => 'İbrahim Şimşek', 'username' => $username]);
        foreach (Permission::userDefaults() as $p) {
            $u->permissions()->create(['permission' => $p->value]);
        }

        return $u;
    }

    public function test_demo_registration_creates_14_day_demo_with_device(): void
    {
        Carbon::setTestNow('2026-11-25 10:00:00');

        $res = $this->demo()->assertCreated()
            ->assertJson(['state' => 'demo', 'status' => 'demo', 'expires_at' => '2026-12-09', 'device_limit' => 1, 'created' => true]);

        $license = License::where('license_key', $res->json('license_key'))->firstOrFail();
        $this->assertSame('demo', $license->source);
        $this->assertNull($license->created_by);
        $this->assertNull($license->installer_username); // son kullanıcı
        $this->assertSame(1, $license->devices()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'license.demo_register', 'user_id' => null]);
    }

    public function test_installer_username_is_matched_to_panel_user(): void
    {
        $staff = $this->staff('i.simsek');

        $key = $this->demo(['installer_username' => ' I.Simsek '])->assertCreated()->json('license_key');

        $license = License::where('license_key', $key)->first();
        $this->assertSame('i.simsek', $license->installer_username);
        $this->assertSame($staff->id, $license->installed_by);
    }

    public function test_unknown_installer_username_is_kept_but_not_matched(): void
    {
        $key = $this->demo(['installer_username' => 'bilinmeyen'])->assertCreated()->json('license_key');

        $license = License::where('license_key', $key)->first();
        $this->assertSame('bilinmeyen', $license->installer_username);
        $this->assertNull($license->installed_by);
    }

    public function test_reinstall_on_same_device_does_not_open_a_new_demo(): void
    {
        Carbon::setTestNow('2026-11-01');
        $first = $this->demo()->assertCreated()->json('license_key');

        Carbon::setTestNow('2026-11-25'); // demo bitmiş
        $this->demo(['customer' => ['name' => 'Başka Ad']])
            ->assertOk()
            ->assertJson(['license_key' => $first, 'state' => 'demo_expired', 'created' => false]);

        $this->assertSame(1, License::count());
    }

    public function test_invalid_fingerprint_and_unknown_product_are_rejected(): void
    {
        $this->demo(['device' => ['fingerprint' => 'ham-donanim-bilgisi']])->assertUnprocessable()->assertJsonValidationErrors('device.fingerprint');
        $this->demo(['product_code' => 'YOK'])->assertUnprocessable()->assertJsonValidationErrors('product_code');
    }

    public function test_demo_endpoint_is_rate_limited(): void
    {
        foreach (range(1, 3) as $i) {
            $this->demo(['device' => ['fingerprint' => $this->fp("pc-$i")]])->assertCreated();
        }
        $this->demo(['device' => ['fingerprint' => $this->fp('pc-4')]])->assertTooManyRequests();
    }

    public function test_staff_with_create_permission_approves_demo_and_both_people_are_shown(): void
    {
        $installer = $this->staff('i.simsek');
        $approver = User::factory()->admin()->create(['name' => 'Salih Kocamaz']);
        $key = $this->demo(['installer_username' => 'i.simsek'])->json('license_key');
        $license = License::where('license_key', $key)->first();

        $this->actingAs($approver)->postJson("/api/v1/licenses/{$license->id}/approve", [
            'customer' => ['name' => 'Ada Market Ltd.', 'city' => 'Kayseri', 'tax_number' => '1234567890'],
            'device_limit' => 3,
            'starts_at' => now()->toDateString(),
            'expires_at' => now()->addYear()->toDateString(),
        ])->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.device_limit', 3)
            ->assertJsonPath('data.customer.name', 'Ada Market Ltd.')
            ->assertJsonPath('data.installer.user.name', 'İbrahim Şimşek')
            ->assertJsonPath('data.created_by.name', 'Salih Kocamaz')
            ->assertJsonPath('data.approved_by.name', 'Salih Kocamaz')
            ->assertJsonCount(1, 'data.devices');

        $this->assertDatabaseHas('audit_logs', ['action' => 'license.approve_demo', 'subject_id' => $license->id]);

        // İstemci bir sonraki kontrolde aktif lisansı görür; anahtar değişmez.
        $this->postJson('/api/v1/client/check', [
            'product_code' => 'PANOPOS', 'license_key' => $key, 'device' => ['fingerprint' => $this->fp()],
        ])->assertOk()->assertJson(['state' => 'active', 'device_limit' => 3]);
    }

    public function test_approve_requires_create_permission_and_only_works_on_demo(): void
    {
        $key = $this->demo()->json('license_key');
        $license = License::where('license_key', $key)->first();
        $viewer = User::factory()->create();
        $viewer->permissions()->create(['permission' => 'license.view']);
        $body = ['customer' => ['name' => 'Ada Market'], 'device_limit' => 1, 'starts_at' => now()->toDateString(), 'expires_at' => now()->addMonth()->toDateString()];

        $this->actingAs($viewer)->postJson("/api/v1/licenses/{$license->id}/approve", $body)->assertForbidden();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->postJson("/api/v1/licenses/{$license->id}/approve", $body)->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/licenses/{$license->id}/approve", $body)->assertUnprocessable();
    }

    public function test_user_sees_demo_requests_and_dashboard_counts_them(): void
    {
        $staff = $this->staff();
        $this->demo(['installer_username' => 'i.simsek'])->assertCreated();

        $this->actingAs($staff)->getJson('/api/v1/licenses?scope=demo')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.installer.username', 'i.simsek');
        $this->actingAs($staff)->getJson('/api/v1/dashboard')
            ->assertOk()->assertJsonPath('stats.demo_pending', 1)->assertJsonCount(1, 'recent');
    }

    public function test_demo_can_be_rejected_with_revoke(): void
    {
        $key = $this->demo()->json('license_key');
        $license = License::where('license_key', $key)->first();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson("/api/v1/licenses/{$license->id}/revoke", ['reason' => 'Sahte kayıt'])
            ->assertOk()->assertJsonPath('data.state', 'revoked');

        $this->postJson('/api/v1/client/check', [
            'product_code' => 'PANOPOS', 'license_key' => $key, 'device' => ['fingerprint' => $this->fp()],
        ])->assertOk()->assertJson(['state' => 'revoked']);
    }
}
