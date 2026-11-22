# Mimari

Kurallar (ne olacağı) [LICENSING_RULES.md](LICENSING_RULES.md)'dedir; bu dosya nasıl uygulanacağını anlatır.
✅ = uygulandı, 🔜 = planlı (henüz kod yok).

> 2026-11-22 karar değişikliği: ASP.NET Core + SQL Server + IIS yerine **Laravel + MySQL 8**, yayın **Linux VPS**
> (Nginx + PHP-FPM; cPanel'de SSH/PHP 8.3 olmadığı için). Kod paylaşımlı hostinge taşınabilir kalır.
> .NET yalnız istemci kütüphanesinde (PanoLicense.Client) kalır, çünkü PanoPOS .NET'tir.

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
| **Client (.NET)** | Ürün içinde: aktivasyon isteği, imzalı lisans belgesini doğrulama, offline süre, `LicenseState`. | Lisans üretmez; özel anahtar taşımaz; ürünün UI'ını bilmez. |

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
✅ Uygulanan: `products` (PanoPOS hazır kayıt), `customers`, `licenses` (`created_by`, `revoked_*`, `active_key`),
`audit_logs`, `users.username`. 🔜 Kalan: `packages`, `modules`, `license_modules`, `devices`.

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
  tekrar aktivasyonu yeni kota tüketmez.
- **Paket değişse verilmiş lisans değişmez:** lisans oluşturulurken modüller `license_modules`'a kopyalanır.
- **Geçmiş:** süre uzatma, modül değişikliği, iptal, cihaz serbest bırakma `audit_logs`'a yazılır.

## 6. Eşzamanlılık ve performans
- 🔜 **Aktivasyon / kota:** tek transaction içinde `SELECT ... FROM licenses WHERE id=? FOR UPDATE`
  → aktif cihaz sayımı → gerekiyorsa insert. İki cihaz aynı anda son kotayı alamaz.
  Aynı parmak izi tekrar gelirse mevcut kayıt döner (idempotent).
- 🔜 **Doğrulama ucu:** lisans anahtarı + parmak izi ile tek indeksli sorgu (licenses ⨝ devices).
  `last_seen_at` her istekte değil, en fazla saatte bir güncellenir (gereksiz yazma yok).
- 🔜 Dashboard sayaçları basit `COUNT` sorgularıdır; gerekirse kısa süreli `database` cache.

## 7. Offline çalışma yaklaşımı (🔜 F3–F4)
1. Client online iken sunucudan **imzalı lisans belgesi** alır: lisans id, firma, ürün, modüller,
   cihaz parmak izi hash'i, `expires_at`, `issued_at`, `valid_until_offline = issued_at + 7 gün`, `key_id`.
2. Belge yerelde saklanır; her açılışta açık anahtarla imza + parmak izi + tarih doğrulanır.
3. Sunucuya ulaşılamazsa belge `valid_until_offline`'a kadar kabul edilir → `OfflineGrace`.
4. Süre aşılırsa `Expired`; ürün ticari işlemleri durdurur.
5. Saat geri alma koruması: son görülen zaman yerelde korumalı tutulur; saat geriye giderse offline süre ilerlemiş sayılır.
6. Online olunca belge yenilenir; iptal edilen lisans en geç 7 gün içinde istemciye yansır.

## 8. İmza ve cihaz kimliği güvenliği (🔜 F3)
- **İmza asimetriktir;** özel anahtar yalnız sunucuda, `public_html` ve uygulama klasörü dışında,
  `600` izinli dosyada (`LICENSE_SIGNING_KEY_PATH`). İstemciye hiçbir uçtan gönderilmez, loglanmaz.
  Client yalnız açık anahtarı gömer; anahtar rotasyonu `key_id` ile.
- **Algoritma seçenekleri** (açık karar, [STATUS](../STATUS.md#açık-kararlar)):
  - *ECDSA P-256 / SHA-256* — PHP `openssl` ve .NET `ECDsa` yerleşik; **Client'a ek paket gerekmez** (öneri).
  - *Ed25519* — PHP `sodium` yerleşik; ancak .NET 8'de yerleşik Ed25519 yok, Client'a ek kütüphane gerekir.
- Belge kanonik JSON (sıralı alanlar) + base64url imza; imza ham baytlar üzerinde doğrulanır.
- **Cihaz kimliği:** Client birkaç donanım kaynağından (ör. anakart, disk, işletim sistemi kimliği) parmak izi
  üretir, sunucuya yalnız SHA-256 hash'i gider. Değişim toleransı açık karardır.
- **Taşıma:** yalnız HTTPS; Client uzak HTTP adresini reddeder ✅.
- **İstemci uçları:** `throttle:license-client`, istek zaman damgası + nonce ile tekrar saldırısına karşı koruma.
- **Denetim:** her yönetim işlemi ve aktivasyon `audit_logs`'a (kullanıcı, işlem, kayıt, IP, zaman).
