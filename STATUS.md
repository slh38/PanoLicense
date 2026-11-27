# PanoLicense — Durum

Son güncelleme: 2026-11-28 · Faz: **FAZ 2 — Katalog ve yayın hazırlığı** tamamlandı (yerel; commit ve yayın yok). Ortak sözleşme: [LICENSING_CONTRACT](docs/LICENSING_CONTRACT.md) v2.

## Karar geçmişi
- 2026-11-22: İlk iskelet ASP.NET Core + SQL Server + IIS idi → **Laravel 13 (PHP 8.3+) + MySQL 8**'e geçildi. Sunucu tarafında .NET yok.
- .NET yalnız PanoPOS'un kullanacağı **Client kütüphanesinde** kalır ve PanoPOS gibi **.NET 8** hedefler.
- Yayın: Turhost cPanel uygun değildi (SSH yok, PHP 7.4) → **Contabo VPS** (Nginx + PHP 8.3-FPM + MySQL 8).
- Demo veri kaldırıldı; panel girişle açılır. User yetkileri: yeni lisans, ±30 gün süresi dolacaklar, tüm lisanslarda arama.
- 2026-11-25: PanoPOS kurulumunda **14 günlük demo** sunucuya kaydolur; personel kendi panel kullanıcı adını yazar
  (isteğe bağlı, yoksa son kullanıcı). Demo onayı `license.create` yetkisiyle. PanoPOS tarafı bu repo kapsamında değil.
- 2026-11-27: İmza algoritması **ECDSA P-256 (ES256)** onaylandı.

## Gerçek tamamlanma durumu

| Alan | Durum | Not |
|---|---|---|
| Repo | ✅ | `slh38/PanoLicense` private. Canlıda `9df87c8` (F3a). F3b, F4, panel 7/365, ürün/paket/modül ve FAZ 2 katalog tek commit için hazır; **canlıya yayınlanmadı**. |
| Giriş / kullanıcılar / loglar | ✅ canlı | Kullanıcı adı + parola, yetki kutucukları, pasifleştirme, `/loglar`, Türkçe mesajlar. |
| Lisans (panel) | ✅ canlı | Yeni lisans, ±30 gün süresi dolacaklar, arama, detay, Admin: süre uzatma + iptal. |
| Demo kaydı | ✅ canlı | `POST /client/demo`: 14 gün, cihaz limiti 1, kurulumu yapan (kullanıcı adı eşleşmesi), cihaz başına tek demo. |
| Demo onayı | ✅ canlı | Lisanslar → Demo Talepleri; eksik firma bilgisi + cihaz limiti + süre → aktif. Anahtar değişmez. "Kurulumu yapan" ve "Lisansı veren" ayrı. Reddetme = iptal. |
| Cihazlar | ✅ canlı / güvenlik yerel | `devices` tablosu, `POST /client/activate` (kilitli limit kontrolü), `POST /client/check`, panelde cihaz listesi + serbest bırakma, Dashboard aktif cihaz sayısı. |
| İmzalı belge (sunucu) | ✅ yerel | `LicenseSigner` (ES256, DER imza, base64url payload); `/client/demo, /client/activate, /client/check` yanıtlarında `license_document`; `valid_until` = issued + lisansın 7/365 günü (lisans bitişini geçmez); anahtar yoksa `503 signing_unavailable`; `panolicense:signing-key` komutu. **Canlı anahtar henüz oluşturulmadı.** |
| Offline (Client .NET 8) | ✅ yerel | `LicenseDocumentVerifier` + `OfflineLicenseEvaluator` (7/365 gün, cihaza bağlılık, saat geri alma, iptal/bitiş). Durumlar: `Demo`, `OfflineExpired`. |
| F4 Client | ✅ yerel | `LicenseManager`: demo/activate/check HTTP + challenge kanıtı; CNG cihaz anahtarı (TPM varsa, dışa aktarılamaz); cihaz anahtarıyla imzalı durum dosyası + kayıt defteri kopyası; kalıcı iptal ve kesin red kaydı; saat geri alma koruması (watermark + monoton saat, sunucu zamanıyla iyileşme); açılış + saatlik yerel, 6 saatlik online kontrol; 15 dk → 6 sa kademeli tekrar + jitter; bağlantı dönüşünde hemen kontrol; tek uçuşlu istek. Ürün bağımsız; ek NuGet paketi yok. |
| Ürün / paket / modül | ✅ yerel | Panelde Ürünler sayfası (Admin): ürün, paket, modül ve paket–modül eşleşmesi. PanoPOS: Lite, Pano, Restaurant, Pro; demo Pro (14 gün); mevcut lisanslar Pro. Lisans verirken paket zorunlu. Lisansa özel ek modül (`license_module`), paket değiştirme yalnız Admin ve loglu; paket değişimi cihaz limitini ve ek modülleri değiştirmez. İmzalı belgede `package` + geçerli `modules`; Client `HasModule` ağ/disk kullanmaz. Migration 2026_11_28_100000 yalnız yerel. |
| FAZ 2 katalog | ✅ yerel | 16 modül + Lite/Pano/Restaurant/Pro matrisi `config/catalog.php`; `panolicense:catalog-sync` (tekrar çalıştırılabilir, ezmez, silmez, `--dry-run`). Yerel dev DB'de uygulandı; deneme modülleri (`table_order`, `backup`) yalnız yerelde pasif. Ürün pasifleştirme düzeltmesi (Codex, `InactiveProductTest`) doğrulandı. Canlı yayın planı DEPLOYMENT "FAZ 2 canlı yayın planı". |
| Testler | ✅ | Katalog: hedefli PHPUnit 68/68, xUnit 78/78, Vitest 20/20, panel build ve tarayıcı kontrolü geçti. Son dört bulgu: hedefli xUnit 34/34, .NET build 0 uyarı/0 hata. Önceki F4: ilgili xUnit 59/59 (F3b belge testleri dahil) + yerel gerçek Laravel API ile uçtan uca test (TPM anahtarı, demo → aktivasyon → check → offline → iptal → offline iptal) geçti; .NET build 0 uyarı/0 hata. Backend değişmedi; PHPUnit bu görevde çalıştırılmadı. Tam regresyon yok. |

## Offline politika ek işi
- Backend: lisans bazlı 7/365; mevcut/yeni varsayılan 7; 365 verme/değiştirme yalnız Admin; demo 7.
  Süre ticari lisans bitişini aşmaz; değişiklik audit'i transaction içinde. Client imzalı sözleşmesi güncel.
- Migration 2026_11_27_130000_add_license_offline_policy yalnız yerel test ortamında; canlıya uygulanmadı.
- 365 günlük izin cihaz online olmadıkça uzaktan iptal edilemez; ayrıntı LICENSING_RULES §22.
- React panel: yeni lisans, demo onayı ve lisans detayında 7/365 seçimi + Admin politika kartı + 365 uyarısı (yerel, yayın yok).
- Nihai Client kontrol politikası onaylandı; zamanlama, retry/jitter, arka plan/satış ayrımı ve kalıcı iptal
  gereksinimleri [LICENSING_RULES §25](docs/LICENSING_RULES.md#i̇mzalı-lisans-ve-offline-2026-11-27) ve ARCHITECTURE §9'da. F4 Client ile uygulandı (§11).


## Ürün pasifleştirme düzeltmesi
- Yerelde doğrulandı: yeni lisans/demo engellenir; mevcut lisansların aktivasyon/check/challenge ve offline yenilemesi sürer.
- Hedefli PHPUnit 27/27; paket/modül davranışı korunur. Migration/yayın/commit yok.

## Sonraki aşamalar
- **F3b güvenlik:** cihaz P-256 anahtarı + 120 saniyelik atomik tek kullanımlık challenge; belge anahtar hash'ine bağlı.
  Client'ta beklenen ürün/lisans/fingerprint/yerel anahtar hash'i zorunlu. Eski aktif anahtarsız kayda otomatik bağlama yok;
  yetkili release + kanıtlı yeniden aktivasyon gerekir. Migration 2026_11_27_120000_add_device_proof yalnız test ortamı.
- **F3b kalan:** onayla commit/push + yayın; canlı imza anahtarının oluşturulması ve sunucu dışı yedeği ([DEPLOYMENT](docs/DEPLOYMENT.md#i̇mza-anahtarı-f3b-yayınında-bir-kez)).
- **F4 son düzeltmeler:** kayıt hatasında mevcut süreç kapalı; normal kapanışta zaman kaydı; ticari izin tek canlı karar; bağlantı dönüşünde sonuçtan bağımsız kontrol + tekrar birleştirme. Kalıcı kayıttan önce çökme/dondurulmuş saat belirsizliği kullanıcı onayıyla kabul edildi (LICENSING_RULES son bölüm).
- **Yayın (onay bekliyor):** commit/push → DEPLOYMENT "FAZ 2 canlı yayın planı" (3 migration + catalog-sync + imza anahtarı k1 + yedek/geri dönüş). Sonra FAZ 3 (Device Service lisans ajanı).
- **F5 — PanoPOS entegrasyonu** (PanoPOS reposu, ayrı görev ve onay).
- **F2c:** firma düzenleme.
- **F6 — Operasyon:** otomatik yedekleme, izleme, bekleyen sistem güncellemeleri.

## Açık kararlar
1. ~~İmza algoritması~~ → ECDSA P-256 onaylandı (2026-11-27).
2. Cihaz parmak izi kaynakları ve donanım değişimi toleransı (PanoPOS/Client tarafı).
3. Cihaz serbest bırakma sınırı (ör. ayda kaç kez) gerekiyor mu?
4. ~~PanoPOS modül matrisi~~ → onaylandı ve `config/catalog.php` ile uygulandı (2026-11-28, LICENSING_CONTRACT §2).
5. Süresi dolan lisans için User yeni lisans veremez (aktif kayıt var); yenileme Admin'in süre uzatmasıyla.
6. Demo kurulumunda KVKK bilgilendirme metni (PanoPOS tarafı).
