# Lisanslama Kuralları (onaylı)

Bu dosya iş kurallarının tek kaynağıdır. Uygulama ayrıntısı: [ARCHITECTURE.md](ARCHITECTURE.md).
Kural değişikliği yalnız kullanıcı onayıyla yapılır ve tarihlenir.

## Onaylı kararlar
_İlk kayıt 2026-11-22; Laravel geçişinde (2026-11-22) yeniden teyit edildi._

1. **Firma + ürün bazlı lisans:** lisans bir firmaya, belirli bir ürün (yazılım) için verilir;
   tek tek cihaza veya kullanıcıya değil. Bir firma + ürün için aynı anda tek aktif lisans vardır.
2. **Satın alınan cihaz limiti:** firma, ürün lisansı için belirli sayıda cihaz satın alır
   (ör. Ada Market — PanoPOS — 3 cihaz). Limit dolunca yeni cihaz aktive edilemez; Admin veya
   yetkili User eski cihazı serbest bırakarak yer açabilir. Aynı cihazın tekrar aktivasyonu kota tüketmez.
3. **Birden fazla yazılım:** sistem ürün bağımsızdır; PanoPOS ilk üründür, yeni ürün eklemek kod değil kayıt işidir.
   Paket ve modüller ürüne bağlıdır.
4. **Admin yetkileri:** lisans oluşturabilir, süresini uzatabilir, modüllerini değiştirebilir, iptal edebilir;
   kullanıcıları, ürünleri ve ayarları yönetir.
5. **User yetkileri:** yalnız Admin'in kendisine tek tek verdiği yetkilerle işlem yapar
   (ör. lisans görüntüleme, süre uzatma). Kullanıcı/ürün/ayar yönetimi User'a verilemez.
   Pasif kullanıcı hiçbir işlem yapamaz.
6. **İptal var, silme yok:** lisans, müşteri, cihaz ve kullanıcı kayıtları fiziksel silinmez.
   İptal ve pasifleştirme kim / ne zaman / neden bilgisiyle tutulur; tüm değişikliklerin geçmişi korunur.
7. **7 gün offline çalışma:** son başarılı sunucu doğrulamasından itibaren 7 gün boyunca
   sunucuya ulaşmadan çalışılabilir. Süre dolunca yeniden çevrimiçi doğrulama gerekir.
8. **Süre dolunca ticari işlemler durur:** lisans süresi dolduğunda (ve iptal / offline süre aşımında)
   istemci satış, tahsilat, belge kesme gibi ticari işlemleri durdurur.
   Client karşılığı: `LicenseState.AllowsCommercialOperations()`.
9. **Bağımsız sunucu:** lisans sunucusu hiçbir üründen bağımsızdır; ürünler yalnız istemcidir ve
   sunucunun imzalı kararını uygular. Özel imza anahtarı hiçbir zaman istemciye gönderilmez.
10. **PanoPOS entegrasyonu** (kalan offline süre gösterimi dahil) sonraki aşamadır.

## Durum sözlüğü (Client)
| Durum | Anlamı | Ticari işlem |
|---|---|---|
| `Active` | Online doğrulandı, süre içinde | Açık |
| `OfflineGrace` | Sunucuya ulaşılamıyor, 7 gün dolmadı | Açık |
| `Expired` | Lisans süresi veya offline süre doldu | Kapalı |
| `Revoked` | Admin iptal etti | Kapalı |
| `DeviceLimitExceeded` | Satın alınan cihaz limiti dolu | Kapalı |
| `Unknown` | Henüz doğrulanmadı / belge yok | Kapalı |
