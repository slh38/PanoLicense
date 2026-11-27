<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\License;
use App\Models\Product;
use App\Models\User;
use App\Support\LicenseSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OfflinePolicyTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'customer' => ['name' => 'Politika Test Firması'],
            'product_id' => Product::first()->id, 'package_id' => \App\Models\Package::where('code', 'PRO')->value('id'), 'device_limit' => 2,
            'starts_at' => '2026-11-27', 'expires_at' => '2030-11-27',
        ], $overrides);
    }

    private function createLicense(int $days = 7): License
    {
        $response = $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/v1/licenses', $this->payload(['offline_grace_days' => $days]))
            ->assertCreated()->assertJsonPath('data.offline_grace_days', $days);
        return License::findOrFail($response->json('data.id'));
    }

    private function document(array $response): array
    {
        $envelope = $response['license_document'];
        $bytes = base64_decode(strtr($envelope['payload'], '-_', '+/'));
        $this->assertSame(1, openssl_verify($bytes,
            base64_decode(strtr($envelope['sig'], '-_', '+/')),
            LicenseSigner::fromConfig()->publicKeyPem(), OPENSSL_ALGO_SHA256));
        return json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
    }

    private function activateBody(License $license, string $device = 'pc-1'): array
    {
        return ['product_code' => 'PANOPOS', 'license_key' => $license->license_key,
            'device' => ['fingerprint' => hash('sha256', $device)]];
    }

    public function test_default_is_seven_for_user_admin_and_database(): void
    {
        foreach ([User::factory()->create(), User::factory()->admin()->create()] as $user) {
            $user->permissions()->create(['permission' => 'license.create']);
            $id = $this->actingAs($user)->postJson('/api/v1/licenses', $this->payload())
                ->assertCreated()->assertJsonPath('data.offline_grace_days', 7)->json('data.id');
            $license = License::findOrFail($id);
            $this->assertSame(7, $license->offline_grace_days);
        }
        $row = License::first()->getAttributes();
        unset($row['id'], $row['offline_grace_days'], $row['active_key']);
        $row['license_key'] = License::generateKey('PAN');
        $row['status'] = 'revoked'; // müşteri/ürün aktif tekilliğine takılmasın
        $id = \DB::table('licenses')->insertGetId($row);
        $this->assertSame(7, License::findOrFail($id)->offline_grace_days);
    }

    public function test_only_admin_can_grant_or_change_special_policy_even_if_permission_is_injected(): void
    {
        $license = $this->createLicense();
        $user = User::factory()->create();
        foreach (['license.create', 'license.extend'] as $permission) {
            $user->permissions()->create(['permission' => $permission]);
        }
        $this->actingAs($user)->postJson('/api/v1/licenses', $this->payload(['offline_grace_days' => 365]))
            ->assertForbidden();
        // Admin-only adını DB'ye elle yazmak Gate'in rol kısıtını aşmamalı.
        \DB::table('user_permissions')->insert(['user_id' => $user->id, 'permission' => 'license.offline_policy']);
        foreach ([365, 7] as $days) {
            $this->postJson("/api/v1/licenses/{$license->id}/offline-policy", ['offline_grace_days' => $days])
                ->assertForbidden();
        }
        $this->assertSame(1, License::count());
        $this->assertSame(7, $license->fresh()->offline_grace_days);
        $this->assertSame(0, AuditLog::where('action', 'license.offline_policy_change')->count());
    }

    public function test_admin_changes_are_transactionally_logged_and_same_value_is_a_noop(): void
    {
        $license = $this->createLicense(365);
        $this->assertDatabaseHas('audit_logs', ['action' => 'license.offline_policy_change', 'subject_id' => $license->id]);
        $this->postJson("/api/v1/licenses/{$license->id}/offline-policy", ['offline_grace_days' => 7])
            ->assertOk()->assertJsonPath('data.offline_grace_days', 7);
        $logs = AuditLog::where('action', 'license.offline_policy_change')->orderBy('id')->get();
        $this->assertEquals(['from' => 7, 'to' => 365], $logs[0]->data);
        $this->assertEquals(['from' => 365, 'to' => 7], $logs[1]->data);
        $this->assertSame(auth()->id(), $logs[1]->user_id);
        $this->postJson("/api/v1/licenses/{$license->id}/offline-policy", ['offline_grace_days' => 7])->assertOk();
        $this->assertSame(2, AuditLog::where('action', 'license.offline_policy_change')->count());
    }

    public function test_invalid_values_and_unauthenticated_or_inactive_users_are_rejected(): void
    {
        $license = $this->createLicense();
        foreach ([0, 8, 364, 366, null] as $days) {
            $this->postJson("/api/v1/licenses/{$license->id}/offline-policy", ['offline_grace_days' => $days])
                ->assertUnprocessable()->assertJsonValidationErrors('offline_grace_days');
        }
        $this->actingAs(User::factory()->admin()->inactive()->create())
            ->postJson("/api/v1/licenses/{$license->id}/offline-policy", ['offline_grace_days' => 365])->assertForbidden();
        auth()->forgetGuards();
        $this->postJson("/api/v1/licenses/{$license->id}/offline-policy", ['offline_grace_days' => 365])->assertUnauthorized();
    }

    public function test_signed_policy_is_per_license_for_all_devices_and_renews_only_online(): void
    {
        Carbon::setTestNow('2026-11-27 10:00:00');
        $license = $this->createLicense(365);
        foreach (['pc-1', 'pc-2'] as $device) {
            $response = $this->clientPost('/api/v1/client/activate', $this->activateBody($license, $device))
                ->assertCreated()->assertJsonPath('offline_grace_days', 365)->json();
            $document = $this->document($response);
            $this->assertSame(365, $document['offline_grace_days']);
            $this->assertSame('2027-11-27T10:00:00Z', $document['valid_until']);
            $this->assertNotEmpty($document['device_key_hash']);
        }
        Carbon::setTestNow('2026-12-01 10:00:00');
        $renewed = $this->document($this->clientPost('/api/v1/client/check', $this->activateBody($license))->assertOk()->json());
        $this->assertSame('2027-12-01T10:00:00Z', $renewed['valid_until']);
        $this->postJson("/api/v1/licenses/{$license->id}/offline-policy", ['offline_grace_days' => 7])->assertOk();
        $standard = $this->document($this->clientPost('/api/v1/client/check', $this->activateBody($license))->assertOk()->json());
        $this->assertSame(7, $standard['offline_grace_days']);
        $this->assertSame('2026-12-08T10:00:00Z', $standard['valid_until']);
        // Önceden verilen belgenin bitişi uzaktan değişmez.
        $this->assertSame('2027-12-01T10:00:00Z', $renewed['valid_until']);
    }

    public function test_neither_policy_exceeds_license_end_including_last_day(): void
    {
        Carbon::setTestNow('2026-11-27 10:00:00');
        foreach ([7, 365] as $days) {
            $license = $this->createLicense($days);
            $license->update(['expires_at' => '2026-11-27']);
            $document = $this->document($this->clientPost('/api/v1/client/activate', $this->activateBody($license))->assertCreated()->json());
            $this->assertSame('2026-11-28T00:00:00Z', $document['valid_until']);
        }
    }

    public function test_demo_cannot_receive_special_policy_until_admin_approves_it_as_commercial(): void
    {
        Carbon::setTestNow('2026-11-27 10:00:00');
        $body = ['product_code' => 'PANOPOS', 'device' => ['fingerprint' => hash('sha256', 'demo')],
            'customer' => ['name' => 'Test Demo']];
        $response = $this->clientPost('/api/v1/client/demo', $body)->assertCreated()->assertJsonPath('offline_grace_days', 7);
        $license = License::where('license_key', $response->json('license_key'))->firstOrFail();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->postJson("/api/v1/licenses/{$license->id}/offline-policy", ['offline_grace_days' => 365])->assertUnprocessable();
        // Savunma: DB'deki yanlış değer bile demo belgesini 365 yapamaz.
        $license->update(['offline_grace_days' => 365]);
        $check = $this->activateBody($license, 'demo');
        $this->clientPost('/api/v1/client/check', $check)->assertOk()->assertJsonPath('offline_grace_days', 7);
        $approval = ['customer' => ['name' => 'Onaylı Firma'], 'device_limit' => 1,
            'starts_at' => '2026-11-27', 'expires_at' => '2029-11-27', 'offline_grace_days' => 365];
        $user = User::factory()->create();
        $user->permissions()->create(['permission' => 'license.create']);
        $this->actingAs($user)->postJson("/api/v1/licenses/{$license->id}/approve", $approval)->assertForbidden();
        $this->actingAs($admin)->postJson("/api/v1/licenses/{$license->id}/approve", $approval)
            ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.offline_grace_days', 365);
    }

    public function test_client_and_unrelated_mutations_cannot_override_offline_policy(): void
    {
        $license = $this->createLicense(365);
        $body = $this->activateBody($license);
        $request = $this->proofBody('activate', $body);
        $request['offline_grace_days'] = 365;
        $this->postJson('/api/v1/client/activate', $request)->assertUnprocessable();
        $this->postJson("/api/v1/licenses/{$license->id}/extend", ['expires_at' => '2031-11-27', 'offline_grace_days' => 7])->assertOk();
        $this->assertSame(365, $license->fresh()->offline_grace_days);
    }
}