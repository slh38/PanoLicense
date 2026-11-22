# PanoLicense — Durum

Son güncelleme: 2026-11-22 · Faz: **F2a — Giriş, kullanıcılar ve lisans verme** (canlıda)

## Karar geçmişi (2026-11-22)
- ASP.NET Core 10 + SQL Server + Windows IIS → **Laravel 13 (PHP 8.3+) + MySQL 8**. .NET yalnız Client'ta kalır.
- Yayın: Turhost cPanel uygun değildi (SSH yok, PHP 7.4) → **Contabo VPS** (Nginx + PHP 8.3-FPM + MySQL 8).
- Demo veri kaldırıldı; panel girişle açılır. User yetkileri: yeni lisans, ±30 gün süresi dolacaklar, tüm lisanslarda arama.

## Gerçek tamamlanma durumu

| Alan | Durum | Not |
|---|---|---|
| Repo | ✅ | `slh38/PanoLicense` private. `53d38d1` F0, `a958ad6` Laravel. F2a commit edilip canlıya alındı. |
| Giriş | ✅ yerel | Kullanıcı adı + parola, Sanctum cookie oturumu, rate limit, aynı hata mesajı, çıkış. İlk Admin: `panolicense:create-admin`. |
| Kullanıcılar | ✅ yerel | Admin panelden User oluşturur, yetki kutucukları, pasife alma (silme yok), parola sıfırlama. |
| Lisans | ✅ yerel | Yeni lisans (yeni/kayıtlı firma), ±30 gün süresi dolacaklar, arama, detay ("Lisansı veren"), Admin: süre uzatma + iptal (nedenli, silme yok). Firma + ürün için tek aktif lisans (DB unique). |
| Denetim | ✅ yerel | `audit_logs`: giriş, başarısız giriş, firma/lisans/kullanıcı oluşturma, uzatma, iptal. Panelde görüntüleme ekranı henüz yok. |
| Veritabanı | ✅ | + `users.username`, `products` (PanoPOS), `customers`, `licenses`, `audit_logs`. Yok: paket/modül/cihaz. |
| Panel | ✅ yerel | Demo veri silindi. `/giris`, gerçek Dashboard (Admin/User farklı), Lisanslar, Yeni Lisans, Lisans Detay, Müşteriler, Ayarlar·Kullanıcılar. Menü yetkiye göre. Modüller/Paketler/Cihazlar/Loglar boş durum. |
| Client (.NET) | ✅ iskelet | Değişmedi. |
| Testler | ✅ | PHPUnit 34 (MySQL), Vitest 12, xUnit 10. Tarayıcıda Admin + User akışı yerelde denendi. |
| Yayın | ✅ | F2a canlıda: demo veri yok, giriş zorunlu. Admin hesabını kullanıcı `panolicense:create-admin` ile kendisi oluşturur. |

## Sonraki aşamalar
- **F2a kalan:** kullanıcının kendi Admin hesabını komutla oluşturması.
- **F2b:** Loglar ekranı, firma düzenleme, paket/modül kataloğu.
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
