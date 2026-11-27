# Mimari

Kurallar (ne olacağı) [LICENSING_RULES.md](LICENSING_RULES.md)'dedir; bu dosya nasıl uygulanacağını anlatır.
✅ = uygulandı, 🔜 = planlı (henüz kod yok).

> 2026-11-22 karar değişikliği: ASP.NET Core + SQL Server + IIS yerine **Laravel + MySQL 8**, yayın **Linux VPS**
> (Nginx + PHP-FPM; cPanel'de SSH/PHP 8.3 olmadığı için). Kod paylaşımlı hostinge taşınabilir kalır.
> .NET yalnız istemci kütüphanesinde (PanoLicense.Client) kalır ve PanoPOS gibi **.NET 8** (`net8.0`) hedefler.

## 1. Bileşenler ve sorumluluk ayrımı

```
 Ürün (PanoPOS, ...)                    lisans.jokeryazilim.com.tr (VPS, Nginx, tek site)
 ┌──────────────────────┐   HTTPS   ┌──────────────────────────────────────────────┐
 │ PanoLicense.Client   │──────────▶│ Laravel  public/index.php                    │
 │ (.NET DLL: imza      │           │  ├─ /api/v1/*          JSON API              │
 │  doğrular, offline   │           │  │   ├─ yönetim uçları (Sanctum oturumu)     │
 │  süre takibi)        │           │  │   └─ istemci uçları (aktivasyon/doğrulama)│
 └──────────────────────┘           │  ├─ /spa/assets/*      panel statik dosyaları│
                                    │  └─ diğer yollar       panel index.html      │
 Tarayıcı (Admin/User) ───────────▶ │              │ Eloquent                      │
   React panel                      │              ▼                               │
                                    │        MySQL 8 (yalnız localhost)            │
                                    └──────────────────────────────────────────────┘
```

| Proje | Sorumluluk | Yapmaz |
|---|---|---|
| **Api (Laravel)** | Tek otorite: lisans kararı, cihaz kotası, imzalama, yetki, denetim logu, panelin sunulması. | Ürün iş kuralı bilmez. |
| **Panel (React)** | Admin/User arayüzü; yalnız `/api/v1`'i çağırır. | İş kuralı hesaplamaz, yetkiyi tek başına uygulamaz. |
| **Client (.NET 8)** | Ürün içinde: aktivasyon isteği, imzalı lisans belgesini doğrulama, offline süre, `LicenseState`. | Lisans üretmez; sunucu imza özel anahtarını taşımaz; kendi cihaz anahtarını CNG deposundan (mümkünse TPM) kullanır; ürünün UI'ını bilmez. |

## 2. Laravel API yapısı
- ✅ Basit monolit. Uçlar `/api/v1` altında (`bootstrap/app.php` → `apiPrefix: 'api/v1'`).
  Sürümsüz `/api/...` yolu sunulmaz.
- ✅ Controller'lar `app/Http/Controllers/Api/V1`. Lisans mantığı büyüdükçe
  `app/Services` (ör. `LicenseActivationService`) eklenir; repository/CQRS katmanı açılmaz.
- ✅ Uçlar (`/api/v1`):

  | Uç | Yetki |
  |---|---|
  | `GET /health` | açık |
  | `POST /auth/login` · `POST /auth/logout` | açık (throttle:login) · oturum |
  | `GET /me` · `GET /dashboard` · `GET /products` | oturum |
  | `GET /licenses?q=` · `?scope=expiring` · `?scope=all` | `license.view` (filtresiz liste `license.list_all` = Admin) |
  | `GET /licenses/{id}` | `license.view` |
  | `POST /licenses` (mevcut firma veya yeni firma ile) | `license.create` |
  | `POST /licenses/{id}/extend` · `/revoke` | `license.extend` · `license.revoke` |
  | `GET /customers?q=` | `customer.view` |
  | `GET/POST /users` · `PATCH /users/{id}` | `user.manage` (Admin) |
  | `GET /audit-logs?group=&user_id=&license_id=` (yalnız okuma, 50/sayfa) | `audit.view` |
  | `GET /licenses?scope=demo` · `POST /licenses/{id}/approve` | `license.view` · `license.create` |
  | `POST /licenses/{id}/devices/{device}/release` | `device.release` |
  | `POST /client/demo` · `/client/activate` · `/client/check` (cihaz sahiplik kanıtı; önce POST /client/challenge) | throttle `license-demo` / `license-client` |

  İstemci sözleşmesi: [CLIENT_API.md](CLIENT_API.md). İş mantığı: `app/Services/ClientLicenseService.php`.

- ✅ Mesajlar Türkçe: `APP_LOCALE=tr`, `lang/tr/validation.php` (alan adları dahil), `lang/tr/auth.php`.
- ✅ Panel aynı domain'den sunulur: `npm run build:deploy` → `public/spa`; `routes/web.php`
  fallback'i `/api` dışındaki sayfa yollarını `public/spa/index.html`'e verir. Statik dosyaları
  web sunucusu doğrudan servis eder (PHP'ye uğramaz).
- ✅ Paylaşımlı hosting uyumu: `CACHE_STORE=database`, `QUEUE_CONNECTION=sync`, Redis yok.
- ✅ Proxy güveni: `TRUSTED_PROXIES` boşsa hiçbir proxy'ye güvenilmez; Cloudflare kullanılırsa
  yalnız onun IP aralıkları yazılır (rate limit'in IP sahteciliğiyle atlatılmaması için).

## 3. Kimlik doğrulama ve yetki
- ✅ **Sanctum SPA modu** (`statefulApi()`): panel ile API aynı domain'de olduğundan token yerine
  HttpOnly oturum cookie'si + CSRF (XSRF-TOKEN) kullanılır. Token tablosu yoktur.
  Oturumlar veritabanında ve şifreli (`SESSION_ENCRYPT=true`), canlıda `SESSION_SECURE_COOKIE=true`.
- ✅ Giriş akışı: `GET /sanctum/csrf-cookie` → `POST /api/v1/auth/login` (kullanıcı adı + parola,
  throttle:`login` 5/dk kullanıcı adı+IP) → oturum yenilenir → `POST /api/v1/auth/logout`. Parola `bcrypt`.
  Hatalı parola / bilinmeyen / pasif kullanıcı aynı mesajı alır. İlk Admin: `php artisan panolicense:create-admin`
  (parola gizli sorulur). Panel: `/giris` sayfası; oturum yoksa tüm sayfalar oraya yönlenir.
- ✅ **Roller:** `users.role` = `admin` | `user` (`App\Enums\UserRole`), `users.is_active`.
- ✅ **Yetkiler:** `App\Enums\Permission` (ör. `license.create`, `license.extend`, `license.revoke`,
  `customer.manage`, `device.release`, `audit.view`). User'a verilenler `user_permissions` tablosunda.
  - `Gate::before`: pasif kullanıcı → her şey reddedilir; Admin → her şeye izin.
  - User → yalnız kendisine verilen yetkiler. `user.manage`, `product.manage`, `settings.manage` yalnız Admin.
  - Yetkiler istek başına **tek sorguyla** yüklenip bellekten kontrol edilir.
  - Uçlarda `->middleware('can:license.revoke')` veya Policy ile uygulanır; `GET /me` panel için yetki listesini döner.
- ✅ **Rate limit:** `api` (120/dk kullanıcı/IP), `login` (5/dk e-posta+IP, 20/dk IP), `license-client` (30/dk IP).

## 4. MySQL bağlantı mimarisi
- ✅ `DB_CONNECTION=mysql`, `utf8mb4` / `utf8mb4_unicode_ci` (MySQL 8 ve MariaDB'de çalışır), saatler UTC.
- ✅ Yerel: MySQL 8.4, `panolicense_dev` (geliştirme) ve `panolicense_test` (PHPUnit) ayrı veritabanları,
  uygulama kullanıcısı yalnız bu iki veritabanında yetkili. Veri klasörü repoda değil.
- ✅ Canlı: VPS'te MySQL 8.0 (yalnız `127.0.0.1`), `panolicense` veritabanı ve yalnız ona yetkili kullanıcı.
  Bilgiler yalnız sunucuda (`/root/.panolicense-db` → `.env`). Canlı migration yalnız onayla.
- ✅ Şema yalnız Laravel migration ile değişir. Canlıda `php artisan migrate --force` yalnız onaylı yayın adımında;
  öncesinde veritabanı yedeği alınır.
- ✅ Fiziksel silme yok: kullanıcılarda `is_active`; lisanslarda `status` + `revoked_*` alanları (F1).

## 5. Veri modeli: Firma + Ürün + Cihaz limiti
✅ Uygulanan: `products` (PanoPOS hazır kayıt), `customers`, `licenses` (`created_by`, `revoked_*`, `active_key`,
`source`, `installer_username`, `installed_by`, `approved_by/at`, durum `demo|active|revoked`), `devices`,
`audit_logs`, `users.username`. 🔜 Kalan: `packages`, `modules`, `license_modules`.

```
customers (firma) 1──* licenses *──1 products
                         │  └──* license_modules *──1 modules (*──1 products)
                         │  └── package_id → packages (ürün bazlı şablon: modül seti + varsayılan cihaz limiti)
                         └──* devices (aktivasyon)
users 1──* user_permissions          audit_logs (kim, ne, hangi kayıt, önce/sonra, IP, zaman)
```
- **licenses:** `customer_id`, `product_id`, `package_id`, `device_limit` (satın alınan adet),
  `starts_at`, `expires_at`, `status (active|suspended|revoked)`, `license_key` (unique),
  `revoked_at`, `revoked_by`, `revoke_reason`.
  Bir firma + ürün için tek aktif lisans: MySQL'de kısmi index olmadığından
  `active_key = IF(status='active', CONCAT(customer_id,'-',product_id), NULL)` üretilmiş sütunu üzerinde unique index.
- **devices:** `license_id`, `fingerprint_hash` (SHA-256, ham değer saklanmaz), `name`, `status (active|released)`,
  `first_seen_at`, `last_seen_at`, `released_at`. Unique (`license_id`, `fingerprint_hash`) → aynı cihazın
  tekrar aktivasyonu yalnız kayıtlı anahtarın yeni sahiplik kanıtıyla ek kota tüketmez; public_key ve public_key_hash cihaz anahtarını bağlar.
- **Paket değişse verilmiş lisans değişmez:** lisans oluşturulurken modüller `license_modules`'a kopyalanır.
- **Geçmiş:** süre uzatma, modül değişikliği, iptal, cihaz serbest bırakma `audit_logs`'a yazılır.

## 6. Eşzamanlılık ve performans
- ✅ **Aktivasyon / kota:** tek transaction içinde `SELECT ... FROM licenses WHERE license_key=? FOR UPDATE`
  → aktif cihaz sayımı → gerekiyorsa insert. İki cihaz aynı anda son kotayı alamaz.
  Aynı fingerprint + kayıtlı anahtar + yeni sahiplik kanıtıyla tekrar idempotenttir; serbest cihaz yeniden kota tüketir.
- ✅ **Demo tekilliği:** demo kaydı ürün satırı kilitlenerek yapılır; aynı parmak izi için ikinci demo açılmaz.
- ✅ **Kontrol ucu:** challenge atomik tüketilir; cihaz okuması kilitsiz, lisans anahtarı (unique) + `devices(license_id, fingerprint_hash)` indeksi.
  `last_seen_at` her istekte değil, en fazla saatte bir yazılır.
- ✅ Dashboard sayaçları basit `COUNT` sorgularıdır.

## 7. Offline çalışma (✅ F3b sunucu + Client mantığı · ✅ F4 saklama/ağ, yerel)
1. Her başarılı `/client/demo|activate|check` yanıtı cihaza bağlı **imzalı lisans belgesi** içerir:
   lisans anahtarı, ürün, firma, durum, cihaz limiti, cihaz parmak izi, `expires_at`, `issued_at`,
   `valid_until = issued_at + lisansın 7/365 günlük politikası` (lisans bitişini geçmez). Biçim: [CLIENT_API](CLIENT_API.md).
2. ✅ Client `LicenseDocumentVerifier` imzayı açık anahtarla doğrular; `OfflineLicenseEvaluator` kararı verir:
   başka cihazın belgesi/serbest cihaz → geçersiz; iptal → `Revoked`; lisans bitti → `Expired`;
   `valid_until` geçti → `OfflineExpired`; aksi hâlde demo → `Demo`, aktif → çevrimiçi `Active` / çevrimdışı `OfflineGrace`.
3. ✅ Saat geri alma: şimdiki an, belgenin `issued_at`'ından veya istemcinin sakladığı "son görülen" andan
   5 dakikadan fazla geriyse yeniden çevrimiçi doğrulama istenir; "son görülen" asla geriye yazılmaz.
4. ✅ İptal/bitiş imzalı gelir. ✅ En yeni belge ve öğrenilmiş iptal F4 korumalı durumunda saklanır (§11); saf evaluator geçmiş tutmaz.
5. ✅ F4: belge, watermark ve iptal korumalı saklanır; HTTP çağrıları LicenseManager içindedir (§11). PanoPOS entegrasyonu F5.

## 8. İmza ve cihaz kimliği güvenliği
- ✅ Belge imzası ES256 (ECDSA P-256/SHA-256), DER; ham payload baytları doğrulanır.
  Sunucu imza anahtarı cihaz anahtarlarından ayrıdır. Özel zarf/JWS farkı: [CLIENT_API](CLIENT_API.md).
- ✅ Sunucu özel anahtarı public ve uygulama dışında 600 izinli dosyada; klasör 700.
  Anahtar yoksa istemci uçları 503 verir. Sunucu dışında şifreli yedek ve geri yükleme doğrulaması zorunlu;
  canlı anahtar/yedek henüz oluşturulmadı. Rotasyonda yeni kid, geçişte eski/yeni gömülü açık anahtarlar.
- ✅ Her cihazın ayrı P-256 anahtarı vardır. Sunucuda SPKI PEM ve SHA-256(SPKI DER) özeti tutulur.
  Fingerprint yardımcı sinyaldir; tek başına kimlik doğrulaması değildir.
- ✅ MySQL device_challenges: 32 rastgele bayt id, işlem/ürün/lisans/fingerprint/anahtar bağı,
  120 saniye ömür ve atomik tek kullanımlık tüketim. Aynı anda tekrarların yalnız biri geçer.
  Ek servis/cache yok. Scheduler günlük olarak bir günden eski challenge kayıtlarını temizler;
  temizlenmemiş kayıt da süre kontrolünden geçemez.
- ✅ Aktivasyon transaction + lisans satırı kilidinde kota kontrolü yapar.
  devices(license_id, public_key_hash) unique, demo araması için public_key_hash indeksli; aynı anahtar farklı fingerprint ile ek slot/ikinci demo açmaz.
  Kontrolde challenge atomik tüketilir; cihaz okuması kilitsizdir, last_seen_at en fazla saatte bir yazılır.
- ✅ Belge device_key_hash taşır. Verifier ve evaluator beklenen ürün/lisans/fingerprint/yerel anahtar özetini
  zorunlu alır. Farklı ürünün geçerli imzası yeterli değildir.
- ✅ Eski anahtarsız aktif cihazlar otomatik sahiplenilemez; kontrollü release + kanıtlı yeniden aktivasyon gerekir.
  Geçiş/anahtar kaybı: [CLIENT_API](CLIENT_API.md#eski-cihaz-kayıtlarının-geçişi).
- Migration: 2026_11_27_120000_add_device_proof; yalnız yerel test DB için uygulanır.
  Canlı migration/yayın ayrı kullanıcı onayı gerektirir.

## 9. F4 güvenlik şartları
1. **Windows CNG:** her kurulumda ayrı P-256 özel anahtarı; dosyaya export edilmez, CNG anahtar deposunda
   mümkünse TPM ile dışa aktarılamaz tutulur. Servis hesabına uygun ACL uygulanır. Kontrolde ve offline
   başlangıçta gerçek anahtar açılır/sahipliği doğrulanır; diskteki hash tek başına kanıt değildir.
   Kopyalanmış lisans/durum dosyası başka makinede çalışmamalı. TPM yoksa yazılımsal CNG'nin daha zayıf
   koruması açıkça kabul edilir; yerel admin/VM snapshot karşısında mutlak kırılmazlık vaat edilmez.
2. **Challenge HTTP akışı:** yalnız HTTPS, normal sertifika/hostname doğrulaması; mesajın purpose,
   id, işlem, ürün, lisans, fingerprint ve yerel anahtar özeti beklenen istekle eşleşmeden imzalanmaz.
   Kullanılmış/süresi dolmuş challenge yerine yeni challenge alınır. Özel anahtar gönderilmez/loglanmaz.
3. **Korumalı durum:** imzalı belge yanında en ileri görülen zaman, en yeni kabul edilmiş belge zamanı
   ve öğrenilmiş iptal tutulur; DPAPI/CNG bütünlüğü, hesap/makine bağı, ACL ve atomik yazma kullanılır.
   Dosya eksik/bozuksa offline hak yeniden başlamaz; online doğrulama gerekir.
   İmzalı belgeyi korumak, geçmiş durumun eski yedeğe dönüşünü tek başına önlemez.
4. **İptal geri alınamaz:** imzalı iptal öğrenildikten sonra eski aktif belge ticari işlemleri açamaz;
   korumalı iptal kaydı ve belge güncellik eşiği uygulanır. Sunucudan doğrulanarak öğrenilen iptal,
   ilgili lisans kimliğiyle kalıcı ve atomik saklanır; yeniden bağlantı kesilmesi, yeniden başlatma veya
   eski aktif belgenin geri yüklenmesi iptal kaydını kaldıramaz. Kalıcı yazma başarısızsa ticari işlemler
   kapalı kalır; eski olumlu belgeye fallback yapılmaz. İptal hiç öğrenilmemiş offline cihazın
   mevcut belgesi en fazla imzalı bitişine kadar geçerlidir. Tam disk geri dönüşü çevrimiçi teyitle sınırlanır.
5. **Saat:** kalıcı en ileri zaman geriye yazılmaz; oturumda monoton sayaçla geçen süre ilerletilir.
   Beş dakikalık tolerans her kontrolde yeniden süre kazandırmamalı; sabit/geri alınan saat süreyi dondurmamalı.
   Şüpheli durum online doğrulama ister; güvenilir sunucu zamanı ile kontrollü iyileşme sağlanır.
6. **Karar/fallback:** imzalı iptal ve kesin yetki/cihaz reddi bağlantı hatası sayılmaz; eski olumlu belgeye
   dönülmez. Ağ kesintisi/503 yalnız mevcut geçerli offline hakkı kullandırır; yeni offline izin süresi başlatmaz.
   Başarısız çağrı, yerel ayar veya tekrar başlatma lisans bitişini/offline sınırını uzatamaz.

7. **Kontrol zamanlayıcısı:** onaylı zamanlar ve davranışlar [LICENSING_RULES §25](LICENSING_RULES.md#i̇mzalı-lisans-ve-offline-2026-11-27)
   kaynağından uygulanır. Açılış/yerel kontrol diskten doğrulanmış belge, cihaz anahtarı, zaman ve kalıcı
   iptal durumunu değerlendirir; yerel kontrol online istek başlatmaz. Online kontrol challenge + check akışıdır.
   Periyodik online aralık offline_grace_days veya valid_until üzerinden hesaplanmaz.
   Bağlantı dönüşü periyodik kontrolü ve hata beklemesini beklemeden ilk fırsatta doğrulamayı tetikler.
   Aynı anda gelen periyot/bağlantı/tekrar tetikleri tek devam eden doğrulamada birleştirilir; paralel
   veya birikmiş gereksiz istek oluşturulmaz. Küçük jitter ağ isteklerini cihazlar arasında dağıtır;
   yerel kontrolü, ticari izin bitişini veya öğrenilmiş iptalin uygulanmasını geciktirmez.
   İlk bağlantı hatasından sonraki 15 dakikalık tekrarın ardından bekleme kademeli artar; artış dizisi,
   üst sınır ve jitter aralığı §11'dedir. Kesin lisans reddi bağlantı
   hatası gibi retry/fallback ile geçersiz kılınmaz; başarısız deneme mevcut offline izni uzatmaz.
8. **Satış ve arayüz:** ağ/disk/kriptografi işi arka planda yürütülür; UI iş parçacığı bloke edilmez.
   Satış akışı online lisans kontrolü tetiklemez veya sonucunu beklemez; güncel yerel izin kararını okur.
   Zamanlanmış arka plan kontrolü satış olsa da bağımsız çalışabilir; satışın parçası olarak ağ çağrısı yapılmaz.
   İptal veya süre sonu öğrenildiğinde yerel izin kararı güncellenir; saatlik kontrol aralığı mevcut
   imzalı bitişten sonra ek ticari hak vermez.

## 10. Lisans bazlı offline politika
- licenses.offline_grace_days: unsigned smallint, DB/model varsayılanı 7. İzinli API değerleri 7/365.
  Migration 2026_11_27_130000_add_license_offline_policy mevcut kayıtları da 7 ile doldurur.
- License.offlineGraceDays mevcut kayıttan sorgusuz hesaplanır; demo veya bilinmeyen değer 7'ye düşer.
  Eski global env ayarı otorite değildir. Device tablosuna politika eklenmedi.
- LicenseSigner imzalı payload'a offline_grace_days koyar ve valid_until değerini ticari lisans bitişiyle
  sınırlar. ES256/DER, cihaz anahtarı bağı, challenge ve kota güvenliği değişmez.
- Admin-only license.offline_policy Gate; store/approve içinde 365 seçimi ayrıca kontrol edilir.
  Politika değiştirme ucu lisans satırını kilitler; güncelleme ve audit tek transaction'dır.
- Client alanı yalnız sözleşmedir; mevcut evaluator imzalı valid_until'u kullanır, 7 günlük sabit tavan
  uygulamaz. HTTP yenileme/CNG/korumalı saklama F4 (§11); §9 şartları 365 için de uygulanır.
- **365 günlük belge cihaz online olmadıkça uzaktan iptal edilemez.** İptal veya 365→7 değişikliği
  eski belgeyi değiştirmez; yeni politika sonraki online belgede geçerlidir. En geç imzalı bitişte durur;
  ticari lisans daha erken bitiyorsa o sınır geçerlidir. Bu, özel offline politikanın kabul edilen sonucudur.
- Panel sözleşmesi ve hata/yanıtlar: [CLIENT_API](CLIENT_API.md#yönetim-apisi--offline-politika-react-uygulaması-ayrı-görev).
## 11. F4 Client uygulaması (2026-11-27, yerel)
Kod: `src/PanoLicense.Client`. Ürün bağımsız, yalnız .NET 8 yerleşik kütüphaneleri (ek NuGet yok).

| Bileşen | Görev |
|---|---|
| `LicenseManager` | Tek giriş: `StartAsync`, `RegisterDemoAsync`, `ActivateAsync`, `CheckNowAsync`, `Current`, `AllowsCommercialOperations`, `StatusChanged`. `CreateForWindows(options)` hazır kurulum. |
| `CngDeviceKeyStore` | P-256 anahtarı, `ExportPolicy=None`. Önce TPM (Microsoft Platform Crypto Provider), yoksa yazılımsal KSP. Kapsam: kullanıcı (varsayılan) veya makine. |
| `LicenseApiClient` | challenge → mesaj bağlamı kontrolü → imza → demo/activate/check. Sonuç: `Success`, `Denied` (kesin karar kodları), `Rejected` (422 vb.), `Unavailable` (ağ, zaman aşımı, 5xx/429/503, JSON olmayan yanıt). `invalid_device_proof` bir kez yeni challenge ile tekrarlanır. |
| `ProtectedStateStore` | Durum JSON'u cihaz anahtarıyla imzalanır; dosya (atomik geçici dosya + flush + taşıma) ve `HKCU/HKLM\Software\PanoLicense\{ürün}` kopyası. Okurken birleşim: iptaller birleşir, zamanlar en ileri, en yeni belge ve en yeni red. |
| `TrustedClock` | Karar zamanı = max(duvar saati, çapa + monoton süre). Çapa açılışta watermark, online başarıda imzalı `issued_at`. Açılışta saat watermark'tan 5 dk'dan fazla gerideyse `clock_rollback` → online gerekir. |
| `CheckScheduler` | Saf takvim mantığı (aşağıda). |

**Takvim:** açılışta ve saatte bir yerel kontrol (ağ yok); offline izin/lisans bitiş anında ek yerel kontrol.
Online: son başarılı kontrol + 6 sa (+ en fazla %10, üst sınır 5 dk jitter). Hata (Unavailable/Rejected): 15 dk, 30 dk,
1 sa, 2 sa, 4 sa, sonra 6 sa sabit (+ jitter). Belge yok/süre doldu/saat şüpheli: ilk fırsatta (0–30 sn jitter),
ancak hata beklemesi sürerken beklemeyi bozmaz. Bağlantı dönüşü (`NetworkChange` olayları veya `NotifyNetworkAvailable`)
önceki kontrol sonucundan bağımsız beklemeyi atlar. Aynı olay dalgası 30 saniyelik aralıkta birleştirilir;
gerçek ağ kesilmesi olayı bu birleştirme kaydını sıfırlar. Devam eden kontrol sırasında gelen tekrarlar yeni istek kuyruğu oluşturmaz. Kesin red kademeli tekrar başlatmaz, normal 6 sa takvimine döner.
`CheckNowAsync` tek uçuşludur: eşzamanlı tetikler aynı isteği paylaşır.

**Karar kuralları:**
- İmzalı `status=revoked` belge veya check'te `license_revoked` → lisans anahtarı kalıcı iptal listesine yazılır; hiçbir işlem kaldırmaz.
- check'te diğer kesin red (`device_not_activated`, `license_not_found`, `device_key_mismatch`, ...) → red kaydı; yalnız daha yeni
  imzalı belge (ör. kanıtlı yeniden aktivasyon) kaldırır. activate/demo'daki red mevcut lisansı bozmaz.
- Bağlantı hatası yalnız mevcut imzalı belgenin kalan offline hakkını kullandırır; yeni süre başlatmaz.
- Daha eski `issued_at` taşıyan belge (yanıt veya diskten) kabul edilmez (`stale_document`).
- Kalıcı yazma herhangi bir yuvada başarısızsa mevcut süreçte ticari izin kapanır (state_write_failed); sonraki
  yerel kontrolde tekrar yazılır. Başarı için tüm yuvaların yazılması gerekir; tek yuvadaki kalıcı iptal de yüklemede korunur.
- Lisanssız boş durum diske yazılmaz (geçici okunamayan kopyanın üzerine boş kayıt yazılmasın).

**Bilinen sınırlar:** yazılımsal KSP TPM kadar güçlü değildir; tam disk/VM snapshot sınırı sürer.
Kayıt tamamlanmadan çökme ve dondurulmuş saat belirsizliği için onaylı süreklilik kararı LICENSING_RULES son bölümündedir.


### F4 son düzeltmelerin uygulanışı
- Ticari izin LicenseManager'da tek mekanizmadan gelir: güncel durum, kayıt hatası/online gerekliliği,
  tespit edilmiş saat şüphesi ve imzalı bitiş birlikte kontrol edilir. Okuma yalnız bellek + zaman sayacıdır.
  LicenseStatus.State bilgi görüntüsüdür. Yöneticiye bağlı LicenseStatus.AllowsCommercialOperations,
  eski görüntüde bile aynı güncel karar fonksiyonuna yönelir; bağımsız/deserialize edilmiş görüntü izin vermez.
  Dispose sonrası tutulan eski görüntüler de izin vermez.
- Normal DisposeAsync arka plan/ağ işini tamamlayıp zaman watermark'ını bir kez arka planda kalıcı yazar;
  servis/ürün normal duruşta bu metodu await etmelidir. Satış yoluna ağ/disk eklenmez; saatlik kayıt sıklığı artırılmaz.
- Bozuk durumun geçerli aynası olsa bile online doğrulama zorunlu olur. requires_online işareti yuvalar birleştirilirken
  OR ile korunur; geçerli online belge alınıp tüm yuvalara başarıyla yazılınca temizlenir.
- Kalıcı kaydedilememiş öğrenilmiş iptal + çökme ve son kayıt sonrası dondurulmuş saat + çökme normal
  kesintiden kesin ayrılamaz. Önceden online kontrol işareti veya süreyi peşin tüketme eklenmedi;
  kullanıcı onaylı sınır ve iş kuralı: [LICENSING_RULES](LICENSING_RULES.md#f4-son-düzeltmeler--çalışma-sürekliliği-kararı-2026-11-27).
## 12. Ürün / paket / modül kataloğu (2026-11-28)
- Tablolar: `packages` (ürün, kod, ad, varsayılan cihaz limiti, sıra, aktif), `modules` (ürün, kod, ad, açıklama, aktif),
  `package_module`, `license_module` (lisansa özel ek modül; veren ve zaman). `licenses.package_id`, `products.demo_package_id`.
  Migration `2026_11_28_100000_create_packages_and_modules` PanoPOS paketlerini ekler, demo paketini Pro yapar ve mevcut lisansları Pro'ya eşler.
- `License::effectiveModules()` = paket modülleri ∪ ek modüller, aktif ve aynı ürüne ait, koda göre sıralı.
  Modüller lisansa kopyalanmaz: paket değişince tüm lisanslar sonraki belgede yeni listeyi alır.
- `LicenseSigner` payload'a `package` (kod veya null) ve `modules` (kod listesi) ekler; her belge üretiminde ilişkiler taze yüklenir.
  Ekleme geriye uyumludur (`v` 1 kalır); eski Client alanları yok sayar, yeni Client eski belgede boş liste görür.
- Yetki: katalog `product.manage`, paket değiştirme ve ek modüller yeni Admin-only `license.modules` Gate'i (User'a verilemez).
  Yeni yetki motoru veya servis yok.
- Client: `LicenseDocument.Package/Modules`, `LicenseStatus.Package/Modules`, `LicenseManager.HasModule(code)` (bellekten, birebir kod).
- Başlangıç kataloğu: `config/catalog.php` + `App\Support\CatalogSync` + `panolicense:catalog-sync [--dry-run] [--attach-missing]`.
  Yalnız eksik ürün/paket/modül eklenir; eşleşme yalnız paket veya modül o çalıştırmada oluştuysa eklenir (Admin'in
  kaldırdığı eşleşme geri gelmez). `--attach-missing` eksik varsayılan eşleşmeleri ekler, hiçbir şeyi silmez. Tek transaction,
  değişiklik varsa `catalog.sync` audit kaydı. Ortak sözleşme: [LICENSING_CONTRACT](LICENSING_CONTRACT.md).


- Ürün pasifleştirme: ClientController.deviceRules(requireActiveProduct) demo ve demo challenge için
  aktif ürün, mevcut lisansın activate/check ve challenge akışlarında yalnız kayıtlı ürün ister.
  LicenseController.store aktif ürün şartını korur. Ek tablo/sorgu/Client değişikliği yoktur.
  Lisans/kota/sahiplik kontrolleri ve paket/modül hesabı değişmez; kural LICENSING_RULES §32.