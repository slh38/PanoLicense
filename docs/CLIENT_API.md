# İstemci API Sözleşmesi (PanoPOS ↔ PanoLicense)

PanoPOS ve diğer ürünlerin lisans sunucusuyla konuşma biçimi. Uygulama ayrıntısı:
[ARCHITECTURE](ARCHITECTURE.md), kurallar: [LICENSING_RULES](LICENSING_RULES.md).
Durum: **F3a** — imzasız durum yanıtı. F3b'de yanıtlara imzalı lisans belgesi eklenecek; alanlar geriye uyumlu kalır.

## Genel
- Taban adres: `https://lisans.jokeryazilim.com.tr/api/v1/client` (adres istemcide ayardan okunur).
- `Content-Type: application/json`, `Accept: application/json`. Oturum/token yok.
- `product_code`: ürün kodu (`PANOPOS`).
- `device.fingerprint`: istemcinin donanım kimliğinden ürettiği **SHA-256, 64 karakter küçük hex**.
  Ham donanım bilgisi (seri no, MAC vb.) gönderilmez.
- Sınırlar: `/demo` IP başına dakikada 3, saatte 10; `/activate` ve `/check` IP başına dakikada 30. Aşılırsa `429`.

## Ortak başarılı yanıt
```json
{
  "license_key": "PAN-D027-88D4-BAF0",
  "state": "demo | demo_expired | active | expiring | expired | revoked",
  "status": "demo | active | revoked",
  "expires_at": "2026-12-09",
  "device_limit": 1,
  "device_status": "active | released",
  "customer_name": "Yıldız Büfe",
  "offline_grace_days": 7,
  "server_time": "2026-11-25T14:43:04+00:00"
}
```
Ticari işlemler yalnız `state` = `demo`, `active` veya `expiring` iken açıktır.

## Hata yanıtı
```json
{ "error": "<kod>", "message": "İnsan için açıklama" }
```
| HTTP | `error` | Anlamı |
|---|---|---|
| 404 | `license_not_found` | Anahtar yok |
| 422 | `product_mismatch` | Anahtar başka ürüne ait |
| 403 | `license_revoked` | İptal edilmiş |
| 403 | `license_expired` / `demo_expired` | Süre dolmuş |
| 409 | `device_limit_exceeded` | Cihaz limiti dolu |
| 403 | `device_not_activated` | Cihaz bu lisansa kayıtlı değil veya serbest bırakılmış |
| 422 | (Laravel doğrulama: `errors` alanı) | Eksik/hatalı alan |

## `POST /demo` — kurulumda demo kaydı
```json
{
  "product_code": "PANOPOS",
  "installer_username": "i.simsek",
  "device": { "fingerprint": "<sha256>", "name": "KASA-1", "app_version": "1.4.0" },
  "customer": { "name": "Yıldız Büfe", "city": "Kayseri / Talas", "phone": "0532 000 00 00", "email": null, "tax_number": null }
}
```
- `installer_username` isteğe bağlı: personel lisans sunucusundaki kullanıcı adını yazar; boşsa kurulumu son kullanıcı yapmıştır.
- Yeni kayıt: `201`, `"created": true`, `state: "demo"`, süre **14 gün**, cihaz limiti 1.
- Aynı cihaz (aynı fingerprint) tekrar gönderirse yeni demo açılmaz: mevcut kayıt `200`, `"created": false`
  (demo bitmişse `state: "demo_expired"`). Yeniden kurulumla demo süresi sıfırlanamaz.
- İstemci `license_key` değerini saklar; sonraki kontroller bununla yapılır.

## `POST /activate` — lisansa cihaz ekleme
```json
{ "product_code": "PANOPOS", "license_key": "PAN-XXXX-XXXX-XXXX", "device": { "fingerprint": "<sha256>", "name": "KASA-2", "app_version": "1.4.0" } }
```
- Yeni cihaz: `201`. Aynı cihaz tekrar: `200`, yer tüketmez. Limit doluysa `409 device_limit_exceeded`.
- Demo lisansına ek cihaz eklenemez (demo limiti 1); onaydan sonra limit, satın alınan adettir.

## `POST /check` — durum kontrolü
Gövde `/activate` ile aynı. Kayıtlı ve aktif cihaz için güncel durumu döner (demo onaylandıysa `state: "active"`).
Sunucu `last_seen_at` alanını en fazla saatte bir günceller.

## Panel tarafı (bilgi için)
Demo talepleri **Lisanslar → Demo Talepleri** sekmesinde görünür. `license.create` yetkisi olan kullanıcı eksik
firma bilgilerini tamamlayıp cihaz limiti ve süre vererek onaylar; lisans anahtarı değişmez. İstemci bir sonraki
`/check` çağrısında aktif lisansı görür. Onaylanmayacak demo iptal edilir (kayıt silinmez).
