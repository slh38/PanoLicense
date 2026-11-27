# Yayın Planı (Linux VPS)

> Her canlı adım kullanıcı onayıyla yapılır. Aynı sunucuda **Joker QR Menü** canlı çalışır;
> onun dosyalarına, Nginx site dosyasına, PM2 sürecine ve sertifikasına dokunulmaz.

## Hedef
| Öğe | Değer |
|---|---|
| Adres | `https://lisans.jokeryazilim.com.tr` (değiştirilebilir) |
| Sunucu | Contabo VPS `185.214.135.49`, Ubuntu 24.04, 4 vCPU / 8 GB RAM / 2 GB swap |
| Web | Nginx 1.24 (mevcut) + **PHP 8.3-FPM** |
| Veritabanı | **MySQL 8.0**, yalnız `127.0.0.1`, DB/kullanıcı `panolicense` |
| SSL | Let's Encrypt (certbot), `lisans` için ayrı sertifika |
| DNS | `jokeryazilim.com.tr` DNS'i Turhost cPanel'de; yalnız `lisans` A kaydı VPS'e |

> Karar geçmişi: önce Windows IIS + SQL Server, sonra Turhost cPanel düşünüldü. cPanel'de SSH yok ve
> hesap PHP 7.4'te (canlı eski WordPress) olduğu için 2026-11-22'de mevcut VPS seçildi.

## 1. Sunucu durumu (2026-11-22)
| Adım | Durum |
|---|---|
| Contabo snapshot "Yedek" (22.11.2026 21:17, 22.12.2026'da otomatik silinir) | ✅ |
| QR Menü uploads/.env/nginx yedeği bu bilgisayara (`Belgeler\Yedekler\jokerqrmenu\2026-11-22`) | ✅ |
| SSH yalnız anahtar (`/etc/ssh/sshd_config.d/01-hardening.conf`) | ✅ |
| fail2ban sshd jail (`/etc/fail2ban/jail.local`) | ✅ |
| ufw: yalnız 22/80/443 (Node 5000 dışarıya kapandı) | ✅ |
| 2 GB swap, `vm.swappiness=10` | ✅ |
| PHP 8.3-FPM + eklentiler (sodium dahil), Composer 2 (SHA-256 doğrulandı) | ✅ |
| MySQL 8.0, yalnız localhost; DB bilgisi `/root/.panolicense-db` (600) | ✅ |
| `panolicense` sistem kullanıcısı, GitHub read-only deploy key, kod `/opt/panolicense/repo` (`a958ad6`) | ✅ |
| Üretim `.env` (600), ayrı PHP-FPM havuzu (`/run/php/panolicense.sock`, open_basedir) | ✅ |
| Nginx sitesi `panolicense` (gizli dosyalar 404, yalnız `index.php` çalışır) | ✅ |
| DNS A kaydı (Turhost) + Let's Encrypt (20.02.2027'ye kadar, otomatik yenileme provası başarılı), HTTP→HTTPS | ✅ |
| Migration (öncesinde yedek `/var/backups/panolicense/`) + config/route/view cache | ✅ |
| Scheduler cron (`panolicense` kullanıcısı) | ✅ |
| Canlı kontrol: `/api/v1/health` 200, `/api/v1/me` 401, panel sayfaları 200, `/.env` 404, QR Menü 200 | ✅ |
| 72 bekleyen sistem güncellemesi | ⏳ ayrıca, olası reboot nedeniyle gece |
| F3b imza anahtarı (canlı) | ⏳ yayın onayında: aşağıdaki "İmza anahtarı" adımları |

## İmza anahtarı (F3b yayınında bir kez)
Bu adım yapılmadan F3b kodu yayınlanırsa istemci uçları `503 signing_unavailable` döner (panel etkilenmez).
1. `.env`: `LICENSE_SIGNING_KEY_PATH=/etc/panolicense/keys/signing-k1.pem`, `LICENSE_SIGNING_KEY_ID=k1`
2. `sudo -u panolicense php artisan panolicense:signing-key` → anahtar oluşur (600), açık anahtar yazdırılır.
   Açık anahtar Client/PanoPOS'a gömülür (kid `k1`). PHP-FPM `open_basedir` zaten `/etc/panolicense` içerir.
3. **Yedek:** özel anahtar sunucu dışında şifreli saklanır (kaybolursa dağıtılmış istemciler doğrulayamaz).
4. `php artisan optimize` (config önbelleği yeni yolu alsın).
| F2a yayını `ade1f74` (2026-11-22): yedek → migration → panel; giriş zorunlu, demo veri yok, cookie secure+httponly | ✅ |

## Admin hesabı (canlı)
Parolayı yalnız kullanıcı yazar; hiçbir yere kaydedilmez. Kullanıcının kendi terminalinde:
```
ssh -t root@185.214.135.49 "cd /var/www/panolicense && sudo -u panolicense php artisan panolicense:create-admin"
```
Aynı komut mevcut Admin'in parolasını sıfırlamak için de kullanılır.

## Güncelleme yayını (her sürüm)
1. Yerelde: testler yeşil, `npm run build:deploy`, commit + push (onaylı).
2. Sunucu: `mysqldump` yedeği → `sudo -u panolicense git pull` → `composer install --no-dev -o`
   → `php artisan migrate --force` → `php artisan optimize` → panel build'i `public/spa`'ya kopyalanır
   → `systemctl reload php8.3-fpm`.

## 2. Dizin ve kullanıcı düzeni
```
/opt/panolicense/repo/         git checkout (tüm repo, deploy key ile)
/var/www/panolicense/          → /opt/panolicense/repo/src/PanoLicense.Api (sembolik bağ)
/var/backups/panolicense/      migration öncesi DB yedekleri (700)
/var/www/panolicense/public    Nginx root (yalnız bu dizin web'e açık)
/var/www/panolicense/public/spa  React panel build'i
/etc/panolicense/keys/         İmza anahtarı signing-k1.pem (ES256) — uygulama dışında, klasör 700, dosya 600
```
- Uygulama `panolicense` Linux kullanıcısıyla, kendi PHP-FPM havuzunda (`/run/php/panolicense.sock`) çalışır;
  QR Menü (root/PM2) ile süreç ve dosya ayrılır.
- `storage/` ve `bootstrap/cache/` yalnız `panolicense` tarafından yazılabilir.
- Kod GitHub'dan **salt okunur deploy key** ile çekilir.

## 3. Nginx site dosyası (`/etc/nginx/sites-available/panolicense`)
- `server_name lisans.jokeryazilim.com.tr;` `root /var/www/panolicense/public;`
- `location / { try_files $uri $uri/ /index.php?$query_string; }`
- `location ~ \.php$` → `fastcgi_pass unix:/run/php/panolicense.sock;`
- `location /spa/assets/` uzun süreli cache; gizli dosyalar (`/\.`) reddedilir.
- Değişiklik: `nginx -t` → `systemctl reload nginx` (açık bağlantılar kopmaz). `qrmenu` dosyasına dokunulmaz.

## 4. Yayın adımları
1. Yerelde: `npm run build:deploy` (panel → `public/spa`), build sunucuya kopyalanır.
2. Sunucuda: `git pull`, `composer install --no-dev --optimize-autoloader`.
3. `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://lisans.jokeryazilim.com.tr`,
   DB bilgileri `/root/.panolicense-db`'den, `SESSION_SECURE_COOKIE=true`,
   `SANCTUM_STATEFUL_DOMAINS=lisans.jokeryazilim.com.tr`, `TRUSTED_PROXIES=` (Cloudflare yok).
4. Yedek (`mysqldump`) → `php artisan migrate --force` → `php artisan config:cache route:cache view:cache`.
5. Cron (`panolicense` kullanıcısı): `* * * * * cd /var/www/panolicense && php artisan schedule:run >> /dev/null 2>&1`.

## 5. DNS (kullanıcı, Turhost cPanel → Zone Editor)
```
lisans.jokeryazilim.com.tr.   A   185.214.135.49
```
WordPress sitesi etkilenmez. Kayıt yayıldıktan sonra `certbot --nginx -d lisans.jokeryazilim.com.tr`.

## 6. Domain değiştirilebilirliği ve taşıma
- Koda domain gömülmez: panel `/api/v1`'i göreli çağırır, Laravel `APP_URL`'den okur,
  Client sunucu adresini `LicenseClientOptions.ServerUrl` ayarından alır.
- Başka sunucuya taşıma: `mysqldump` → yükleme, kod + `.env` + imza anahtarı kopyası, DNS A kaydını değiştirme.
  İstemciler domain'le bağlandığı için değişiklik gerekmez; 7 gün offline hakkı kesintiyi gizler.
- **İmza anahtarı kaybolursa** verilmiş lisanslar doğrulanamaz → sunucu dışında şifreli yedeği tutulur.

## 7. Yedekleme (planlı)
Günlük `mysqldump` + `.env` + imza anahtarı (+ QR Menü `uploads`) yedeği; 7/24 çalışan Windows
bilgisayar SSH ile çeker. Ayrıntı yedekleme görevinde belirlenecek.


## F3b cihaz sahipliği geçişi (henüz canlıda uygulanmadı)
- Yeni migration: 2026_11_27_120000_add_device_proof. Bu görev yalnız yerel panolicense_test ortamını kapsar.
- Canlıya geçiş ayrı onayla; eski Client'lar challenge/cihaz anahtarı gönderemediğinden artık çalışmaz.
  F4 Client hazır (yerel); PanoPOS entegrasyonu (F5) ve kontrollü cihaz geçişi hazır olmadan yayın yapılmaz.
- Eski anahtarsız aktif kayda otomatik anahtar atanmaz; yetkili gerçek cihazı doğrulayıp release eder,
  yeni cihaz anahtarıyla kanıtlı activate aynı satırı kota içinde bağlar. Ayrıntı: [CLIENT_API](CLIENT_API.md).
- Laravel scheduler günlük süresi dolmuş challenge temizliğini çalıştırır; ek servis/dependency yoktur.

## Lisans bazlı offline politika migration'ı (henüz canlıda uygulanmadı)
2026_11_27_130000_add_license_offline_policy yalnız yerel test DB'de uygulandı.
Mevcut kayıtlara varsayılan 7 gün ekler; global LICENSE_OFFLINE_GRACE_DAYS artık kullanılmaz.
Canlı migration/yayın ayrı onay gerektirir. Politika ve 365 günlük uzaktan iptal sınırı:
[LICENSING_RULES §22](LICENSING_RULES.md#i̇mzalı-lisans-ve-offline-2026-11-27).
## Ürün/paket/modül migration'ı (henüz canlıda uygulanmadı)
2026_11_28_100000_create_packages_and_modules: yalnız yerel dev/test DB'de uygulandı. Canlıda mevcut tüm PanoPOS lisansları
Pro'ya eşlenir ve demo paketi Pro olur. Önce yedek alınır; F3b migration'larıyla birlikte ayrı onayla yayınlanır.

## FAZ 2 canlı yayın planı (hazır, onay bekliyor — uygulanmadı)
Kapsam: F3b (cihaz kanıtı, imzalı belge, 7/365) + panel 7/365 + katalog (ürün/paket/modül). Canlı: kod `9df87c8`,
son migration `2026_11_25_100002`, lisans 0, cihaz 0 (2026-11-28 salt okunur kontrol). Eski istemci yok; geçiş riski düşük.

**Bekleyen migration'lar (bu sırayla, `migrate --force` otomatik sıralar):**
1. `2026_11_27_120000_add_device_proof` — devices: public_key/hash + unique; `device_challenges`
2. `2026_11_27_130000_add_license_offline_policy` — licenses.offline_grace_days (varsayılan 7)
3. `2026_11_28_100000_create_packages_and_modules` — packages/modules/eşleşmeler/license_module; PanoPOS 4 paket, demo Pro

Ardından `panolicense:catalog-sync` (16 modül + matris). Yerel deneme modülleri/lisansları canlıya taşınmaz:
katalog yalnız `config/catalog.php`'den gelir, veri taşıması yapılmaz.

**Ön koşullar:** yerelde hedefli testler yeşil, `npm run build:deploy`, onaylı commit + push; kullanıcı onayı.

**Adımlar (sunucu, root):**
1. Kayıt: `git -C /opt/panolicense/repo rev-parse HEAD` (geri dönüş için, beklenen `9df87c8`).
2. Yedek: `mysqldump` → `/var/backups/panolicense/pre-faz2-<tarih>.sql.gz` (700); `public/spa` →
   `/var/backups/panolicense/spa-pre-faz2.tgz`; `.env` kopyası (600). Yedeğin açılabildiği `gunzip -t` ile kontrol.
3. `sudo -u panolicense php artisan down` (yalnız lisans sitesi; QR Menü ayrı Nginx sitesi + PM2, etkilenmez).
4. `sudo -u panolicense git pull` → `composer install --no-dev -o`.
5. İmza anahtarı (bir kez): `install -d -o panolicense -g panolicense -m 700 /etc/panolicense/keys`;
   `.env`: `LICENSE_SIGNING_KEY_PATH=/etc/panolicense/keys/signing-k1.pem`, `LICENSE_SIGNING_KEY_ID=k1`;
   `sudo -u panolicense php artisan panolicense:signing-key` (var olan anahtarın üzerine yazmaz; dosya 600).
   Yazdırılan **açık** anahtar FAZ 3'te Device Service'e gömülür.
6. `php artisan migrate --force` → `php artisan panolicense:catalog-sync --dry-run` (çıktı kontrolü) →
   `php artisan panolicense:catalog-sync`.
7. Panel build'i `public/spa`'ya kopyalanır → `php artisan optimize` → `systemctl reload php8.3-fpm`
   (QR Menü PHP kullanmaz) → `php artisan up`.
8. **Özel anahtar yedeği (kullanıcı):** anahtar sunucu dışına şifreli arşivle (ör. 7-Zip AES-256) alınır; parola ve
   anahtar sohbet, MD, Git veya loga yazılmaz. Anahtar kaybı = dağıtılmış istemciler doğrulayamaz.

**Duman testi:** `/api/v1/health` 200; `/api/v1/me` 401; panel `/` ve `/urunler` 200; `/.env` 404; Admin girişi →
Ürünler'de 4 paket ve 16 modül; `/client/challenge` geçersiz gövdeyle 422; QR Menü ana sayfası 200 (önceki kontrolle aynı).
İmzalı belge uçtan uca: FAZ 3'te gerçek Device Service ile (canlıda deneme lisansı oluşturulmaz).

**Geri dönüş (hata olursa):** `php artisan down` → `git checkout <kaydedilen commit>` → `composer install --no-dev -o`
→ veritabanını `pre-faz2` dump'ından geri yükle (migrate:rollback yerine; lisans sayısı 0 olduğundan veri kaybı yok)
→ `spa-pre-faz2.tgz` geri aç → `.env` yedeğini geri koy → `php artisan optimize` → `systemctl reload php8.3-fpm` → `up`.
İmza anahtarı silinmez (zararsız; sonraki denemede aynı `k1` kullanılır).
