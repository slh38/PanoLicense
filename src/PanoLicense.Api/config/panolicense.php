<?php

return [
    'version' => '0.2.0-f0',

    // Onaylı kural: son başarılı doğrulamadan sonra en fazla 7 gün offline (docs/LICENSING_RULES.md).
    'offline_grace_days' => (int) env('LICENSE_OFFLINE_GRACE_DAYS', 7),

    // Onaylı karar: PanoPOS kurulumunda başlatılan demo süresi (gün).
    'demo_days' => (int) env('LICENSE_DEMO_DAYS', 14),

    // F3b: imza anahtarı. Dosya public dizininin DIŞINDA olmalıdır.
    'signing' => [
        'key_path' => env('LICENSE_SIGNING_KEY_PATH'),
        'key_id' => env('LICENSE_SIGNING_KEY_ID', 'k1'),
    ],

    // Virgülle ayrılmış güvenilen proxy IP/CIDR listesi (ör. Cloudflare). Boş = hiçbiri.
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),

    // React panel build çıktısı (public/spa). Aynı domain altında sunulur.
    'spa_index' => public_path('spa/index.html'),
];
