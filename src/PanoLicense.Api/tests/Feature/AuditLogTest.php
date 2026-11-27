<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\License;
use App\Models\Product;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_logs_with_user_and_license_key(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Yönetici']);
        $bayi = User::factory()->create(['name' => 'Bayi Ali']);
        $customer = Customer::create(['name' => 'Ada Market', 'created_by' => $bayi->id]);
        $license = License::create([
            'license_key' => 'PAN-AAAA-BBBB-CCCC', 'customer_id' => $customer->id,
            'product_id' => Product::first()->id, 'package_id' => \App\Models\Package::where('code', 'PRO')->value('id'), 'device_limit' => 1,
            'starts_at' => '2026-01-01', 'expires_at' => '2027-01-01', 'status' => 'active', 'created_by' => $bayi->id,
        ]);
        Audit::log('license.create', $license, ['customer' => 'Ada Market'], userId: $bayi->id);
        Audit::log('auth.login', $admin, userId: $admin->id);

        $this->actingAs($admin)->getJson('/api/v1/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.0.action', 'auth.login')
            ->assertJsonPath('data.1.action', 'license.create')
            ->assertJsonPath('data.1.user.name', 'Bayi Ali')
            ->assertJsonPath('data.1.subject.label', 'PAN-AAAA-BBBB-CCCC');

        $this->actingAs($admin)->getJson('/api/v1/audit-logs?group=license')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson("/api/v1/audit-logs?user_id={$bayi->id}")
            ->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson("/api/v1/audit-logs?license_id={$license->id}")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_user_without_audit_permission_cannot_see_logs(): void
    {
        $user = User::factory()->create();
        $user->permissions()->create(['permission' => 'license.view']);

        $this->actingAs($user)->getJson('/api/v1/audit-logs')->assertForbidden();
    }

    public function test_user_with_audit_permission_can_see_logs(): void
    {
        $user = User::factory()->create();
        $user->permissions()->create(['permission' => 'audit.view']);

        $this->actingAs($user)->getJson('/api/v1/audit-logs')->assertOk();
    }

    public function test_failed_login_is_logged_without_password(): void
    {
        $this->withHeader('Referer', 'http://localhost:8000')
            ->postJson('/api/v1/auth/login', ['username' => 'saldirgan', 'password' => 'GizliDeneme123'])
            ->assertUnprocessable();

        $admin = User::factory()->admin()->create();
        $log = $this->actingAs($admin)->getJson('/api/v1/audit-logs?group=auth')->json('data.0');

        $this->assertSame('auth.login_failed', $log['action']);
        $this->assertSame('saldirgan', $log['data']['username']);
        $this->assertStringNotContainsString('GizliDeneme123', json_encode($log));
    }
}
