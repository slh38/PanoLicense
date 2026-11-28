<?php

namespace Tests;

use App\Models\Product;
use App\Support\LicenseSigner;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        if (! $app->environment('testing') ||
            config('database.default') !== 'mysql' ||
            config('database.connections.mysql.host') !== '127.0.0.1' ||
            config('database.connections.mysql.database') !== 'panolicense_test' ||
            config('database.connections.mysql.url')) {
            throw new \RuntimeException('Testler yalnız yerel panolicense_test MySQL veritabanında çalışabilir.');
        }

        return $app;
    }

    /** Test çalıştırması boyunca tek geçici imza anahtarı (yalnız test; gerçek anahtar değildir). */
    private static ?string $testKeyPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'panolicense.signing.key_path' => self::test_signing_key_path(),
            'panolicense.signing.key_id' => 'test',
            // Yalnız izole test uygulaması; gerçek env ve varsayılan kapalı bayraklar değişmez.
            'panolicense.perpetual_enabled' => true,
            'panolicense.perpetual_production_approved' => false,
        ]);
    }

    private array $deviceKeys = [];

    protected function deviceTestKey(string $fingerprint): array
    {
        return $this->deviceKeys[$fingerprint] ??= LicenseSigner::generateKeyPair();
    }

    protected function proofBody(string $operation, array $body, ?array $keyPair = null): array
    {
        [$private, $public] = $keyPair ?? $this->deviceTestKey($body['device']['fingerprint']);
        $body['device']['public_key'] = $public;
        $challenge = $this->postJson('/api/v1/client/challenge', [
            'operation' => $operation,
            'product_code' => $body['product_code'],
            'license_key' => $body['license_key'] ?? null,
            'device' => $body['device'],
        ])->assertOk()->json();
        openssl_sign($challenge['message'], $signature, $private, OPENSSL_ALGO_SHA256);
        $body['proof'] = ['challenge_id' => $challenge['challenge_id'], 'signature' => base64_encode($signature)];

        return $body;
    }

    protected function clientPost(string $uri, array $body): TestResponse
    {
        // Doğrulama hatası testleri gerçek uçta kalsın; geçerli istekler challenge akışını kullanır.
        if (! preg_match('/^[a-f0-9]{64}$/', $body['device']['fingerprint']) ||
            ! Product::where('code', $body['product_code'])->exists()) {
            $body['device']['public_key'] = $this->deviceTestKey('invalid')[1];
            $body['proof'] = ['challenge_id' => str_repeat('0', 64), 'signature' => 'AA=='];

            return $this->postJson($uri, $body);
        }
        if (! is_readable(config('panolicense.signing.key_path'))) {
            $body['device']['public_key'] = $this->deviceTestKey('missing')[1];
            $body['proof'] = ['challenge_id' => str_repeat('0', 64), 'signature' => 'AA=='];

            return $this->postJson($uri, $body);
        }

        return $this->postJson($uri, $this->proofBody(basename($uri), $body));
    }

    protected static function test_signing_key_path(): string
    {
        if (self::$testKeyPath === null) {
            [$private] = LicenseSigner::generateKeyPair();
            self::$testKeyPath = tempnam(sys_get_temp_dir(), 'pl-test-key-');
            file_put_contents(self::$testKeyPath, $private);
            register_shutdown_function(fn () => @unlink(self::$testKeyPath));
        }

        return self::$testKeyPath;
    }
}
