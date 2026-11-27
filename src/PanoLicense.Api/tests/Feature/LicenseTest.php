<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\Customer;
use App\Models\License;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LicenseTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->product = Product::where('code', 'PANOPOS')->firstOrFail();
    }

    private function standardUser(): User
    {
        $user = User::factory()->create(['name' => 'Bayi Ali']);
        foreach (Permission::userDefaults() as $p) {
            $user->permissions()->create(['permission' => $p->value]);
        }

        return $user;
    }

    private function licenseFor(User $creator, string $customer, string $expires, string $status = 'active'): License
    {
        $c = Customer::create(['name' => $customer, 'created_by' => $creator->id]);

        return License::create([
            'license_key' => License::generateKey('PAN'),
            'customer_id' => $c->id,
            'product_id' => $this->product->id,
            'device_limit' => 2,
            'starts_at' => '2026-01-01',
            'expires_at' => $expires,
            'status' => $status,
            'created_by' => $creator->id,
        ]);
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'customer' => ['name' => 'Ada Market', 'city' => 'Kayseri'],
            'product_id' => $this->product->id,
            'package_id' => \App\Models\Package::where('code', 'PRO')->value('id'),
            'device_limit' => 3,
            'starts_at' => '2026-11-22',
            'expires_at' => '2027-11-22',
        ], $override);
    }

    public function test_user_can_create_license_and_creator_is_recorded(): void
    {
        $user = $this->standardUser();

        $response = $this->actingAs($user)->postJson('/api/v1/licenses', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.created_by.name', 'Bayi Ali')
            ->assertJsonPath('data.customer.name', 'Ada Market')
            ->assertJsonPath('data.device_limit', 3);

        $this->assertMatchesRegularExpression('/^PAN-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}$/', $response->json('data.license_key'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'license.create', 'user_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.create', 'user_id' => $user->id]);
    }

    public function test_admin_sees_who_issued_the_license_in_detail(): void
    {
        $user = $this->standardUser();
        $license = $this->licenseFor($user, 'Lezzet Restoran', '2027-05-01');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->getJson("/api/v1/licenses/{$license->id}")
            ->assertOk()
            ->assertJsonPath('data.created_by.name', 'Bayi Ali');
    }

    public function test_second_active_license_for_same_customer_and_product_is_rejected(): void
    {
        $user = $this->standardUser();
        $existing = $this->licenseFor($user, 'Ada Market', '2027-01-01');

        $this->actingAs($user)->postJson('/api/v1/licenses', $this->payload([
            'customer' => null, 'customer_id' => $existing->customer_id,
        ]))->assertUnprocessable()->assertJsonValidationErrors('customer_id');

        $this->assertSame(1, License::count());
    }

    public function test_database_enforces_single_active_license_even_without_app_check(): void
    {
        $user = $this->standardUser();
        $first = $this->licenseFor($user, 'Ada Market', '2027-01-01');

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        License::create([
            ...$first->only(['customer_id', 'product_id', 'device_limit', 'starts_at', 'expires_at', 'created_by']),
            'license_key' => License::generateKey('PAN'),
            'status' => 'active',
        ]);
    }

    public function test_revoked_license_does_not_block_a_new_one(): void
    {
        $user = $this->standardUser();
        $old = $this->licenseFor($user, 'Ada Market', '2027-01-01', 'revoked');

        $this->actingAs($user)->postJson('/api/v1/licenses', $this->payload([
            'customer' => null, 'customer_id' => $old->customer_id,
        ]))->assertCreated();
    }

    public function test_user_cannot_list_all_licenses_without_search_or_expiring_scope(): void
    {
        $this->actingAs($this->standardUser())->getJson('/api/v1/licenses')->assertForbidden();
    }

    public function test_user_sees_expiring_licenses_within_30_days(): void
    {
        Carbon::setTestNow('2026-11-22');
        $user = $this->standardUser();
        $this->licenseFor($user, 'Yakında Biter', '2026-12-10');
        $this->licenseFor($user, 'Yeni Bitti', '2026-11-15');
        $this->licenseFor($user, 'Uzun Süreli', '2027-11-22');
        $this->licenseFor($user, 'İptal Edilmiş', '2026-12-01', 'revoked');

        $names = collect($this->actingAs($user)->getJson('/api/v1/licenses?scope=expiring')
            ->assertOk()->json('data'))->pluck('customer.name')->all();

        $this->assertSame(['Yeni Bitti', 'Yakında Biter'], $names);
    }

    public function test_user_can_search_all_licenses_by_customer_or_key(): void
    {
        $user = $this->standardUser();
        $other = User::factory()->admin()->create();
        $license = $this->licenseFor($other, 'Merkez Toptan', '2027-06-01');
        $this->licenseFor($other, 'Başka Firma', '2027-06-01');

        $this->actingAs($user)->getJson('/api/v1/licenses?q=merkez')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.customer.name', 'Merkez Toptan');

        $this->actingAs($user)->getJson('/api/v1/licenses?q='.substr($license->license_key, 4, 4))
            ->assertOk()->assertJsonPath('data.0.license_key', $license->license_key);
    }

    public function test_search_treats_like_wildcards_literally(): void
    {
        $user = $this->standardUser();
        $this->licenseFor($user, 'Ada Market', '2027-06-01');

        $this->actingAs($user)->getJson('/api/v1/licenses?q=%25%25')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_user_cannot_extend_or_revoke_by_default(): void
    {
        $user = $this->standardUser();
        $license = $this->licenseFor($user, 'Ada Market', '2027-01-01');

        $this->actingAs($user)->postJson("/api/v1/licenses/{$license->id}/extend", ['expires_at' => '2028-01-01'])->assertForbidden();
        $this->actingAs($user)->postJson("/api/v1/licenses/{$license->id}/revoke", ['reason' => 'test'])->assertForbidden();
    }

    public function test_admin_can_extend_and_revoke_and_history_is_kept(): void
    {
        $admin = User::factory()->admin()->create();
        $license = $this->licenseFor($admin, 'Ada Market', '2027-01-01');

        $this->actingAs($admin)->postJson("/api/v1/licenses/{$license->id}/extend", ['expires_at' => '2026-12-01'])
            ->assertUnprocessable();
        $this->actingAs($admin)->postJson("/api/v1/licenses/{$license->id}/extend", ['expires_at' => '2028-01-01'])
            ->assertOk()->assertJsonPath('data.expires_at', '2028-01-01');

        $this->actingAs($admin)->postJson("/api/v1/licenses/{$license->id}/revoke", ['reason' => 'Ödeme yapılmadı'])
            ->assertOk()->assertJsonPath('data.state', 'revoked')->assertJsonPath('data.revoke_reason', 'Ödeme yapılmadı');

        $this->assertDatabaseHas('licenses', ['id' => $license->id]); // fiziksel silme yok
        $this->assertDatabaseHas('audit_logs', ['action' => 'license.extend', 'subject_id' => $license->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'license.revoke', 'subject_id' => $license->id]);

        $this->actingAs($admin)->postJson("/api/v1/licenses/{$license->id}/extend", ['expires_at' => '2029-01-01'])
            ->assertUnprocessable();
    }

    public function test_expiry_must_be_after_start(): void
    {
        $this->actingAs($this->standardUser())
            ->postJson('/api/v1/licenses', $this->payload(['expires_at' => '2026-11-01']))
            ->assertUnprocessable()->assertJsonValidationErrors('expires_at');
    }

    public function test_user_without_create_permission_cannot_create(): void
    {
        $user = User::factory()->create();
        $user->permissions()->create(['permission' => 'license.view']);

        $this->actingAs($user)->postJson('/api/v1/licenses', $this->payload())->assertForbidden();
    }

    public function test_dashboard_scope_differs_for_user_and_admin(): void
    {
        $user = $this->standardUser();
        $admin = User::factory()->admin()->create();

        $this->actingAs($user)->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('scope', 'own')->assertJsonMissingPath('stats.customers');
        $this->actingAs($admin)->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('scope', 'all')->assertJsonStructure(['stats' => ['active', 'customers', 'expiring', 'inactive']]);
    }
}
