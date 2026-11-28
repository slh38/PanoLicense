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

## API ile çalışma (F2'den itibaren)
- Panel yalnız göreli `/api/v1/...` çağırır; Sanctum cookie oturumu kullanılır (önce `/sanctum/csrf-cookie`).
- Menü ve buton görünürlüğü `GET /api/v1/me` dönen `permissions` listesine göre ayarlanır;
  asıl yetki kontrolü her zaman sunucudadır.
- Yayın: `npm run build:deploy` → Laravel `public/spa`, aynı domain.

## Offline çalışma politikası (2026-11-27)
- Bileşen: `src/components/licenses/OfflinePolicy.tsx` (iki seçenekli kart grubu + uyarı); yardımcılar `src/lib/offline.ts`.
- **Yeni lisans** ve **demo onayı** formlarında "Offline çalışma": Standart · 7 gün (varsayılan) / Özel · 365 gün.
  `license.offline_policy` yetkisi yoksa (User) 365 kilitli gösterilir ve istek alanı hiç gönderilmez (sunucu 7 uygular).
- **Lisans detayı:** her lisansta "Offline çalışma" satırı (365 turuncu rozet); demo için "daima 7 gün" notu.
  Aktif ticari lisansta yalnız Admin "Offline çalışma politikası" kartıyla 7 ↔ 365 değiştirir
  (`POST /licenses/{id}/offline-policy`). Demo ve iptal edilmiş lisansta kart yoktur.
- 365 seçiliyken kısa uyarı: cihaz internete bağlanmazsa iptal/politika değişikliği offline izin bitene kadar
  etkili olmayabilir. Değişiklik geçmişte "Offline politikayı değiştirdi · 7 gün → 365 gün" olarak görünür.

## Ürünler (2026-11-28)
- Menüdeki "Modüller" ve "Paketler" boş sayfaları tek **Ürünler** sayfasına (`/urunler`, yalnız Admin) dönüştü.
  Solda ürün listesi + "Yeni ürün"; sağda ürün adı/aktiflik/demo paketi ve **Paketler | Modüller** sekmeleri.
  Paket kartında ad, varsayılan cihaz limiti ve modül kutucukları; modül listesinde hangi paketlerde olduğu görünür.
- Yeni lisans ve demo onayı: ürün → paket seçimi (`PackagePicker`), paket modülleri ön izleme; cihaz limiti paketten dolar.
- Lisans detayı: Paket ve Modüller satırı (ek modül "· ek" rozetiyle); Admin için "Paket ve modüller" kartı.
- Lisans tablosunda ürün rozetinin altında paket adı.

## Süreli / ömür boyu lisans (FAZ 6.2a.5)
- "Lisans türü" seçimi Offline politika seçimiyle aynı kart-radyo düzenindedir (`LicenseTypePicker`); yeni renk/kütüphane yok.
  Ömür boyu yalnız `license.perpetual` (Admin) ile seçilir; User'da kilit simgesi ve gerekçe görünür.
- Ömür boyu seçilince bitiş alanı gizlenir ve istek `expires_at: null` taşır; süreli lisansta bitiş zorunlu ve başlangıçtan sonra.
- Liste "Bitiş" sütunu ve detay "Lisans türü / Geçerlilik" satırları türü gösterir (ömür boyu: "Ömür boyu", "… tarihinden itibaren · bitiş yok").
  Boş tarih tek başına ömür boyu sayılmaz; çelişkili kayıt "Bilinmiyor" / "—" görünür.
- Dönüşüm kartı yalnız Admin + aktif süreli lisansta; iki adımlı onay korunan anahtar/cihaz/limit/paket/modül/offline
  bilgisini ve geri alınamazlığı yazar. Ömür boyunda süre uzatma ve ters dönüşüm hiç gösterilmez; iptal/demoda dönüşüm yok.
- Dağıtım bayrakları kapalıyken ömür boyu seçimi ve dönüşüm onayında bilgi notu gösterilir; sunucunun
  "henüz açılmadı" hatası alan/kart hatası olarak aynen görünür. Panel bayrak durumunu önceden okuyamaz (STATUS: API eksiği).
- Mantık `lib/licenseType.ts`'tedir (görünürlük + form ön doğrulaması); asıl yetki/bayrak kontrolü sunucudadır.
