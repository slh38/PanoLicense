<?php

namespace Tests\Feature;

use App\Enums\LicenseStatus;
use App\Http\Controllers\Api\V1\ClientController;
use App\Models\AuditLog;
use App\Models\License;
use App\Models\Module;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Support\LicenseSigner;
use App\Support\SigningUnavailableException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PerpetualLicenseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-28 10:00:00');
        config(['panolicense.perpetual_enabled' => true]);
        $this->actingAs(User::factory()->admin()->create());
    }

    private function body(string $type = 'term', string $package = 'PRO'): array
    {
        return [
            'customer' => ['name' => 'İzole Süre Testi'],
            'product_id' => Product::where('code', 'PANOPOS')->value('id'),
            'package_id' => Package::where('code', $package)->value('id'),
            'device_limit' => 2, 'starts_at' => '2026-11-28',
            'license_type' => $type, 'expires_at' => $type === 'perpetual' ? null : '2027-11-28',
        ];
    }

    private function createLicense(string $type = 'term', string $package = 'PRO'): License
    {
        $id = $this->postJson('/api/v1/licenses', $this->body($type, $package))
            ->assertCreated()->assertJsonPath('data.license_type', $type)->json('data.id');

        return License::findOrFail($id);
    }

    private function deviceBody(License $license): array
    {
        return ['product_code' => 'PANOPOS', 'license_key' => $license->license_key,
            'device' => ['fingerprint' => hash('sha256', 'perpetual-pc')]];
    }

    private function decode(array $doc): array
    {
        $bytes = base64_decode(strtr($doc['payload'], '-_', '+/'));
        $this->assertSame(1, openssl_verify($bytes, base64_decode(strtr($doc['sig'], '-_', '+/')),
            LicenseSigner::fromConfig()->publicKeyPem(), OPENSSL_ALGO_SHA256));
        $payload = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($doc['v'], $payload['v']);

        return $payload;
    }

    public function test_all_four_packages_support_both_types_and_dashboard_counts_them(): void
    {
        foreach (['LITE', 'PANO', 'RESTAURANT', 'PRO'] as $package) {
            foreach (['term', 'perpetual'] as $type) {
                $license = $this->createLicense($type, $package);
                $this->assertTrue($license->isUsable());
                $this->assertSame($type === 'perpetual', $license->expires_at === null);
            }
        }
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('stats.active', 8);
        $this->getJson('/api/v1/licenses?scope=expiring')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_users_cannot_create_convert_or_approve_perpetual_even_with_injected_permission(): void
    {
        $license = $this->createLicense();
        $demoKey = $this->clientPost('/api/v1/client/demo', [
            'product_code' => 'PANOPOS', 'device' => ['fingerprint' => hash('sha256', 'demo-pc')],
            'customer' => ['name' => 'İzole Demo'],
        ])->assertCreated()->json('license_key');
        $demo = License::where('license_key', $demoKey)->firstOrFail();
        $user = User::factory()->create();
        foreach (['license.create'] as $permission) {
            DB::table('user_permissions')->insert(['user_id' => $user->id, 'permission' => $permission]);
        }
        $this->actingAs($user);
        $this->postJson('/api/v1/licenses', $this->body('perpetual'))->assertForbidden();
        DB::table('user_permissions')->insert(['user_id' => $user->id, 'permission' => 'license.perpetual']);
        $this->postJson("/api/v1/licenses/{$license->id}/convert-perpetual", ['license_type' => 'perpetual'])->assertForbidden();
        DB::table('user_permissions')->where('user_id', $user->id)->where('permission', 'license.perpetual')->delete();
        $this->postJson("/api/v1/licenses/{$demo->id}/approve", $this->body('perpetual'))->assertForbidden();
        $this->postJson('/api/v1/licenses', $this->body())->assertCreated();
    }

    public function test_conversion_preserves_identity_devices_modules_and_audits_once(): void
    {
        $license = $this->createLicense();
        $this->clientPost('/api/v1/client/activate', $this->deviceBody($license))->assertCreated();
        $module = Module::create(['product_id' => $license->product_id, 'code' => 'bank', 'name' => 'Test Bank']);
        $license->addonModules()->attach($module->id, ['granted_by' => auth()->id(), 'created_at' => now()]);
        $devices = $license->devices()->get()->toArray();
        $old = $license->only(['license_key', 'device_limit', 'package_id', 'offline_grace_days']);
        $modules = $license->effectiveModuleCodes();
        $this->postJson("/api/v1/licenses/{$license->id}/convert-perpetual", ['license_type' => 'perpetual'])
            ->assertOk()->assertJsonPath('data.expires_at', null);
        $fresh = $license->fresh();
        $this->assertEquals($old, $fresh->only(array_keys($old)));
        $this->assertEquals($devices, $fresh->devices()->get()->toArray());
        $this->assertEquals($modules, $fresh->effectiveModuleCodes());
        $this->assertDatabaseHas('audit_logs', ['action' => 'license.convert_perpetual', 'subject_id' => $license->id, 'user_id' => auth()->id()]);
        $this->postJson("/api/v1/licenses/{$license->id}/convert-perpetual", ['license_type' => 'perpetual'])->assertUnprocessable();
        $this->assertSame(1, AuditLog::where('action', 'license.convert_perpetual')->count());
        $this->postJson("/api/v1/licenses/{$license->id}/extend", ['expires_at' => '2028-11-28'])->assertUnprocessable();
        $this->assertTrue($license->fresh()->isPerpetual());
    }

    public function test_revoked_license_cannot_be_converted_and_revocation_stays_signed(): void
    {
        $license = $this->createLicense('perpetual');
        $this->clientPost('/api/v1/client/activate', $this->deviceBody($license))->assertCreated();
        $this->postJson("/api/v1/licenses/{$license->id}/revoke", ['reason' => 'İzole test iptali'])->assertOk();
        $this->postJson("/api/v1/licenses/{$license->id}/convert-perpetual", ['license_type' => 'perpetual'])->assertUnprocessable();
        $doc = $this->clientPost('/api/v1/client/check', $this->deviceBody($license))->assertOk()->json('license_document');
        $this->assertSame('revoked', $this->decode($doc)['status']);
        $this->assertNotNull($license->fresh()->revoked_at);
        $term = $this->createLicense();
        $this->postJson("/api/v1/licenses/{$term->id}/revoke", ['reason' => 'İzole test iptali'])->assertOk();
        $this->postJson("/api/v1/licenses/{$term->id}/convert-perpetual", ['license_type' => 'perpetual'])->assertUnprocessable();
    }

    public function test_perpetual_offline_is_finite_for_both_policies_and_term_uses_v2(): void
    {
        foreach ([7, 365] as $days) {
            $license = $this->createLicense('perpetual');
            $license->update(['offline_grace_days' => $days]);
            $doc = $this->clientPost('/api/v1/client/activate', $this->deviceBody($license))->assertCreated()->json('license_document');
            $payload = $this->decode($doc);
            $this->assertSame(2, $doc['v']);
            $this->assertSame('perpetual', $payload['license_type']);
            $this->assertNull($payload['expires_at']);
            $this->assertTrue(Carbon::parse($payload['valid_until'])->equalTo(Carbon::parse($payload['issued_at'])->addDays($days)));
        }
        $term = $this->createLicense();
        $doc = $this->clientPost('/api/v1/client/activate', $this->deviceBody($term))->assertCreated()->json('license_document');
        $payload = $this->decode($doc);
        $this->assertSame(2, $doc['v']);
        $this->assertSame('term', $payload['license_type']);
        $this->assertSame('2027-11-28', $payload['expires_at']);
    }

    public function test_feature_is_off_by_default_and_production_requires_separate_approval(): void
    {
        $license = $this->createLicense();
        $defaults = require base_path('config/panolicense.php');
        $this->assertFalse($defaults['perpetual_enabled']);
        config(['panolicense.perpetual_enabled' => false]);
        $this->postJson('/api/v1/licenses', $this->body('perpetual'))->assertUnprocessable();
        $this->postJson("/api/v1/licenses/{$license->id}/convert-perpetual", ['license_type' => 'perpetual'])->assertUnprocessable();
        $this->assertSame('term', $license->fresh()->license_type);
        config(['panolicense.perpetual_enabled' => true]);
        $this->app->instance('env', 'production');
        $this->postJson('/api/v1/licenses', $this->body('perpetual'))->assertUnprocessable();
        config(['panolicense.perpetual_production_approved' => true]);
        $this->postJson('/api/v1/licenses', $this->body('perpetual'))->assertCreated();
    }

    public function test_invalid_type_date_combinations_are_not_inferred_as_perpetual(): void
    {
        foreach ([
            ['license_type' => 'term', 'expires_at' => null],
            ['license_type' => 'unknown', 'expires_at' => null],
            ['license_type' => null, 'expires_at' => null],
            ['license_type' => 'perpetual', 'expires_at' => '2027-11-28'],
            ['license_type' => 'term', 'expires_at' => '2027-02-30'],
        ] as $bad) {
            $this->postJson('/api/v1/licenses', array_replace($this->body(), $bad))->assertUnprocessable();
        }
        $body = $this->body();
        unset($body['license_type']);
        $this->postJson('/api/v1/licenses', $body)->assertCreated()->assertJsonPath('data.license_type', 'term');
        $license = $this->createLicense();
        $license->update(['expires_at' => null]);
        $this->assertFalse($license->isUsable());
        $this->clientPost('/api/v1/client/activate', $this->deviceBody($license))->assertForbidden();
    }

    public function test_demo_remains_term_pro_single_device_seven_days_and_admin_can_approve_perpetual(): void
    {
        $body = ['product_code' => 'PANOPOS', 'device' => ['fingerprint' => hash('sha256', 'demo')],
            'customer' => ['name' => 'İzole Demo']];
        $res = $this->clientPost('/api/v1/client/demo', $body)->assertCreated();
        $license = License::where('license_key', $res->json('license_key'))->firstOrFail();
        $this->assertSame('term', $license->license_type);
        $this->assertSame('PRO', $license->package->code);
        $this->assertSame(1, $license->device_limit);
        $this->assertSame(7, $license->offlineGraceDays());
        $this->assertSame('2026-12-12', $license->expires_at->toDateString());
        config(['panolicense.perpetual_enabled' => false]);
        $this->postJson("/api/v1/licenses/{$license->id}/approve", $this->body('perpetual'))->assertUnprocessable();
        $this->assertSame(LicenseStatus::Demo, $license->fresh()->status);
        config(['panolicense.perpetual_enabled' => true]);
        $this->postJson("/api/v1/licenses/{$license->id}/approve", $this->body('perpetual'))
            ->assertOk()->assertJsonPath('data.license_key', $license->license_key)->assertJsonPath('data.license_type', 'perpetual');
    }

    public function test_signer_rejects_invalid_v2_and_all_v1_payloads(): void
    {
        $signer = LicenseSigner::fromConfig();
        foreach ([
            ['v' => 1, 'expires_at' => null],
            ['v' => 1],
            ['v' => 1, 'expires_at' => '2027-11-28'],
            ['v' => 1, 'license_type' => 'term', 'expires_at' => '2027-11-28'],
            ['v' => 2, 'expires_at' => null],
            ['v' => 2, 'license_type' => 'unknown', 'expires_at' => null],
            ['v' => 2, 'license_type' => 'perpetual'],
            ['v' => 2, 'license_type' => 'perpetual', 'expires_at' => '2027-11-28'],
            ['v' => 2, 'license_type' => 'term', 'expires_at' => null],
            ['v' => 2, 'license_type' => 'term', 'expires_at' => '2027-02-30'],
            ['v' => 2, 'license_type' => 'perpetual', 'expires_at' => null, 'status' => 'demo'],
        ] as $payload) {
            try {
                $signer->sign($payload);
                $this->fail('Geçersiz belge imzalandı.');
            } catch (SigningUnavailableException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(2, $signer->sign(['v' => 2, 'license_type' => 'term', 'expires_at' => '2027-11-28'])['v']);
    }

    public function test_stale_inflight_snapshot_cannot_issue_term_after_conversion_and_order_increases(): void
    {
        $license = $this->createLicense();
        $oldDoc = $this->clientPost('/api/v1/client/activate', $this->deviceBody($license))->assertCreated()->json('license_document');
        $old = $this->decode($oldDoc);
        Carbon::setTestNow(now()->addMicroseconds(2));
        $stale = $license->fresh();
        $device = $stale->devices()->firstOrFail();
        $this->postJson("/api/v1/licenses/{$license->id}/convert-perpetual", ['license_type' => 'perpetual'])->assertOk();
        $controller = app(ClientController::class);
        $signerProperty = new \ReflectionProperty($controller, 'signer');
        $signerProperty->setValue($controller, LicenseSigner::fromConfig());
        $method = new \ReflectionMethod($controller, 'payload');
        $response = $method->invoke($controller, $stale, $device);
        $new = $this->decode($response['license_document']);
        $this->assertSame('perpetual', $response['license_type']);
        $this->assertSame(2, $new['v']);
        $this->assertTrue(Carbon::parse($new['issued_at'])->gt(Carbon::parse($old['issued_at'])));
        $check = $this->proofBody('check', $this->deviceBody($license));
        config(['panolicense.perpetual_enabled' => false]);
        $this->postJson('/api/v1/client/check', $check)
            ->assertStatus(503)->assertJsonPath('error', 'signing_unavailable');
    }

    public function test_mysql_migration_preserves_term_rows_and_refuses_lossy_rollback(): void
    {
        $license = $this->createLicense();
        $this->assertSame('term', DB::table('licenses')->where('id', $license->id)->value('license_type'));
        $column = DB::selectOne("SELECT IS_NULLABLE AS nullable FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'licenses' AND COLUMN_NAME = 'expires_at'");
        $this->assertSame('YES', $column->nullable);
        $this->createLicense('perpetual');
        $migration = require database_path('migrations/2026_11_28_160000_add_perpetual_licenses.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
