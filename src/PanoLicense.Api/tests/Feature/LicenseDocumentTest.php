<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\LicenseSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LicenseDocumentTest extends TestCase
{
    use RefreshDatabase;

    private function fp(string $seed = 'pc-1'): string
    {
        return hash('sha256', $seed);
    }

    private function demo(): \Illuminate\Testing\TestResponse
    {
        return $this->clientPost('/api/v1/client/demo', [
            'product_code' => 'PANOPOS',
            'device' => ['fingerprint' => $this->fp(), 'name' => 'KASA-1'],
            'customer' => ['name' => 'Yıldız Büfe'],
        ]);
    }

    private function decode(string $b64url): string
    {
        return base64_decode(strtr($b64url, '-_', '+/'));
    }

    private function publicKey(): string
    {
        return LicenseSigner::fromConfig()->publicKeyPem();
    }

    public function test_response_contains_document_signed_with_p256_over_payload_bytes(): void
    {
        Carbon::setTestNow('2026-11-27 10:00:00');

        $doc = $this->demo()->assertCreated()->json('license_document');

        $this->assertSame(['v' => 1, 'kid' => 'test', 'alg' => 'ES256'], array_intersect_key($doc, array_flip(['v', 'kid', 'alg'])));
        $payloadBytes = $this->decode($doc['payload']);
        $this->assertSame(1, openssl_verify($payloadBytes, $this->decode($doc['sig']), $this->publicKey(), OPENSSL_ALGO_SHA256));

        $payload = json_decode($payloadBytes, true);
        $this->assertSame('Yıldız Büfe', $payload['customer']);
        $this->assertSame('demo', $payload['status']);
        $this->assertSame($this->fp(), $payload['fingerprint']); // belge yalnız bu cihaza bağlı
        $this->assertSame('2026-11-27T10:00:00Z', $payload['issued_at']);
        // 7 gün offline (demo bitişi 11.12 olduğundan sınır 7 gün)
        $this->assertSame('2026-12-04T10:00:00Z', $payload['valid_until']);
    }

    public function test_tampered_payload_does_not_verify(): void
    {
        $doc = $this->demo()->json('license_document');
        $payload = json_decode($this->decode($doc['payload']), true);
        $payload['status'] = 'active';
        $payload['valid_until'] = '2099-01-01T00:00:00Z';
        $forged = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertSame(0, openssl_verify($forged, $this->decode($doc['sig']), $this->publicKey(), OPENSSL_ALGO_SHA256));
    }

    public function test_offline_validity_never_exceeds_license_end(): void
    {
        // Demo 14 gün: 12. günde alınan belge 7 gün değil, demo bitişine kadar geçerli olmalı.
        Carbon::setTestNow('2026-11-01 08:00:00');
        $this->demo()->assertCreated();

        Carbon::setTestNow('2026-11-13 08:00:00');
        $doc = $this->demo()->assertOk()->json('license_document');
        $payload = json_decode($this->decode($doc['payload']), true);

        $this->assertSame('2026-11-15', $payload['expires_at']);
        $this->assertSame('2026-11-16T00:00:00Z', $payload['valid_until']);
    }

    public function test_revoked_license_gets_signed_negative_document(): void
    {
        $key = $this->demo()->json('license_key');
        $admin = User::factory()->admin()->create();
        $id = \App\Models\License::where('license_key', $key)->value('id');
        $this->actingAs($admin)->postJson("/api/v1/licenses/{$id}/revoke", ['reason' => 'Ödeme yok'])->assertOk();

        $doc = $this->clientPost('/api/v1/client/check', [
            'product_code' => 'PANOPOS', 'license_key' => $key, 'device' => ['fingerprint' => $this->fp()],
        ])->assertOk()->json('license_document');

        // İptal bilgisi de imzalı gelir; istemci eski "aktif" belgesini bununla değiştirir.
        $this->assertSame('revoked', json_decode($this->decode($doc['payload']), true)['state']);
    }

    public function test_missing_key_fails_closed_without_creating_records(): void
    {
        config(['panolicense.signing.key_path' => sys_get_temp_dir().'/yok-'.uniqid().'.pem']);

        $this->demo()->assertStatus(503)->assertJson(['error' => 'signing_unavailable']);
        $this->assertDatabaseCount('licenses', 0);
    }

    public function test_signing_key_command_creates_key_once_and_never_overwrites(): void
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pl-cmd-'.uniqid().DIRECTORY_SEPARATOR.'signing.pem';
        config(['panolicense.signing.key_path' => $path]);

        $this->artisan('panolicense:signing-key')->expectsOutputToContain('Yeni anahtar oluşturuldu')->assertSuccessful();
        $first = file_get_contents($path);

        $this->artisan('panolicense:signing-key')->expectsOutputToContain('üzerine yazılmadı')->assertSuccessful();
        $this->assertSame($first, file_get_contents($path));

        @unlink($path);
        @rmdir(dirname($path));
    }
}
