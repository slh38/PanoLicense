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
| 72 bekleyen sistem güncellemesi | ⏳ ayrıca, olası reboot nedeniyle gece |
| Uygulama kurulumu, Nginx sitesi, SSL, cron | ⏳ |

## 2. Dizin ve kullanıcı düzeni
```
/var/www/panolicense/          Laravel (git checkout: src/PanoLicense.Api içeriği)
/var/www/panolicense/public    Nginx root (yalnız bu dizin web'e açık)
/var/www/panolicense/public/spa  React panel build'i
/etc/panolicense/keys/         İmza anahtarı (F3) — uygulama dışında, 700/600
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
