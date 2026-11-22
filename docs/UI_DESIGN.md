# Panel UI Tasarım Kuralları

Otorite: renk/ölçü değerleri `src/PanoLicense.Panel/src/index.css` tokenlarındadır; buraya kopyalanmaz.

## İlkeler
- Modern, sade, profesyonel; lacivert, mor ve mavi ağırlıklı palet.
- Light/Dark tema: `html.dark` sınıfı, tercih `localStorage["pl-theme"]`, yoksa sistem tercihi.
- Responsive: masaüstünde sabit sol menü, dar ekranda açılır menü; yatay sayfa kaydırması yok.
- Animasyon yalnız kısa durum geçişlerinde; ağır grafik/animasyon kütüphanesi yok (grafikler hafif SVG).
- Bileşenler shadcn/ui yaklaşımıyla repoda (`src/components/ui`) tutulur; yalnız kullanılan eklenir.

## Davranış kuralları
- Henüz geliştirilmeyen sayfalar tutarlı **boş durum** ekranı gösterir.
- Gerçek işlem yapıyormuş gibi davranan sahte kaydet/sil butonu yok; mock veri açıkça "örnek veri" etiketlidir.
- Silme eylemi yoktur; iptal/pasif kullanılır ([LICENSING_RULES](LICENSING_RULES.md)).

## Tasarım referansı
Kullanıcının verdiği dashboard görseli (2026-11-22) ana referanstır; onaylı sapma: daha az renkli,
lacivert/mavi ağırlıklı. Durum renkleri (yeşil/turuncu/kırmızı) yalnız durum anlamı için kullanılır.

| Alan | Karar |
|---|---|
| Sol menü | Logo + 8 menü; aktif öğe dolu mavi. Masaüstünde ☰ ile ikon moduna daralır, < 1024 px'te çekmece. |
| Üst bar | ☰, "Lisans Sunucusu", arama (pasif, F2), tema anahtarı, bildirim (pasif), kullanıcı. |
| Dashboard | Karşılama + "Yeni Lisans"; 5 KPI kartı (Aktif Lisans, Müşteri, Aktif Cihaz, Süresi Dolacak, Pasif/İptal); sekmeli tablo (Aktif / Süresi Dolacak / Son İşlemler); sağda Hızlı İşlemler + Paket Dağılımı halka grafiği. |
| Tablo | Görseldeki düzenleme/menü ikonları yerine **Cihaz (kullanılan/limit)** sütunu; sahte işlem butonu yok. |
| Hızlı işlemler | İlgili sayfaya yönlendirir; form/işlem F2'de. |
| Boş sayfa | İkon + "Planlanan faz" + açıklama + Dashboard'a dönüş. |

Kod: `src/components/layout` (Sidebar, Topbar, AppLayout), `src/components/dashboard`,
`src/pages`, menü tanımı `src/lib/navigation.ts`, örnek veri `src/data/mockDashboard.ts`.
