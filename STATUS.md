# PanoLicense — Durum

Son güncelleme: 2026-11-25 · Faz: **F3a — PanoPOS demo kaydı, cihaz aktivasyonu ve limit** (canlıda)

## Karar geçmişi
- 2026-11-22: İlk iskelet ASP.NET Core + SQL Server + IIS idi → **Laravel 13 (PHP 8.3+) + MySQL 8**'e geçildi. Sunucu tarafında .NET yok.
- .NET yalnız PanoPOS'un kullanacağı **Client kütüphanesinde** kalır ve PanoPOS gibi **.NET 8** hedefler.
- Yayın: Turhost cPanel uygun değildi (SSH yok, PHP 7.4) → **Contabo VPS** (Nginx + PHP 8.3-FPM + MySQL 8).
- Demo veri kaldırıldı; panel girişle açılır. User yetkileri: yeni lisans, ±30 gün süresi dolacaklar, tüm lisanslarda arama.
- 2026-11-25: PanoPOS kurulumunda **14 günlük demo** sunucuya kaydolur; personel kendi panel kullanıcı adını yazar
  (isteğe bağlı, yoksa son kullanıcı). Demo onayı `license.create` yetkisiyle. PanoPOS tarafı bu repo kapsamında değil.

## Gerçek tamamlanma durumu

| Alan | Durum | Not |
|---|---|---|
| Repo | ✅ | `slh38/PanoLicense` private. F3a commit edilip canlıya alındı (migration öncesi DB yedeği alındı). |
| Giriş / kullanıcılar / loglar | ✅ canlı | Kullanıcı adı + parola, yetki kutucukları, pasifleştirme, `/loglar`, Türkçe mesajlar. |
| Lisans (panel) | ✅ canlı | Yeni lisans, ±30 gün süresi dolacaklar, arama, detay, Admin: süre uzatma + iptal. |
| Demo kaydı | ✅ canlı | `POST /client/demo`: 14 gün, cihaz limiti 1, kurulumu yapan (kullanıcı adı eşleşmesi), cihaz başına tek demo. |
| Demo onayı | ✅ canlı | Lisanslar → Demo Talepleri; eksik firma bilgisi + cihaz limiti + süre → aktif. Anahtar değişmez. "Kurulumu yapan" ve "Lisansı veren" ayrı. Reddetme = iptal. |
| Cihazlar | ✅ canlı | `devices` tablosu, `POST /client/activate` (kilitli limit kontrolü), `POST /client/check`, panelde cihaz listesi + serbest bırakma, Dashboard aktif cihaz sayısı. |
| İmza / offline belge | ❌ | F3b: algoritma kararı, anahtar üretimi, imzalı belge. Yanıtlar şimdilik imzasız. |
| Client (.NET 8) | ✅ iskelet | Değişmedi; F4'te sunucu uçlarına bağlanacak. |
| Testler | ✅ | PHPUnit 56 (MySQL), Vitest 15, xUnit 10. Demo → onay → kontrol akışı yerelde tarayıcı + API ile denendi. |

## Sonraki aşamalar
- **F3b — İmzalı lisans belgesi:** algoritma (öneri ECDSA P-256), anahtar çifti (sunucuda `/etc/panolicense/keys`), belge biçimi, `/client/*` yanıtına eklenmesi.
- **F4 — Client (.NET 8):** demo/aktivasyon/kontrol çağrıları, imza doğrulama, 7 gün offline, saat geri alma koruması.
- **F5 — PanoPOS entegrasyonu** (PanoPOS reposu, ayrı görev ve onay).
- **F2c:** firma düzenleme, paket/modül kataloğu.
- **F6 — Operasyon:** otomatik yedekleme, izleme, bekleyen sistem güncellemeleri.

## Açık kararlar
1. İmza algoritması: **ECDSA P-256** (Client'a ek paket gerekmez, öneri) veya Ed25519 (.NET 8'de ek kütüphane gerekir).
2. Cihaz parmak izi kaynakları ve donanım değişimi toleransı (PanoPOS/Client tarafı).
3. Cihaz serbest bırakma sınırı (ör. ayda kaç kez) gerekiyor mu?
4. Paket/modül kataloğu (PanoPOS Lite/Pro/Restaurant/Plus ve ek modüller).
5. Süresi dolan lisans için User yeni lisans veremez (aktif kayıt var); yenileme Admin'in süre uzatmasıyla.
6. Demo kurulumunda KVKK bilgilendirme metni (PanoPOS tarafı).
