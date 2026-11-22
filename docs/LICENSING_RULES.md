# Lisanslama Kuralları (onaylı)

Bu dosya iş kurallarının tek kaynağıdır. Uygulama ayrıntısı: [ARCHITECTURE.md](ARCHITECTURE.md).
Kural değişikliği yalnız kullanıcı onayıyla yapılır ve tarihlenir.

## Onaylı kararlar (2026-11-22)
1. **Firma bazlı lisans:** lisans firmaya (müşteriye) verilir; tek tek cihaza veya kullanıcıya değil.
2. **Ürün bazlı cihaz kotası:** firmanın her ürün lisansı kendi cihaz limitini taşır
   (ör. PanoPOS için 3 cihaz). Kota doluysa yeni cihaz aktive edilemez.
3. **7 gün offline çalışma:** son başarılı sunucu doğrulamasından itibaren 7 gün boyunca
   sunucuya ulaşmadan çalışılabilir. Süre dolunca yeniden çevrimiçi doğrulama gerekir.
4. **İptal var, silme yok:** Admin lisansı iptal edebilir; lisans, müşteri ve cihaz kayıtları
   fiziksel olarak silinmez. İptal kim / ne zaman / neden bilgisiyle tutulur.
5. **Roller:** `Admin` (tam yetki) ve sınırlı yetkili `User`. User'ın kesin yetkileri açık karardır
   ([STATUS](../STATUS.md#açık-kararlar)); iptal Admin'e aittir.
6. **Süre dolunca ticari işlemler durur:** lisans süresi dolduğunda (ve iptal / offline süre aşımında)
   istemci satış, tahsilat, belge kesme gibi ticari işlemleri durdurur.
   Client karşılığı: `LicenseState.AllowsCommercialOperations()`.
7. **Kalan offline süre gösterimi** PanoPOS üzerinde ileriki entegrasyon aşamasıdır (F5).
8. **Bağımsız sunucu:** lisans sunucusu hiçbir üründen bağımsızdır; PanoPOS ve diğer ürünler
   yalnız istemcidir. Ürünler lisans kararı vermez, sunucunun imzalı kararını uygular.

## Durum sözlüğü (Client)
| Durum | Anlamı | Ticari işlem |
|---|---|---|
| `Active` | Online doğrulandı, süre içinde | Açık |
| `OfflineGrace` | Sunucuya ulaşılamıyor, 7 gün dolmadı | Açık |
| `Expired` | Lisans süresi veya offline süre doldu | Kapalı |
| `Revoked` | Admin iptal etti | Kapalı |
| `DeviceLimitExceeded` | Ürün cihaz kotası dolu | Kapalı |
| `Unknown` | Henüz doğrulanmadı / belge yok | Kapalı |
