<?php
require __DIR__.'/../../../src/PanoLicense.Api/app/Support/SigningUnavailableException.php';
require __DIR__.'/../../../src/PanoLicense.Api/app/Support/LicenseSigner.php';

use App\Support\LicenseSigner;

$path = __DIR__.'/php-es256-documents.json';
$fixture = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
[$private, $public] = LicenseSigner::generateKeyPair();
[, $devicePublic] = LicenseSigner::generateKeyPair();
$fixture['public_key_pem'] = $public;
$fixture['device_public_key_pem'] = $devicePublic;
$fixture['device_key_hash'] = hash('sha256', base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $devicePublic)));
$sign = static function (array $payload) use ($fixture, $private): array {
    $bytes = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (! openssl_sign($bytes, $signature, $private, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Test fixture imzalanamadı.');
    }
    return ['v' => $payload['v'], 'kid' => $fixture['kid'], 'alg' => 'ES256',
        'payload' => LicenseSigner::base64url($bytes), 'sig' => LicenseSigner::base64url($signature)];
};
foreach (['active', 'demo', 'revoked'] as $name) {
    $payload = json_decode(base64_decode(strtr($fixture[$name]['payload'], '-_', '+/')), true);
    $payload['v'] = 2;
    $payload['license_type'] = 'term';
    $payload['device_key_hash'] = $fixture['device_key_hash'];
    $fixture[$name] = $sign($payload);
}
$special = json_decode(base64_decode(strtr($fixture['active']['payload'], '-_', '+/')), true);
$special['offline_grace_days'] = 365;
$special['expires_at'] = '2030-11-27';
$special['valid_until'] = '2027-11-27T10:00:00Z';
$fixture['special'] = $sign($special);
$legacy = json_decode(base64_decode(strtr($fixture['revoked']['payload'], '-_', '+/')), true);
$legacy['v'] = 1;
unset($legacy['license_type']);
$fixture['legacy_revoked'] = $sign($legacy);
$legacy['status'] = 'active';
$legacy['state'] = 'active';
$fixture['legacy_active'] = $sign($legacy);
file_put_contents($path, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);