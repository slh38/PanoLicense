<?php

/**
 * Başlangıç ürün kataloğu (onaylı: docs/LICENSING_CONTRACT.md §2, 2026-11-28).
 * `php artisan panolicense:catalog-sync` ile uygulanır. Komut yalnız EKSİK kayıtları ekler:
 * mevcut ad/açıklama/aktiflik/limit ve Admin'in paket–modül düzenlemeleri ezilmez, hiçbir şey silinmez.
 * Kodlar değişmez (imzalı belgeye ve ürün yazılımına girer).
 *
 * Planlanan modüller yalnız açıklamada belirtilir (ayrı DB alanı yok).
 */
$planned = 'Planlanan: PanoPOS\'ta henüz geliştirilmedi; lisansa verilebilir ama geliştirilene kadar etkisizdir.';

return [
    'products' => [
        'PANOPOS' => [
            'name' => 'PanoPOS',
            'key_prefix' => 'PAN',
            'demo_package' => 'PRO',

            'modules' => [
                'quick_sale' => ['Hızlı Satış', 'Hızlı satış ekranı ve /hizli-satis uçları. Ortak fatura/tahsilat temeldir.'],
                'shift' => ['Vardiya', 'Kısmi — '.$planned.' Backend çekirdeği var; satış vardiya gerektirmez.'],
                'variant' => ['Renk / Beden', 'Kısmi: backend hazır, Desktop ekranı kısmi.'],
                'multi_warehouse' => ['Çoklu depo ve transfer', 'İkinci ve sonraki depo, transfer fişi. Kapanınca mevcut veriler korunur.'],
                'bank' => ['Banka işlemleri', 'Banka hesapları, transfer ve banka hareketleri. Normal kart tahsilatı temeldir.'],
                'advanced_pricing' => ['Gelişmiş fiyat', 'Kısmi: toplu fiyat güncelleme var; cari fiyat tipi yalnız backend.'],
                'advanced_reports' => ['Gelişmiş raporlar', 'Kârlılık, stok envanter, stok hareket özeti, banka bakiyeleri.'],
                'restaurant' => ['Restoran / adisyon', 'Kısmi — '.$planned.' Masa/adisyon backend çekirdeği var, Desktop ekranı yok.'],
                'mobile_waiter' => ['Mobil garson', $planned.' Cihazları satış bilgisayarı kotasından düşmez.'],
                'multi_branch' => ['Çok şube / merkezi yönetim', $planned.' Şube altyapısı var.'],
                'customer_display' => ['Müşteri ekranı', $planned],
                'erp_integration' => ['ERP entegrasyonu', $planned],
                'production' => ['Üretim', $planned],
                'fiscal_pos' => ['Yazarkasa POS', $planned.' Ayrıca satılır.'],
                'e_invoice' => ['e-Fatura / e-Arşiv', $planned.' Ayrıca satılır.'],
                'cloud_backup' => ['Bulut yedekleme', $planned.' Ayrıca satılır.'],
            ],

            // Paket → modüller. Ayrıca satılanlar (fiscal_pos, e_invoice, cloud_backup) hiçbir pakette yoktur.
            'packages' => [
                'LITE' => ['Lite', 10, ['quick_sale']],
                'PANO' => ['Pano', 20, ['quick_sale', 'shift', 'variant', 'multi_warehouse', 'bank', 'advanced_pricing', 'advanced_reports']],
                'RESTAURANT' => ['Restaurant', 30, ['restaurant', 'shift', 'multi_warehouse', 'bank', 'advanced_pricing', 'advanced_reports', 'mobile_waiter']],
                'PRO' => ['Pro', 40, [
                    'quick_sale', 'shift', 'variant', 'multi_warehouse', 'bank', 'advanced_pricing', 'advanced_reports',
                    'restaurant', 'mobile_waiter', 'multi_branch', 'customer_display', 'erp_integration', 'production',
                ]],
            ],
        ],
    ],
];
