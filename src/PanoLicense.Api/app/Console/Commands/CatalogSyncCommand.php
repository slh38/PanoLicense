<?php

namespace App\Console\Commands;

use App\Support\CatalogSync;
use Illuminate\Console\Command;

/**
 * Başlangıç ürün kataloğunu (config/catalog.php) güvenle uygular. Tekrar çalıştırılabilir; mevcut kayıtları ezmez,
 * hiçbir şey silmez. Ayrıntı: App\Support\CatalogSync, docs/DEPLOYMENT.md.
 *
 *   php artisan panolicense:catalog-sync --dry-run
 *   php artisan panolicense:catalog-sync
 */
class CatalogSyncCommand extends Command
{
    protected $signature = 'panolicense:catalog-sync
        {--dry-run : Ne ekleneceğini göster, yazma}
        {--attach-missing : Mevcut paketlere eksik varsayılan modül eşleşmelerini de ekle (kaldırılanları geri getirir; silmez)}';

    protected $description = 'Ürün/paket/modül başlangıç kataloğunu eksik kayıtları ekleyerek uygular';

    public function handle(): int
    {
        $sync = new CatalogSync(config('catalog'), (bool) $this->option('attach-missing'));
        $counts = $sync->run((bool) $this->option('dry-run'));

        foreach ($sync->log as $line) {
            $this->line('  '.$line);
        }
        $prefix = $this->option('dry-run') ? '[KURU ÇALIŞTIRMA, yazılmadı] ' : '';
        $this->info($prefix."Ürün +{$counts['products']}, paket +{$counts['packages']}, modül +{$counts['modules']}, eşleşme +{$counts['links']}.");

        return self::SUCCESS;
    }
}
