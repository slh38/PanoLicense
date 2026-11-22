# PanoLicense — Durum

Son güncelleme: 2026-11-22 · Faz: **F0 — Proje iskeleti** (tamamlandı)

## Gerçek tamamlanma durumu

| Alan | Durum | Not |
|---|---|---|
| Repo | ✅ | `slh38/PanoLicense` private, `origin` bağlı, F0 ilk commit `main`'e pushlandı. |
| Solution | ✅ | `PanoLicense.sln`, .NET SDK 10.0.404 (`global.json`) |
| API | ✅ iskelet | `/api/health`, boş `PanoLicenseDbContext`, statik Panel sunumu + SPA fallback. Tablo/migration yok. |
| Client | ✅ iskelet | `ILicenseClient`, `LicenseState`, `LicenseClientOptions` (net8.0 + net10.0). Uygulama yok. |
| Tests | ✅ | xUnit: Client ayar doğrulama + ticari işlem kuralı (10 test). Vitest: tema + yüzde hesabı (5 test). |
| Panel | ✅ iskelet | Sol menü + üst bar, 8 menü, mock dashboard, boş durum sayfaları, light/dark, responsive. Tarayıcıda 1440 px ve 375 px kontrol edildi. Gerçek API bağlantısı yok. |
| Dokümanlar | ✅ | AGENTS, STATUS, README, docs/* |

## Sonraki aşamalar
- **F0:** tamamlandı.
- **F1 — Veri modeli:** Firma, Ürün, Paket, Modül, Lisans, Cihaz, Kullanıcı, Denetim logu; ilk migration (onaylı).
- **F2 — Yönetim:** gerçek giriş (Admin/User), Panel CRUD (silme yerine iptal/pasif), loglar.
- **F3 — Aktivasyon:** cihaz kaydı, ürün bazlı cihaz kotası, imzalı lisans dosyası.
- **F4 — Client:** online doğrulama + 7 gün offline, imza doğrulama, saat geri alma koruması.
- **F5 — PanoPOS entegrasyonu:** ticari işlemleri durdurma, kalan offline süre gösterimi.
- **F6 — Yayın:** IIS + Cloudflare + SQL Server Express, `lisans.jokeryazilim.com` (onaylı).

## Açık kararlar
1. İmza algoritması (öneri: Ed25519 veya ECDSA P-256) ve anahtar saklama yeri.
2. Cihaz parmak izi kaynakları (anakart/disk/MAC) ve değişim toleransı.
3. Cihaz kotası aşımında eski cihazı panelden serbest bırakma akışı.
4. Süre bitiminde uyarı başlangıcı (ör. 15 gün önce) ve ödeme sonrası yenileme akışı.
5. Paket/modül kataloğu (PanoPOS Lite/Pro/Restaurant/Plus ve ek modüller) bu sistemde mi tanımlanacak.
6. User rolünün kesin yetkileri (öneri: görüntüleme + lisans uzatma talebi; iptal yok).
7. Panel kimlik doğrulama yöntemi (cookie oturum önerilir; aynı domain).
