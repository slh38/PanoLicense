# PanoLicense — Durum

Son güncelleme: 2026-11-22 · Faz: **F0b — Laravel + MySQL altyapı dönüşümü** (commit bekliyor)

## Karar değişikliği (2026-11-22)
ASP.NET Core 10 + SQL Server + Windows IIS → **Laravel 13 (PHP 8.3+) + MySQL 8 + Linux cPanel**,
domain `lisans.jokeryazilim.com.tr`. .NET yalnız istemci kütüphanesinde kalır (PanoPOS .NET olduğu için).
Eski .NET API iskeleti (`/api/health`, boş DbContext) silindi; içinde iş kuralı yoktu. Git geçmişinde `53d38d1`.

## Gerçek tamamlanma durumu

| Alan | Durum | Not |
|---|---|---|
| Repo | ✅ | `slh38/PanoLicense` private. F0 (`53d38d1`) pushlandı; Laravel dönüşümü **henüz commit edilmedi**. |
| API (Laravel) | ✅ altyapı | Laravel 13.43, Sanctum 4.5 SPA modu, `/api/v1` öneki, `GET /api/v1/health`, `GET /api/v1/me` (oturumlu), rate limit tanımları, güvenilen proxy ayarı, panel fallback'i. Lisans uçları yok. |
| Yetki | ✅ altyapı | `UserRole` (admin/user), `users.is_active`, `Permission` enum + `user_permissions`, Gate kuralları (pasif → red, Admin → tam, User → verilen). Giriş ucu yok (F2). |
| Veritabanı | ✅ altyapı | MySQL 8 migration'ları: users (+role, is_active, last_login_at), sessions, password_reset_tokens, cache, jobs, user_permissions. Lisans tabloları yok (F1). Yerel MySQL 8.4: `panolicense_dev`, `panolicense_test`. |
| Client (.NET) | ✅ iskelet | `ILicenseClient`, `LicenseState`, `LicenseClientOptions` (net8.0 + net10.0). Uygulama yok. |
| Panel | ✅ iskelet | Tasarım korunarak aynen duruyor (8 menü, mock dashboard, boş durumlar, light/dark). Vite proxy Laravel'e; `npm run build:deploy` → `Api/public/spa`. Gerçek API bağlantısı yok. |
| Testler | ✅ | PHPUnit 9 test (MySQL), Vitest 5, xUnit 10 — hepsi geçiyor. |
| Yayın yeri | 🟡 | Turhost cPanel uygun değil (SSH yok, PHP 7.4 + canlı WordPress) → mevcut Contabo VPS. Sunucu sıkılaştırıldı, PHP 8.3-FPM + MySQL 8 kuruldu; uygulama henüz kurulmadı ([DEPLOYMENT §1](docs/DEPLOYMENT.md#1-sunucu-durumu-2026-11-22)). |
| Dokümanlar | ✅ | AGENTS, STATUS, README, ARCHITECTURE, LICENSING_RULES, DEPLOYMENT, TESTING güncel. |

## Sonraki aşamalar
- **F0b kalan:** onayla commit/push → VPS'e ilk kurulum (Nginx sitesi, SSL, `/api/v1/health` + panel canlıda).
- **F1 — Veri modeli:** customers, products, packages, modules, licenses, license_modules, devices, audit_logs migration + modeller + testler.
- **F2 — Yönetim:** Sanctum giriş/çıkış, ilk Admin komutu, kullanıcı/yetki yönetimi, Panel'in API'ye bağlanması, lisans oluştur/uzat/iptal, audit.
- **F3 — Aktivasyon:** cihaz kaydı, kilitli kota kontrolü, imzalı lisans belgesi.
- **F4 — Client:** online doğrulama + 7 gün offline, imza doğrulama, saat geri alma koruması.
- **F5 — PanoPOS entegrasyonu:** ticari işlemleri durdurma, kalan offline süre gösterimi.
- **F6 — Operasyon:** otomatik yedekleme (Windows bilgisayara çekme), izleme, bekleyen sistem güncellemeleri.

## Açık kararlar
1. İmza algoritması: **ECDSA P-256** (Client'a ek paket gerekmez, öneri) veya Ed25519 (.NET 8'de ek kütüphane gerekir).
2. Cihaz parmak izi kaynakları ve donanım değişimi toleransı.
3. Cihaz limiti dolunca eski cihazı serbest bırakma kuralları (kim, ayda kaç kez).
4. Süre bitimi uyarısı başlangıcı (ör. 15 gün önce) ve yenileme akışı.
5. Paket/modül kataloğu (PanoPOS Lite/Pro/Restaurant/Plus ve ek modüller).
6. User'a varsayılan olarak hangi yetkilerin verileceği.
7. Cihaz limiti yorumu: limit **firma + ürün lisansına** bağlı kabul edildi (ör. PanoPOS için 3 cihaz). Firmanın tüm ürünleri için ortak tek havuz isteniyorsa değişir.
8. ~~cPanel uyumluluğu~~ → çözüldü: VPS'te yayın (2026-11-22).
