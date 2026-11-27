<?php

namespace App\Console\Commands;

use App\Support\LicenseSigner;
use Illuminate\Console\Command;

/**
 * Lisans imza anahtarını oluşturur (yoksa) ve açık anahtarı gösterir.
 * Var olan anahtarın üzerine asla yazmaz: anahtar değişirse dağıtılmış istemciler doğrulayamaz.
 *
 *   sudo -u panolicense php artisan panolicense:signing-key
 */
class SigningKey extends Command
{
    protected $signature = 'panolicense:signing-key';

    protected $description = 'ECDSA P-256 lisans imza anahtarını oluşturur (yoksa) ve açık anahtarı yazdırır';

    public function handle(): int
    {
        $path = config('panolicense.signing.key_path');
        $kid = config('panolicense.signing.key_id');

        if (! $path) {
            $this->error('LICENSE_SIGNING_KEY_PATH tanımlı değil (.env). Yol public dizini dışında olmalı.');

            return self::FAILURE;
        }

        if (is_file($path)) {
            $this->info("Anahtar zaten var, üzerine yazılmadı: {$path}");
        } else {
            [$private] = LicenseSigner::generateKeyPair();
            $dir = dirname($path);
            if (! is_dir($dir) && ! mkdir($dir, 0700, true)) {
                $this->error("Klasör oluşturulamadı: {$dir}");

                return self::FAILURE;
            }
            // Önce kısıtlı izinle oluştur, sonra yaz (anahtar hiçbir an herkese okunur olmasın).
            touch($path);
            chmod($path, 0600);
            file_put_contents($path, $private);
            $this->info("Yeni anahtar oluşturuldu: {$path} (izin 600). Sunucu dışında güvenli bir yedeğini alın.");
        }

        $signer = LicenseSigner::fromConfig();
        $this->line('');
        $this->line("Anahtar kimliği (kid): {$kid}");
        $this->line('Açık anahtar (istemciye gömülür):');
        $this->line($signer->publicKeyPem());

        return self::SUCCESS;
    }
}
