# Test ve Doğrulama

Test risk bazlıdır: değişen alanın testleri + build/typecheck. Tam regresyon rutin değildir;
yalnız lisans karar mantığı, imza/offline doğrulama, yetki veya şema değişikliğinde genişletilir.

| Değişiklik | Asgari doğrulama |
|---|---|
| Laravel kodu | `php artisan test --filter=...` (ilgili test sınıfı) |
| Migration | `php artisan migrate:fresh` yerel dev DB'de + ilgili testler (PHPUnit `panolicense_test` MySQL DB'sini kullanır) |
| Yetki / rol | `AuthorizationTest` + değişen uçların yetkisiz (401/403) testleri **zorunlu** |
| Lisans kararı, kota, offline, imza | İlgili testler **zorunlu** + kenar durumlar (süre sınırı, saat geri alma, eşzamanlı son kota) |
| Panel | `npm run build` (tsc dahil) + ilgili Vitest + tarayıcıda light/dark görsel kontrol |
| Aynı domain yayını | `npm run build:deploy` + `php artisan serve` ile `/`, bir menü yolu ve `/api/v1/health` |
| Client (.NET) | `dotnet build PanoLicense.sln` + ilgili xUnit testleri |
| Client paketi | `tools/pack-client.ps1` + .NET 8 ve .NET 10 tüketici derleme/çalıştırma ([CLIENT_PACKAGE](CLIENT_PACKAGE.md)) |

- Testler gerçek MySQL 8'de koşar (SQLite değil) — MySQL'e özgü davranışlar (kilit, üretilmiş sütun) test edilebilsin.
- Test verisi uydurmadır; gerçek müşteri verisi kullanılmaz.
- Başarısız test/build ile iş tamamlandı denmez; atlanan doğrulama raporda açıkça yazılır.


## F3b cihaz sahipliği hedefli doğrulama
- PHP filtre: DeviceProofTest|ClientActivationTest|ClientDemoTest|LicenseDocumentTest.
- .NET filtre: FullyQualifiedName~LicenseDocumentTests.
- Kopyalanmış fingerprint/lisans + başka anahtar; yanlış imza; kullanılan/süresi dolmuş challenge;
  başka işlem/ürün/lisans/fingerprint; kota ve idempotent tekrar; eski kayıt geçişi; farklı ürün/lisans/cihaz
  belgesinin reddi kapsanır. Tam regresyon bu güvenlik düzeltmesinin kapsamı değildir.
- PHPUnit başlangıç koruması yalnız 127.0.0.1, MySQL, panolicense_test, boş DB_URL ve testing ortamına
  izin verir; migration bu izole DB'de çalışır. Testler canlıya/dev DB'ye taşınamaz.
- PHP imzalı fixture tests/PanoLicense.Tests/Fixtures/regenerate.php ile üretilebilir; özel anahtar
  yalnız bellektedir. Fixture'da yalnız açık anahtarlar, hash ve imzalı test belgeleri tutulur.

## 7/365 günlük lisans politikası hedefli doğrulama
- PHP filtre: OfflinePolicyTest|AuthorizationTest|LicenseTest|LicenseDocumentTest|DeviceProofTest|ClientActivationTest|ClientDemoTest.
- Client filtre: FullyQualifiedName~LicenseDocumentTests.
- Varsayılan/DB varsayılanı 7; Admin-only oluşturma/değişim ve yetki enjeksiyonu reddi; demo reddi ve
  ticari onay; 365 günlük imzalı belge, tüm cihazlarda lisans politikası, son gün lisans bitişi sınırı,
  online yenileme, 365→7, eski belge bitişinin uzaktan değişmemesi, audit/no-op ve imza tahrifi kapsanır.
- Migration yalnız korumalı yerel panolicense_test DB'sinde uygulanır.
- Panel (2026-11-27): `npx vitest run src/lib/offline.test.ts src/lib/audit.test.ts` + `npm run build`;
  tarayıcıda Admin (365 seç, oluştur, 365→7 değiştir, geçmiş) ve User (365 kilitli, politika kartı yok) light/dark kontrolü.

## F4 kabul senaryoları (Client testleriyle kapsanır)
- Açılışta yerel doğrulama ve saatte bir yerel kontrol ağ çağrısı yapmadan doğrulanır.
- 7 ve 365 günlük lisanslarda aynı 6 saatlik online kontrol takvimi uygulanır.
- Bağlantı hatasında ilk tekrar 15 dakika sonra, devamında kademeli bekleme ve küçük jitter uygulanır;
  zamanlayıcı testleri denetlenebilir saat/rastgelelik kullanır, gerçek saatlerce beklemez.
- Bağlantı dönüşü hata beklemesini de aşar; eşzamanlı periyot/bağlantı/retry tetikleri paralel istek üretmez.
- Satış akışı internet sorgusu tetiklemez ve ağ sonucunu beklemez; yavaş/yanıtsız sunucu UI'ı bloke etmez.
- Arka plan kontrolü satıştan bağımsız çalışır; offline izin bitişi veya iptal saatlik aralığın sonunu bekleyerek uzatılamaz.
- Bağlantı geri geldiğinde, belge bitişi ve sıradaki periyodik kontrol beklenmeden ilk fırsatta challenge + check yapılır.
- İmzalı iptal öğrenildikten sonra tekrar bağlantı kesilmesi, uygulama yeniden başlatılması ve eski aktif belgenin
  yüklenmesi ticari işlemleri açamaz; korumalı kalıcı iptal kaydı önceliklidir.
- İptali kalıcı kaydetme hatası ticari işlemleri kapalı tutar; eski belgeye fallback yapılmaz.
- Kapsayan testler: LicenseManagerTests, CheckSchedulerTests, CngDeviceKeyStoreTests, RealApiEndToEndTests (aşağıda).
## F4 Client hedefli doğrulama
- `dotnet build PanoLicense.sln` + `dotnet test tests/PanoLicense.Tests` (59 test, saniyeler içinde; gerçek bekleme yok).
- `LicenseManagerTests`: sahte sunucu (`ClientTestKit.FakeLicenseServer`, PHP ile aynı ES256 biçimi) ve denetlenebilir saat
  (`ManualTime`) ile aktivasyon, 7/365 offline, lisans bitişi sınırı, saat geri alma/donma, kalıcı iptal + eski yedek,
  yazma hatasında iptal, kesin red vs bağlantı hatası, yanlış anahtar, tek uçuş, bozuk/kopya durum, eski belge, demo, challenge bağlamı.
- `CheckSchedulerTests`: 15 dk → 6 sa dizisi, jitter sınırı, bağlantı dönüşü, açılış takvimi.
- `CngDeviceKeyStoreTests`: yazılımsal ve TPM anahtar oluşturma, dışa aktarılamama, yeniden açma.
- `RealApiEndToEndTests` (yalnız loopback, ortam değişkeni yoksa çalışmaz): yerel `php artisan serve --host=localhost`
  + PANOLICENSE_E2E_URL, _KID, _PUBKEY_FILE, _ADMIN_USER, _ADMIN_PASSWORD. Test kendi firma/lisansını yerel dev DB'de oluşturur
  ve iptal eder. 2026-11-27: TPM anahtarıyla geçti.


## F4 son dört bulgu — hedefli doğrulama
- Filtre: FullyQualifiedName~F4CorrectionsTests|FullyQualifiedName~LicenseManagerTests|FullyQualifiedName~CheckSchedulerTests.
- F4CorrectionsTests: Finding=1 için 4 test (tek/tüm yuva yazma hatası, kalıcı/yarım kayıt iptali + çökme,
  kalıcı iz bırakılamayan çökmede onaylı süreklilik sınırı).
- Finding=2 için 4 test (normal kapanışla kısa oturum zamanı, normal saatli çökme, kayıt öncesi dondurulmuş
  saatli çökme sınırı, tespit edilen manipülasyonda anlık izin reddi). 10.000 ticari izin okuması ek yazım üretmez.
- Finding=3 için 4 test (eski görüntü + bekleyen HTTP'de bitiş, eski görüntüde iptal/kapanış,
  bozuk imza + geçerli ayna, bozuk kayıt tespitinin online doğrulamaya kadar kalıcılığı).
- Finding=4 için 2 test (başarı/hata sonrası bağlantı dönüşü, tekrarların birleştirilmesi ve tek uçuş).
- 14 yeni test + ilgili 20 mevcut test = 34/34 geçti. Çökme testleri kalıcı bellek yuvalarının son yazılmış
  içeriğini koruyarak Dispose'un ek kaydını dışarıda bırakır; fiziksel elektrik kesintisi testi değildir.
- Yalnız bu filtre ve .NET build çalıştırıldı; backend, panel, CNG/TPM ve gerçek API regresyonu tekrarlanmadı.
## Ürün / paket / modül hedefli doğrulama (2026-11-28)
- PHP: `CatalogTest` (+ `LicenseTest|OfflinePolicyTest|AuditLogTest|AuthorizationTest|Client*|LicenseDocumentTest|DeviceProofTest`):
  4 başlangıç paketi ve Pro demo; Admin katalog yönetimi, kod değişmezliği, başka ürün modülü reddi; User 403 (enjekte yetkiyle de);
  paket zorunluluğu; imzalı belgede paket + ek modüller, pasif modül hariç; paket değişiminde cihaz limiti/ek modül korunur;
  değişiklik sonraki check'te belgeye girer; loglar; demo Pro ve onayda paket seçimi.
- .NET: `ModuleTests` (imzalı modüller, ağsız HasModule, sonraki online kontrolde güncelleme, offline/kapalı lisans, imza kapsamı, eski belge).
- Panel: `audit.test.ts` + `npm run build`; tarayıcıda Ürünler sayfası, paket–modül kaydı, lisans detayında paket değiştirme ve ek modül (dark/light).


## Ürün pasifleştirme hedefli doğrulama
- Filtre: InactiveProductTest|ClientDemoTest|ClientActivationTest|CatalogTest; 27/27 geçti.
- InactiveProductTest (4 test): yeni lisans/demo/demo challenge reddi (önceden alınmış demo kanıtıyla da);
  pasif üründe ilk cihaz aktivasyonu/idempotent tekrar/kota; 7 ve 365 günlük imzalı izin yenilemesi,
  paket/modül listesinin korunması; yanlış cihaz anahtarı, lisans bitişi ve iptal kontrollerinin sürmesi.
- Yalnız korumalı yerel test DB; PHP sözdizimi kontrolü geçti. Migration, Client/panel değişikliği veya tam regresyon yok.
## FAZ 2 katalog hedefli doğrulama (2026-11-28)
- PHP: `CatalogSyncTest|CatalogTest|InactiveProductTest|LicenseDocumentTest|ClientDemoTest|LicenseTest`.
- `CatalogSyncTest`: onaylı matris ve Pro demo; tekrar çalıştırmada mükerrer kayıt/audit yok; Admin ad/açıklama/pasiflik/
  eşleşme/limit/demo değişiklikleri ezilmez; önceden var olan modül eşleşmesine dokunulmaz, hiçbir şey silinmez;
  `--attach-missing` yalnız ekler; `--dry-run` yazmaz; Lite imzalı belgesi `["quick_sale"]`.

## FAZ 3.3 canlı kabul testi (2026-11-27, `bd4948d`)
Canlı sunucuda, TEST adlı kayıtlarla; gerçek CNG anahtarları (cihaz A TPM) ve canlı `k1` açık anahtarıyla .NET Client.
Araç yalnız geçici (repo dışı); test sonunda TEST lisansları iptal edildi, cihazlar serbest bırakıldı, kayıt silinmedi.

| Senaryo | Sonuç |
|---|---|
| Oturumsuz erişim (catalog, licenses, products, audit-logs, users, modules) 401 | PASS |
| Admin `/me`: Admin-only yetkiler açık; User (bellekte, kayıtsız) Gate: yalnız varsayılan 3 yetki, pasif User her şey red | PASS |
| Katalog: LITE 1, PANO 7, RESTAURANT 7, PRO 13 modül; 16 modül | PASS |
| Demo: 14 gün, Pro, 13 modül, TPM anahtar, imza Client'ta doğrulandı | PASS |
| Demo onayı → ticari (PANO), Client check → Active + PANO modülleri | PASS |
| Panelden TEST lisansı (LITE, limit 1, 365 gün) + aktivasyon | PASS |
| Cihaz limiti: ikinci cihaz `device_limit_exceeded` (Denied) | PASS |
| Offline yeniden başlatma: 7 gün (issued+7) ve 365 gün (issued+365, lisans bitişinden önce); izin uzamaz | PASS |
| Cihaz serbest bırakma: yeni cihaz aktive olur; serbest cihaz eski 365 günlük belgeye dönmeden kapanır | PASS |
| Paket değiştirme (LITE→PRO, PANO→LITE) + ek modül `cloud_backup`; cihaz limiti değişmez; offline'da eski liste, online kontrolde yeni liste | PASS |
| İptal: Client imzalı iptali öğrenir, offline yeniden başlatmada da kapalı, modüller kapalı | PASS |
| İşlem geçmişi: demo/onay/oluşturma/aktivasyon/serbest bırakma/paket/ek modül/iptal logları | PASS |

Gözlem: lisans oluşturmada `license.offline_policy_change` logu `license.create`'ten önce yazılıyor (aynı transaction;
yalnız sıralama, işlevsel etkisi yok). User HTTP 403'ü canlıda parola girilmeden yapılamadığı için Gate ile doğrulandı;
HTTP 403 testleri PHPUnit'te kapsanıyor.
