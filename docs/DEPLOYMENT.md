# Yayın Planı

> Bu aşamada canlı yayın, sunucu, domain, DNS veya Cloudflare ayarı **yapılmaz**. Her adım onaylıdır.

## Hedef
| Öğe | Değer |
|---|---|
| Adres | `https://lisans.jokeryazilim.com` (değiştirilebilir) |
| Sunucu | Windows + IIS (ASP.NET Core Hosting Bundle 10) |
| CDN/Proxy | Cloudflare (SSL: Full Strict) |
| Veritabanı | SQL Server Express |
| Yapı | API ve Panel aynı IIS sitesi, aynı domain |

## Tek site yapısı
1. `npm run build` → Panel `dist/` çıktısı `PanoLicense.Api/wwwroot`'a kopyalanır.
2. `dotnet publish src/PanoLicense.Api -c Release` → IIS site klasörü.
3. `/api/*` API'ye, diğer yollar `index.html`'e (SPA fallback uygulamada tanımlı).

## Domain değiştirilebilirliği
- Koda domain gömülmez. API göreli yollar kullanır; Panel `/api`'yi göreli çağırır.
- Client sunucu adresini `LicenseClientOptions.ServerUrl` ayarından okur.
- Domain değişiminde: IIS binding + sertifika + Cloudflare DNS + Client ayarı. İmzalı lisans
  belgeleri domain'e bağlı değildir.

## Cloudflare notları (planlı)
- Gerçek istemci IP'si için Forwarded Headers (yalnız Cloudflare IP aralıklarına güvenilir).
- `/api/*` cache'lenmez; Panel statik dosyaları hash'li adlarla cache'lenebilir.
