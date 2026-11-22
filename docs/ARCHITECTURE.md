# Mimari

Kurallar (ne olacağı) [LICENSING_RULES.md](LICENSING_RULES.md)'dedir; bu dosya nasıl uygulanacağını anlatır.
✅ = uygulandı, 🔜 = planlı (henüz kod yok).

## 1. Bileşenler ve sorumluluk ayrımı

```
 Ürün (PanoPOS, ...)                     lisans.jokeryazilim.com (IIS, tek site)
 ┌──────────────────────┐   HTTPS   ┌─────────────────────────────────────────┐
 │ PanoLicense.Client   │──────────▶│ PanoLicense.Api   /api/*                 │
 │ (DLL, imza doğrular, │           │  ├─ yönetim uçları (Panel, oturumlu)     │
 │  offline önbellek)   │           │  ├─ istemci uçları (aktivasyon/doğrulama)│
 └──────────────────────┘           │  └─ wwwroot: Panel build (SPA fallback)  │
                                    │            │ EF Core                     │
 Tarayıcı (Admin/User) ───────────▶ │            ▼                             │
   PanoLicense.Panel (React)        │      SQL Server Express                  │
                                    └─────────────────────────────────────────┘
```

| Proje | Sorumluluk | Yapmaz |
|---|---|---|
| **Api** | Tek otorite: lisans kararı, cihaz kotası, imzalama, yetki, denetim logu. | Ürün iş kuralı bilmez. |
| **Panel** | Admin/User yönetim arayüzü; yalnız Api'yi çağırır. | İş kuralı hesaplamaz, DB'ye bağlanmaz. |
| **Client** | Ürün içinde: aktivasyon isteği, imzalı lisansı doğrulama, offline süre takibi, `LicenseState` üretimi. | Lisans üretmez; özel anahtar taşımaz; ürünün UI'ını bilmez. |

- ✅ Api Minimal API ile başlar; uç sayısı büyüdükçe özellik klasörleri (`Features/Licenses/...`) eklenir.
  Ayrı Domain/Application/Infrastructure projeleri ihtiyaç kanıtlanmadan açılmaz.
- ✅ Api, Panel build çıktısını `wwwroot`'tan sunar; `/api` dışı yollar `index.html`'e düşer.
- ✅ Client `net8.0;net10.0` hedefler; PanoPOS (.NET 8) doğrudan referans alabilir. Dış bağımlılığı yoktur.

## 2. SQL Server bağlantı mimarisi
- ✅ Tek `PanoLicenseDbContext`, bağlantı dizesi adı `ConnectionStrings:PanoLicense`. F0'da varlık yok;
  bağlantı ilk kullanımda açıldığı için API SQL Server olmadan da ayağa kalkar.
- ✅ Development: `appsettings.Development.json` → `localhost\SQLEXPRESS`, `PanoLicense_Dev`, Windows auth.
- 🔜 Production: bağlantı dizesi repoda değil; IIS ortam değişkeni (`ConnectionStrings__PanoLicense`)
  veya repoya girmeyen `appsettings.Production.json`. Uygulama havuzu kimliği veya ayrı SQL kullanıcısı,
  en az yetki (okuma/yazma; migration ayrı yetkiyle).
- 🔜 Şema yalnız EF Core migration ile değişir; migration'lar SQL script olarak üretilip onayla uygulanır.
  Canlı DB'ye uygulama başlangıcında otomatik migration **yapılmaz**.
- 🔜 Fiziksel silme yok: `Status` + `RevokedAt/RevokedBy` alanları; tüm değişiklikler `AuditLog`'a.

## 3. Veri modeli: Firma + Ürün + Cihaz limiti (🔜 F1)

```
Customer (Firma) 1──* License *──1 Product
                       │  └──* LicenseModule *──1 Module
                       │  └── Package (opsiyonel şablon: modül seti + varsayılan kota)
                       └──* Device (Activation)
User (Admin|User) ──* AuditLog
```
- **License** = bir firmanın bir ürün için hakkı: `CustomerId`, `ProductId`, `MaxDevices`,
  `StartsAt`, `ExpiresAt`, `Status (Active|Suspended|Revoked)`, `LicenseKey`.
  Bir firma + ürün için aynı anda tek aktif lisans (unique filtered index).
- **Device** = lisansa bağlı aktivasyon: `FingerprintHash`, `Name`, `FirstSeenAt`, `LastSeenAt`, `Status`.
  Aktif cihaz sayısı ≤ `MaxDevices`; kontrol transaction içinde yapılır (yarış koşulu yok).
- **Package/Module** ürün bazlıdır; lisans oluşturulurken paket şablon olur, sonra lisans kendi
  modül listesini taşır (paket değişse de verilmiş lisans değişmez).

## 4. Offline çalışma yaklaşımı (🔜 F3–F4)
1. Client online iken sunucudan **imzalı lisans belgesi** alır: firma, ürün, modüller, cihaz parmak izi,
   `ExpiresAt`, `IssuedAt`, `ValidUntilOffline = IssuedAt + 7 gün`.
2. Belge yerelde saklanır; her açılışta açık anahtarla imza + parmak izi + tarih doğrulanır.
3. Sunucuya ulaşılamazsa son geçerli belge `ValidUntilOffline`'a kadar kabul edilir → `OfflineGrace`.
4. Süre aşılırsa `Expired`; ürün ticari işlemleri durdurur.
5. Saat geri alma koruması: son görülen zaman yerelde korumalı tutulur; saat geriye giderse
   offline süre ilerlemiş sayılır.
6. Online olunca belge yenilenir; iptal edilmiş lisans en geç 7 gün içinde istemciye yansır.

## 5. Güvenlik ve lisans doğrulama (🔜)
- **İmza:** asimetrik (öneri Ed25519 / ECDSA P-256). Özel anahtar yalnız sunucuda (ACL'li dosya veya
  Windows sertifika deposu); Client yalnız açık anahtarı gömer. Anahtar rotasyonu için `KeyId`.
- **Taşıma:** yalnız HTTPS (Cloudflare Full-Strict + sunucu sertifikası). Client uzak HTTP adresi reddeder ✅.
- **Panel kimliği:** aynı domain olduğundan HttpOnly + Secure + SameSite cookie oturumu; parola hash
  (ASP.NET Identity PasswordHasher), giriş denemesi sınırlama, Admin/User rol kontrolü sunucuda.
- **İstemci uçları:** lisans anahtarı + cihaz parmak izi; ASP.NET yerleşik rate limiting; tekrar
  saldırısına karşı nonce/zaman damgası.
- **Denetim:** her yönetim işlemi ve aktivasyon `AuditLog`'a (kim, ne, ne zaman, IP).
- **Gizlilik:** parmak izi ham değil hash olarak saklanır; geliştirmede gerçek müşteri verisi kullanılmaz.
