<?php

return [
    'version' => '0.4.0',

    // Tüketiciler v2 hazır olmadan açılmaz. Production için ayrı açık dağıtım onayı da gerekir.
    'perpetual_enabled' => env('LICENSE_PERPETUAL_ENABLED', false),
    'perpetual_production_approved' => env('LICENSE_PERPETUAL_PRODUCTION_APPROVED', false),

    // Onaylı karar: PanoPOS kurulumunda başlatılan demo süresi (gün).
    'demo_days' => (int) env('LICENSE_DEMO_DAYS', 14),

    // İmzalı lisans belgesi: ECDSA P-256 (ES256). Özel anahtar dosyası public dizininin DIŞINDA, 600 izinli.
    // Oluşturma: php artisan panolicense:signing-key. Anahtar değişirse kid de değişmeli (istemci kid'e göre seçer).
    'signing' => [
        'key_path' => env('LICENSE_SIGNING_KEY_PATH'),
        'key_id' => env('LICENSE_SIGNING_KEY_ID', 'k1'),
    ],

    // Virgülle ayrılmış güvenilen proxy IP/CIDR listesi (ör. Cloudflare). Boş = hiçbiri.
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),

    // React panel build çıktısı (public/spa). Aynı domain altında sunulur.
    'spa_index' => public_path('spa/index.html'),
];
