# PanoLicense — Durum

Son güncelleme: 2026-11-22 · Faz: **F2b — Loglar ve Türkçe mesajlar** (canlıda)

## Karar geçmişi (2026-11-22)
- İlk iskelet ASP.NET Core + SQL Server + IIS idi → **Laravel 13 (PHP 8.3+) + MySQL 8**'e geçildi. Sunucu tarafında .NET yok.
- .NET yalnız PanoPOS'un kullanacağı **Client kütüphanesinde** kalır ve PanoPOS gibi **.NET 8** hedefler.
- Yayın: Turhost cPanel uygun değildi (SSH yok, PHP 7.4) → **Contabo VPS** (Nginx + PHP 8.3-FPM + MySQL 8).
- Demo veri kaldırıldı; panel girişle açılır. User yetkileri: yeni lisans, ±30 gün süresi dolacaklar, tüm lisanslarda arama.

## Gerçek tamamlanma durumu

| Alan | Durum | Not |
|---|---|---|
| Repo | ✅ | `slh38/PanoLicense` private. F2a `ade1f74`, F2b (loglar, Türkçe mesajlar, Client .NET 8) commit edilip canlıya alındı. |
| Giriş | ✅ canlı | Kullanıcı adı + parola, Sanctum cookie oturumu, rate limit, çıkış. Admin hesabı canlıda oluşturuldu ve giriş doğrulandı. |
| Kullanıcılar | ✅ canlı | Admin panelden User oluşturur, yetki kutucukları, pasife alma (silme yok), parola sıfırlama. |
| Lisans | ✅ canlı | Yeni lisans (yeni/kayıtlı firma), ±30 gün süresi dolacaklar, arama, detay ("Lisansı veren"), Admin: süre uzatma + iptal. Firma + ürün için tek aktif lisans (DB unique). |
| Loglar | ✅ canlı | `/loglar` ekranı (işlem türü + kullanıcı filtresi, sayfalama), lisans detayında "Geçmiş". Yetki: `audit.view` (Admin; istenirse User'a verilir). |
| Türkçe mesajlar | ✅ canlı | `lang/tr/validation.php` + alan adları; admin komutu `@`/e-posta için Türkçe açıklama verir. |
| Veritabanı | ✅ | `users.username`, `products` (PanoPOS), `customers`, `licenses`, `audit_logs`. Yok: paket/modül/cihaz. |
| Panel | ✅ | `/giris`, Dashboard (Admin/User farklı), Lisanslar, Yeni Lisans, Lisans Detay, Müşteriler, Loglar, Ayarlar·Kullanıcılar. Modüller/Paketler/Cihazlar boş durum. |
| Client (.NET 8) | ✅ iskelet | Yalnız `net8.0` (PanoPOS ile aynı); SDK sürüm sabitlemesi (`global.json`) kaldırıldı. |
| Testler | ✅ | PHPUnit 40 (MySQL), Vitest 15, xUnit 10 (net8.0). |

## Sonraki aşamalar
- **F2c:** firma düzenleme, paket/modül kataloğu.
- **F3 — Aktivasyon:** cihaz kaydı, kilitli kota kontrolü, imzalı lisans belgesi.
- **F4 — Client:** online doğrulama + 7 gün offline, imza doğrulama, saat geri alma koruması.
- **F5 — PanoPOS entegrasyonu:** ticari işlemleri durdurma, kalan offline süre gösterimi.
- **F6 — Operasyon:** otomatik yedekleme (Windows bilgisayara çekme), izleme, bekleyen sistem güncellemeleri.

## Açık kararlar
1. İmza algoritması: **ECDSA P-256** (Client'a ek paket gerekmez, öneri) veya Ed25519 (.NET 8'de ek kütüphane gerekir).
2. Cihaz parmak izi kaynakları ve donanım değişimi toleransı.
3. Cihaz limiti dolunca eski cihazı serbest bırakma kuralları (kim, ayda kaç kez).
4. Paket/modül kataloğu (PanoPOS Lite/Pro/Restaurant/Plus ve ek modüller).
5. Cihaz limiti yorumu: limit **firma + ürün lisansına** bağlı kabul edildi (ör. PanoPOS için 3 cihaz).
6. Süresi dolan lisans için User yeni lisans veremez (aktif kayıt var); yenileme Admin'in süre uzatmasıyla. Farklı istenirse değişir.
