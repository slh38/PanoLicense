# PanoLicense

Joker Yazılım ürünleri için bağımsız lisans yönetim sistemi. Firma bazlı lisans, ürün bazlı
cihaz kotası ve 7 gün offline çalışma destekleyecek. İlk istemci PanoPOS'tur; sistem diğer
yazılımları da yönetecek şekilde ürün bağımsız tasarlanır.

> Durum: F0 iskelet. Gerçek lisans, giriş ve veritabanı tabloları henüz yok — bkz. [STATUS.md](STATUS.md).

## Teknoloji
| Katman | Teknoloji |
|---|---|
| API | ASP.NET Core 10 Web API (Minimal API) |
| Veri | SQL Server (Express) + EF Core 10 |
| Panel | React 19 + TypeScript + Vite + Tailwind CSS 4 + shadcn/ui yaklaşımı |
| Client | .NET kütüphanesi (net8.0 + net10.0) |
| Test | xUnit, Vitest |

## Gereksinimler
- .NET SDK 10.0.404+ (`global.json`)
- Node.js 22+ / npm 10+
- SQL Server Express (F1'den itibaren; F0'da gerekmez)

## Yerel geliştirme
```powershell
dotnet build PanoLicense.sln
```
```powershell
dotnet test PanoLicense.sln
```
```powershell
dotnet run --project src/PanoLicense.Api   # http://localhost:5080/api/health
```
```powershell
cd src/PanoLicense.Panel; npm install; npm run dev   # http://localhost:5173
```
```powershell
cd src/PanoLicense.Panel; npm run build; npm test
```
Panel dev sunucusu `/api` isteklerini `http://localhost:5080`'e proxyler.

## Klasör yapısı
```
PanoLicense/
├─ src/
│  ├─ PanoLicense.Api/      Lisans sunucusu (API + yayında Panel'i wwwroot'tan sunar)
│  ├─ PanoLicense.Panel/    React yönetim paneli
│  └─ PanoLicense.Client/   Ürünlerin kullanacağı lisans doğrulama kütüphanesi
├─ tests/PanoLicense.Tests/ xUnit testleri
├─ docs/                    Mimari, kurallar, tasarım, yayın, test
├─ AGENTS.md  STATUS.md  README.md  global.json  PanoLicense.sln
```

## Dokümanlar
[AGENTS](AGENTS.md) · [ARCHITECTURE](docs/ARCHITECTURE.md) · [LICENSING_RULES](docs/LICENSING_RULES.md) ·
[UI_DESIGN](docs/UI_DESIGN.md) · [DEPLOYMENT](docs/DEPLOYMENT.md) · [TESTING](docs/TESTING.md) · [WORKFLOW](docs/WORKFLOW.md)
