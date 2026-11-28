# PanoLicense — Durum

## Güncel lisans kapsamı — FAZ 6.2a (2026-11-28)
- Son onaylı Backend commit: fb55106fb29cd923c463c970a90abac5e856e501 (tek v2 Backend; commit/push tamamlandı).
- Ürün müşteriye çıkmadı; gerçek müşteri lisansı yok, tüm kayıtlar TEST. Kayıt/anahtar/audit silinmeyecek.
- Ortak sözleşme v2.3 §12 iki repoda eşittir ve bu tur değiştirilmedi. Tek v2 Backend ve Client 1.1.0 kaynak geliştirmesi tamamlandı; resmî paket üretildi; tüketici entegrasyonu bekliyor.
- Gerçek yerel kod: süreli/demo/perpetual tek v2, zorunlu license_type ve kesirli zaman; aktif v1 üretimi yok. Admin-only dönüşüm ve mevcut nullable migration korundu.
  Önceki 76 hedefli test bu Backend commit'ine aittir; tek v2/1.1.0 kabulü değildir.
- Mevcut resmî paket ve Client kaynak sürümü 1.1.0'dır; eski 1.0.1 arşivi değişmeden korunur. Resmî 1.1.0 paket üretildi; panel ve PanoPOS entegrasyonu bekliyor.
  Mevcut iki bayrak varsayılan kapalı kalır; koordineli dağıtım/kabul sonrası kaldırılması planlıdır.
- Aşağıdaki eski faz/yayın notları tarihçedir; bu görevin güncel canlı doğrulaması değildir.
  Önceki MD turu b2f70c1 ile kapandı. Bu tur FAZ 6.2a.2b Backend + hedefli testler; yalnız kullanıcı ek onayıyla izole test DB migration'ı yapıldı, canlı işlem/commit/push yok.

Son güncelleme: 2026-11-28 · Faz: **FAZ 6.2a.3 — Client 1.1.0 ve resmî paket tamamlandı; PanoPOS entegrasyonu bekliyor.** FAZ 6.2a.2 Backend commit/push tamamlandı. Ortak sözleşme: [LICENSING_CONTRACT](docs/LICENSING_CONTRACT.md) v2.3; güncel karar ve gerçek uygulama ayrımı yukarıdadır.

## Karar geçmişi
- 2026-11-22: İlk iskelet ASP.NET Core + SQL Server + IIS idi → **Laravel 13 (PHP 8.3+) + MySQL 8**'e geçildi. Sunucu tarafında .NET yok.
- .NET yalnız PanoPOS'un kullanacağı **Client kütüphanesinde** kalır ve PanoPOS gibi **.NET 8** hedefler.
- Yayın: Turhost cPanel uygun değildi (SSH yok, PHP 7.4) → **Contabo VPS** (Nginx + PHP 8.3-FPM + MySQL 8).
- Demo veri kaldırıldı; panel girişle açılır. User yetkileri: yeni lisans, ±30 gün süresi dolacaklar, tüm lisanslarda arama.
- 2026-11-25: PanoPOS kurulumunda **14 günlük demo** sunucuya kaydolur; personel kendi panel kullanıcı adını yazar
  (isteğe bağlı, yoksa son kullanıcı). Demo onayı `license.create` yetkisiyle. PanoPOS tarafı bu repo kapsamında değil.
- 2026-11-27: İmza algoritması **ECDSA P-256 (ES256)** onaylandı.

## Önceki fazların tamamlanma kayıtları (tarihçe)

| Alan | Durum | Not |
|---|---|---|
| Repo | ✅ | `slh38/PanoLicense` private. **Canlıda `bd4948d`** (F3b, F4, panel 7/365, ürün/paket/modül, FAZ 2 katalog; 2026-11-27). İmza anahtarı `k1` canlıda. |
| Canlı kabul (FAZ 3.3) | ✅ canlı | 2026-11-27: 12 senaryo PASS (demo→ticari, gerçek TPM cihaz, limit, 7/365 offline, serbest bırakma, paket/ek modül, iptal kalıcılığı, loglar). TEST lisansları iptal, cihazlar serbest; ayrıntı TESTING "FAZ 3.3". |
| Giriş / kullanıcılar / loglar | ✅ canlı | Kullanıcı adı + parola, yetki kutucukları, pasifleştirme, `/loglar`, Türkçe mesajlar. |
| Lisans (panel) | ✅ canlı | Yeni lisans, ±30 gün süresi dolacaklar, arama, detay, Admin: süre uzatma + iptal. |
| Demo kaydı | ✅ canlı | `POST /client/demo`: 14 gün, cihaz limiti 1, kurulumu yapan (kullanıcı adı eşleşmesi), cihaz başına tek demo. |
| Demo onayı | ✅ canlı | Lisanslar → Demo Talepleri; eksik firma bilgisi + cihaz limiti + süre → aktif. Anahtar değişmez. "Kurulumu yapan" ve "Lisansı veren" ayrı. Reddetme = iptal. |
| Cihazlar | ✅ canlı / güvenlik yerel | `devices` tablosu, `POST /client/activate` (kilitli limit kontrolü), `POST /client/check`, panelde cihaz listesi + serbest bırakma, Dashboard aktif cihaz sayısı. |
| İmzalı belge (sunucu) | ✅ yerel | `LicenseSigner` (ES256, DER imza, base64url payload); `/client/demo, /client/activate, /client/check` yanıtlarında `license_document`; `valid_until` = issued + lisansın 7/365 günü (lisans bitişini geçmez); anahtar yoksa `503 signing_unavailable`; `panolicense:signing-key` komutu. **Canlı anahtar henüz oluşturulmadı.** |
| Offline (Client .NET 8) | ✅ yerel | `LicenseDocumentVerifier` + `OfflineLicenseEvaluator` (7/365 gün, cihaza bağlılık, saat geri alma, iptal/bitiş). Durumlar: `Demo`, `OfflineExpired`. |
| F4 Client | ✅ yerel | `LicenseManager`: demo/activate/check HTTP + challenge kanıtı; CNG cihaz anahtarı (TPM varsa, dışa aktarılamaz); cihaz anahtarıyla imzalı durum dosyası + kayıt defteri kopyası; kalıcı iptal ve kesin red kaydı; saat geri alma koruması (watermark + monoton saat, sunucu zamanıyla iyileşme); açılış + saatlik yerel, 6 saatlik online kontrol; 15 dk → 6 sa kademeli tekrar + jitter; bağlantı dönüşünde hemen kontrol; tek uçuşlu istek. Ürün bağımsız; ek NuGet paketi yok. |
| Client NuGet paketi | ✅ yerel | `PanoLicense.Client` **1.0.0** resmî; **1.0.1** resmî (ticari yetki kanıtı + `CommercialProofVerifier`; kaynak `846a407`, SHA-256 `5bf8aee3…d954`) (`net8.0`, bağımlılık yok); `tools/pack-client.ps1` (temiz ağaç şartı, içerik/gizli bilgi denetimi, yeniden üretilebilir, `.sha256`). .NET 8 ve .NET 10 tüketicide doğrulandı. Resmî paket kaynak `2a91b68`, SHA-256 `3f7fc9b3…2d91`; ayrıntı [CLIENT_PACKAGE](docs/CLIENT_PACKAGE.md). |
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

## Önceki fazların sonraki aşama notları (tarihçe; güncel görev listesi değildir)
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

## FAZ 6.2a.2 — Ömür boyu lisans Backend (2026-11-28)

- Yerel Backend tamamlandı: açık term/perpetual türü, nullable bitiş; yalnız Admin oluşturma ve tek yönlü
  demo/term dönüşümü; kimlik/cihaz/paket/modül koruması; audit; iptal ve perpetual uzatma reddi.
- v1 süreli belge korunur; v2 perpetual imza/offline hesapları hazır. Dağıtım varsayılan kapalı;
  production ayrıca ayrı onay bayrağı gerektirir. Yeni Client/Device/WebApi hazır olmadan açılmaz.
- Migration 2026_11_28_160000_add_perpetual_licenses yalnız korumalı yerel panolicense_test MySQL'de doğrulandı:
  eski satır korunur, nullable tarih çalışır, null kayıt varsa kayıplı down engellenir. Canlıya uygulanmadı.
- Hedefli PHPUnit: 76 farklı test PASS (12 yeni + 64 mevcut); PHP biçim/sözdizimi kontrolü.
  Tam regresyon, panel/Client build veya NuGet üretimi yapılmadı.
- Tarihsel FAZ 6.2a.2 kapanışında sözleşme v2.2/v2.1 ayrışmıştı; bu MD turunda iki kopya v2.3 olarak eşitlendi.
  Panel, Client ve PanoPOS entegrasyonu bekliyor. Backend kapanışı ve commit/push kullanıcı tarafından onaylandı; canlı yayın yok.

## FAZ 6.2a.2b — Tek v2 Backend (tamamlandı ve onaylandı; canlı yayın yok)

- LicenseSigner yalnız v2 üretir; term/demo/perpetual türü imzalıdır, hatalı tür/tarih ve tüm v1 imzalama
  istekleri reddedilir. UTC mikrosaniye ve önceki v2 +1 mikrosaniye kuralı korunur.
- Süreli/demo min(offline sonu, bitişi izleyen UTC 00:00); perpetual issued_at + 7/365 gün.
- Mevcut iki kapalı bayrak artık tüm v2 üretimini kapatır. Production ikinci açık onay gerektirir.
  Client challenge/demo/activate/check 503 signing_unavailable ile kayıt bırakmadan kapanır; v1 fallback yok.
  Gerçek env veya bayrak varsayılanları değiştirilmedi. Yalnız test uygulaması pozitif senaryolarda izin verir.
- 56 hedefli PHPUnit PASS (556 assertion); yalnız korumalı panolicense_test MySQL.
  Başlangıçta eksik şema görüldü; kullanıcı ek onayından sonra yalnız izole test DB'de mevcut migration'lar uygulandı.
  Yeni migration yok. Canlı/dev DB, gerçek TEST lisansları, audit veya anahtar temizliği yapılmadı.
- Client 1.1.0 ve geçmiş v1 iptal okuyucusu sonraki görevdir. Bu Backend mevcut iptal kayıtlarını okumaz/silmez;
  yeni iptal yanıtı da v2'dir. PanoPOS ve panel değişmedi. Koordineli kabul/dağıtım henüz yapılmadı.

## FAZ 6.2a.3 — Client 1.1.0 (onaylandı; kaynak ve resmî paket tamamlandı)

- Yalnız v2 ticari kabul; zorunlu license_type, term geçerli tarih, perpetual açık null;
  eksik/bilinmeyen/çelişkili tür/tarih reddi. Demo term/7 gün/tek cihaz; sonlu 7/365 offline sınırı doğrulanır.
- LicenseStatus.LicenseType eklendi (bilgi yoksa null); LicenseDocument.ExpiresAt ve LicenseEndUtc nullable,
  AllowedUntil sonlu izin sınırını verir. Mevcut kanıt/kimlik/metot adları ve amaç metinleri korundu.
- V1 yalnız geçmiş iptal okumada: ReadRevocation ve korumalı durumun iptal belgesi yüklemesi;
  aktif v1 ticari/rapor/modül yetkisi vermez. Korumalı kayıt şeması, cihaz anahtarı ve iptal/zaman geçmişi sıfırlanmadı.
- Yeni hedefli V2LicenseTests 26/26 PASS; ardından Client test paketinin tamamı bir kez: 111/111 PASS.
  Gerçek API/DB E2E testi ortam kapısı kapalı olduğundan işlem yapmadan döndü; canlı bağlantı yok.
  Release Client build 0 uyarı/0 hata. PanoPOS regresyonu veya Backend testi/migration çalıştırılmadı.
- Resmî 1.0.1 paketi değişmedi (SHA-256 5bf8aee3…d954). 1.1.0 NuGet kapanışta üretildi; kaynak commit ve doğrulanmış hash CLIENT_PACKAGE kaydındadır. Dağıtım bayrakları kapalı kaldı.
- Backend, panel, ortak v2.3 sözleşmesi ve PanoPOS kaynakları değiştirilmedi. FAZ 6.2a.4 başlamadı.
  Geliştirme adımında commit/push yapılmamıştı; onaylı kaynak/paket kapanışı aşağıdadır. Gerçek anahtar/DB işlemi veya TEST kaydı temizliği yok.

### FAZ 6.2a.3 paket kapanışı (2026-11-28)
- Client kodu c2257ff34c040c0446b9f84806fe4f5316a8f3fe; paketleme ZIP zamanı düzeltmesini de içeren
  paket kaynak commit'i 4e6e04515ede37f3b69ad2b72a047cd83812a75e.
- Resmî artifacts/nuget/PanoLicense.Client.1.1.0.nupkg: 46 740 bayt,
  SHA-256 b381300fa3552109c0b84dcf27f7eef3d29be7864c9e2f4bfd128f71b112e1be.
  Nuspec sürümü/kaynak commit, içerik/gizli bilgi ve iki temiz üretimde eşit hash doğrulandı.
- 1.0.1 paketi değişmedi. 111 başarılı test tekrar edilmedi; gerçek API/DB E2E hâlâ yapılmadı.
  PanoPOS entegrasyonu/kabulü bekliyor. Bayraklar açılmadı; canlı/DB/migration işlemi yok.
- Bu kapanış yalnız Client/paket ve kayıtlarıdır; FAZ 6.2a.4 başlamadı.
