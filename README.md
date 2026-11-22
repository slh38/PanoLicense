# PanoLicense

Joker Yazılım ürünleri için bağımsız lisans yönetim sistemi: firma + ürün bazlı lisans,
satın alınan cihaz limiti, 7 gün offline çalışma ve birden fazla yazılım desteği.
İlk istemci PanoPOS'tur.

> Durum: F0 altyapı (Laravel + MySQL'e geçildi). Lisans tabloları, giriş ve aktivasyon henüz yok — bkz. [STATUS.md](STATUS.md).

## Teknoloji
| Katman | Teknoloji |
|---|---|
| API | PHP 8.3+, Laravel 13, Laravel Sanctum (SPA cookie oturumu) |
| Veritabanı | MySQL 8 (utf8mb4) |
| Panel | React 19 + TypeScript + Vite 8 + Tailwind CSS 4 + shadcn/ui yaklaşımı |
| Client | .NET 8 kütüphanesi (PanoPOS için) |
| Test | PHPUnit (Laravel), Vitest, xUnit (Client) |
| Yayın | Linux VPS (Nginx + PHP 8.3-FPM + MySQL 8), `https://lisans.jokeryazilim.com.tr` |

## Gereksinimler (yerel)
- PHP 8.3+ (eklentiler: ctype, curl, fileinfo, mbstring, openssl, pdo_mysql, sodium, tokenizer, xml, zip, intl)
- Composer 2
- MySQL 8 (yerelde `panolicense_dev` ve `panolicense_test` veritabanları)
- Node.js 22+ / npm 10+
- .NET SDK 8 veya üstü (yalnız PanoPOS Client kütüphanesi ve testleri için)

## Yerel geliştirme
Yerel MySQL'i başlat (Windows servisi değil, isteğe bağlı başlatılır):
```powershell
powershell -File tools/start-dev-mysql.ps1
```
API (ilk kurulumda `composer install`, `.env.example` → `.env`, `php artisan key:generate`):
```powershell
cd src/PanoLicense.Api; php artisan migrate; php artisan serve --port=8000
```
```powershell
cd src/PanoLicense.Api; php artisan test
```
Panel (`/api` ve `/sanctum` istekleri `127.0.0.1:8000`'e proxylenir):
```powershell
cd src/PanoLicense.Panel; npm install; npm run dev
```
```powershell
cd src/PanoLicense.Panel; npm run build; npm test
```
Panelin Laravel üzerinden aynı domain'de denenmesi (çıktı `src/PanoLicense.Api/public/spa`):
```powershell
cd src/PanoLicense.Panel; npm run build:deploy
```
Client:
```powershell
dotnet build PanoLicense.sln; dotnet test PanoLicense.sln
```

## Klasör yapısı
```
PanoLicense/
├─ src/
│  ├─ PanoLicense.Api/      Laravel API (/api/v1) + yayında paneli public/spa'dan sunar
│  ├─ PanoLicense.Panel/    React yönetim paneli (ayrı geliştirilir)
│  └─ PanoLicense.Client/   Ürünlerin kullanacağı .NET lisans doğrulama kütüphanesi
├─ tests/PanoLicense.Tests/ Client xUnit testleri
├─ tools/                   Yerel geliştirme yardımcıları
├─ docs/                    Mimari, kurallar, tasarım, yayın, test, iş akışı
├─ AGENTS.md  STATUS.md  README.md  PanoLicense.sln
```

## Dokümanlar
[AGENTS](AGENTS.md) · [ARCHITECTURE](docs/ARCHITECTURE.md) · [LICENSING_RULES](docs/LICENSING_RULES.md) ·
[UI_DESIGN](docs/UI_DESIGN.md) · [DEPLOYMENT](docs/DEPLOYMENT.md) · [TESTING](docs/TESTING.md) · [WORKFLOW](docs/WORKFLOW.md)
