# İstemci API Sözleşmesi (PanoPOS ↔ PanoLicense)

Durum: **F3b güvenlik düzeltmesi** — cihaz sahiplik kanıtı + anahtara bağlı imzalı belge.
HTTP istemcisi, Windows CNG anahtarı ve korumalı yerel durum F4 ile `LicenseManager` içinde uygulandı (yerel, yayın yok).
Kurallar: [LICENSING_RULES](LICENSING_RULES.md); uygulama: [ARCHITECTURE](ARCHITECTURE.md).

## Genel
- Taban: https://lisans.jokeryazilim.com.tr/api/v1/client; yalnız HTTPS, JSON istek/yanıt.
- Panel oturumu kullanılmaz; cihaz P-256 özel anahtarının sahipliğini kanıtlar.
- İlk aktivasyonda lisans anahtarı kayıt yetkisidir: gizli tutulur. Boş kota varsa yeni anahtar yeni cihaz sayılır.
  Lisans anahtarını bilen kişi mevcut cihazın anahtarını değiştiremez veya fingerprint ile kotayı atlayamaz.
- product_code: ör. PANOPOS. license_key: sunucuda trim + büyük harfe normalize edilir.
- device.fingerprint: SHA-256, 64 küçük hex; yardımcı donanım sinyali, kimlik doğrulaması değildir.
- device.public_key: ECDSA P-256 SPKI PEM (BEGIN PUBLIC KEY, en fazla 1024 karakter).
  Kimlik: device_key_hash = lowercase_hex(SHA256(SPKI DER)); PEM boşlukları kimliği değiştirmez.
- /challenge, /activate, /check: ortak IP limiti 30/dk. /demo: IP başına 3/dk ve 10/saat.
  Rate limit kimlik doğrulaması değildir.

## POST /challenge
İstek alanları: operation, product_code, license_key, device.fingerprint, device.public_key.
- operation: demo, activate, check. Demo için henüz lisans anahtarı yoktur; bağlamdaki değer boş metindir.
- Yanıt: challenge_id (32 rastgele baytın hex'i), message (JSON metni), expires_at (UTC).
- Mesaj; purpose=panolicense-device-proof-v1, challenge id, işlem, ürün, lisans anahtarı,
  fingerprint, cihaz anahtarı özeti ve bitiş anını içerir.
- Geçerlilik **120 saniye**; bitiş anında reddedilir. Challenge almak cihaz oluşturmaz veya kota ayırmaz.
- İstemci mesajın bağlamını kendi isteğiyle karşılaştırır, sonra **message metninin aynen UTF-8 baytlarını**
  cihaz özel anahtarıyla ECDSA P-256/SHA-256 imzalar. JSON'u yeniden serialize etmez.
- İmza DER, taşıma **standart base64**. Client saf helper: DeviceProof.Sign; ağ/saklama içermez.

## POST /demo, /activate, /check
Her çağrı önce kendine ait yeni challenge alır; aynı ürün/lisans/cihaz ve açık anahtarla gönderir:
- product_code, license_key (demo için yok), device.fingerprint, device.public_key.
- device.name ve device.app_version isteğe bağlı.
- proof.challenge_id ve proof.signature (base64 DER) zorunlu.

Sunucu bağlamı, süreyi ve imzayı doğrular; atomik koşullu UPDATE ile challenge'ı tek kez tüketir.
Doğrulama sonrası kota/iş kuralı reddi veya kesinti olsa bile aynı challenge tekrar kullanılamaz.
Yeniden denemede yeni challenge alınır. Kayıtlı cihazda anahtar özeti de kayıtla eşleşmelidir.

- /demo: customer.name zorunlu, city/phone/email/tax_number isteğe bağlı.
  installer_username isteğe bağlı, doğrulanmış personel kimliği değildir.
  Yeni demo 201 (created:true), aynı kayıt/anahtar 200 (created:false); 14 günlük süre sıfırlanmaz.
  Aynı fingerprint farklı anahtarla veya aynı anahtar farklı fingerprint ile demo alamaz.
- /activate: yeni cihaz 201, aynı fingerprint **ve anahtarla** güvenli tekrar 200, ek kota tüketmez.
  Serbest cihazın tekrar aktivasyonu kota tüketir. Bağlı anahtar otomatik değiştirilemez.
- /check: yalnız kayıtlı aktif cihaz + kayıtlı anahtar. İptal/bitiş de imzalı belgeyle 200 döner.
  last_seen_at en fazla saatte bir yazılır; challenge kaydı her çağrıda tüketilir.

### Eski cihaz kayıtlarının geçişi
Migration eski cihazlara anahtar atamaz. Anahtarı olmayan aktif kayıt demo/activate/check ile sahiplenilemez:
403 device_reenrollment_required. Yetkili kişi cihazı panelden serbest bıraktıktan sonra yeni anahtarla
kanıtlı activate, aynı satırı kota kontrolü altında yeniden bağlar. Bu geçiş otomatik değildir;
yetkili kişi gerçek cihazı doğrulamalı ve aktivasyonu koordine etmelidir.
Anahtara bağlı kayıtta release anahtarı sıfırlamaz. Anahtar kaybında otomatik fingerprint fallback yoktur;
yetkili eski cihazı bırakır, yeni fingerprint/kurulum yeni cihaz olarak aktive edilir.
Eski, device_key_hash taşımayan belgeler güncel Client tarafından reddedilir.

## İmzalı lisans belgesi
Başarılı demo/activate/check yanıtları; license_key, state, status, expires_at, device_limit,
device_status, customer_name, offline_grace_days, server_time, license_document taşır.
Offline izin yalnız doğrulanmış belgeye göre verilir; diğer alanlar bilgi amaçlıdır.

Zarf alanları: v=1, kid, alg=ES256, payload (base64url), sig (base64url DER).
Mevcut sözleşme korunur: ECDSA P-256/SHA-256, payload'un çözülmüş ham baytları, DER imza;
bu özel zarf JWS değildir. İstemci gömülü açık anahtarı kid ile seçer.
Payload: v, license_key, product, customer, status, state, device_limit, fingerprint,
**device_key_hash**, device_status, expires_at, issued_at, valid_until, **offline_grace_days** (7 veya 365).

LicenseDocumentVerifier.Verify ve OfflineLicenseEvaluator.Evaluate için LicenseIdentity zorunludur:
beklenen ürün, lisans anahtarı, fingerprint ve **yerel cihaz anahtarından hesaplanan** özet.
Bağlam belge içinden kopyalanmaz. Farklı ürün/lisans/cihaz/anahtar veya eski eksik anahtar bağı reddedilir.
İmza doğrulanmadan belge değerlendirilmez. Client açılışta CNG anahtarını açar; diskteki özete tek başına güvenmez.

valid_until = min(issued_at + lisansın offline_grace_days değeri, lisans bitişini izleyen 00:00 UTC).
Politika lisans bazındadır; tüm cihazlara aynı 7/365 değeri uygulanır. Demo için daima 7'dir.
İlk aktivasyon ve izin yenilemesi online challenge + sahiplik kanıtıyla yapılır. Yerel saat/ayar/yeniden
başlatma süreyi yenileyemez. Client OfflineGraceDays alanını okur (eski imzalı belgede yoksa 7);
izin kararı yine imzalı valid_until ve lisans bitişine dayanır, yerel ayara dayanmaz.
İstemci offline_grace_days seçemez; client uçlarında bu üst seviye istek alanı 422 ile reddedilir.
**365 günlük izin, cihaz online olana kadar uzaktan iptal edilemez.** Lisans iptali veya 365→7 değişikliği
eski imzalı belgenin bitişini geriye dönük kısaltmaz; cihaz en fazla belge/lisans bitişine kadar çalışabilir.
Saat issued_at veya korunan en ileri zamandan 5 dakikadan fazla geriyse online kontrol gerekir.
F4 Client zamanlaması, retry/jitter, satışın ağ çağrısından bağımsızlığı ve kalıcı iptal için tek kural kaynağı:
[LICENSING_RULES §25](LICENSING_RULES.md#i̇mzalı-lisans-ve-offline-2026-11-27).
Zamanlayıcı F4 Client içinde uygulandı (ARCHITECTURE §11); API sözleşmesine yeni alan/uç eklenmedi.
F4 zorunlulukları: [ARCHITECTURE §9](ARCHITECTURE.md#9-f4-güvenlik-şartları).

## Hatalar
Yanıt error ve Türkçe message taşır; 422 alan doğrulaması Laravel errors biçimindedir.

| HTTP | Kod | Anlam |
|---|---|---|
| 403 | invalid_device_proof | Challenge yok, kullanılmış, süresi dolmuş, bağlam veya imza yanlış |
| 422 | invalid_device_key | Açık anahtar P-256 değil/geçersiz |
| 403 | device_key_mismatch | Kayıtlı cihaz/anahtar eşleşmiyor |
| 403 | device_reenrollment_required | Eski aktif kaydı yetkili serbest bırakmalı |
| 404 | license_not_found | Lisans yok |
| 422 | product_mismatch | Lisans başka ürüne ait |
| 403 | license_revoked | Aktivasyon: iptal edilmiş |
| 403 | license_expired / demo_expired | Aktivasyon: süre dolmuş |
| 409 | device_limit_exceeded | Kota dolu |
| 403 | device_not_activated | Kontrol: cihaz yok/serbest |
| 503 | signing_unavailable | İmzasız belge verilmez; yalnız mevcut geçerli offline hak değerlendirilebilir |

## Yönetim API'si — Offline politika (panel kullanımı: UI_DESIGN "Offline çalışma politikası")
- Lisans liste/detay/oluşturma/onay yanıtında data.offline_grace_days sayısal 7 veya 365 bulunur.
- POST /api/v1/licenses: offline_grace_days isteğe bağlı, yoksa 7. Değer 365 ise ayrıca
  license.offline_policy Admin-only Gate kontrolü gerekir; standart 7 için license.create yeterlidir.
- POST /api/v1/licenses/{id}/approve: aynı isteğe bağlı alan; önce ticari lisansa dönüştürülür.
  365'i yalnız Admin seçebilir; onaylanmamış demo belgesi hiçbir zaman özel offline hak taşımaz.
- POST /api/v1/licenses/{id}/offline-policy: gövde { "offline_grace_days": 7 } veya 365.
  Yalnız aktif ticari lisans, oturum + can:license.offline_policy (yalnız Admin). Demo/iptal kaydı 422,
  yetkisiz/pasif kullanıcı 403, oturumsuz 401. Başarılı yanıt güncel LicenseResource'tur.
- 7/365 dışı veya null değer 422. Aynı değer tekrar gönderilirse değişiklik ve ek log oluşmaz.
- Gerçek değişim license.offline_policy_change audit kaydıdır: data.from/data.to, user_id, subject_id,
  created_at, ip_address. 365 ile oluşturma/onay da 7→365 olarak loglanır.
- Yetki User'a atanabilen Permission enum'una eklenmedi; Admin-only Gate'tir.
- Süre uzatma gibi başka uçlardaki ek alanlar offline politikayı değiştiremez.
- Eski global LICENSE_OFFLINE_GRACE_DAYS ayarı kullanılmaz; otorite lisans kaydıdır.
- Politika değişimi mevcut imzalı belgeleri yenilemez; sonraki online activate/check yeni belgeyi üretir.
## .NET 8 ürünlerinde kullanım (F4)
```csharp
var license = LicenseManager.CreateForWindows(new LicenseClientOptions
{
    ServerUrl = new Uri("https://lisans.jokeryazilim.com.tr"),
    ProductCode = "PANOPOS",
    SigningPublicKeys = new Dictionary<string, string> { ["k1"] = EmbeddedPublicKeyPem },
    AppVersion = "1.2.3",
});
await license.StartAsync();            // arka planda; ağ beklemez
license.StatusChanged += (_, s) => { /* UI iş parçacığına aktar */ };

// Kurulum: await license.RegisterDemoAsync(new DemoRegistration("Firma", InstallerUsername: "i.simsek"));
//          veya await license.ActivateAsync(key);  sonuç: Success / Denied / Rejected / Unavailable
// Satış: if (!license.AllowsCommercialOperations) { ... }   // ağ/disk yok, anlık
```
- `Current` kalan offline süre (`CommercialAllowedUntil`), politika (7/365), son/sonraki online kontrolü gösterir.
- `CheckNowAsync` yalnız kullanıcı "şimdi doğrula" dediğinde; satış akışından çağrılmaz.
- Servis hesabıyla çalışan ürünler `KeyScope = Machine` ve uygun ACL'li `StateDirectory` kullanır.


### Ticari izin ve normal kapanış
- Ticari işlem güncel LicenseManager.AllowsCommercialOperations sonucunu kullanır. Yönetici tarafından
  verilen Current/StatusChanged/işlem sonucu görüntüsündeki AllowsCommercialOperations aynı canlı karara
  yönelir; eski görüntüyü tutmak izin süresini uzatmaz. State enum'u ve serialize edilmiş görüntü izin otoritesi değildir.
- Normal kapanışta await license.DisposeAsync() çağrılır; zaman bilgisi arka planda bir kez kaydedilir.
- Normal çökme geçerli offline lisansı otomatik kilitlemez. Kalıcı kayıt hatasında mevcut süreç kapalıdır.
  Kayıt tamamlanmadan çökme/saat dondurma belirsizliğinin onaylı sınırı: [LICENSING_RULES](LICENSING_RULES.md#f4-son-düzeltmeler--çalışma-sürekliliği-kararı-2026-11-27).
## Paket ve modüller (2026-11-28)
- İmzalı payload'a eklendi: `package` (ör. "PRO", paketsiz eski kayıtta null) ve `modules` (geçerli modül kodları,
  sıralı: paket modülleri + lisansa özel ek modüller, yalnız aktif). İmza bu alanları da kapsar; istek sözleşmesi değişmedi.
- Değişiklikler cihaza sonraki başarılı online check/activate ile gelir; bağlantı hatasında son imzalı liste kullanılır.
- Client: `license.HasModule("variant")` ağ/disk kullanmaz; lisans ticari işleme kapalıysa false döner. Kod büyük/küçük harf duyarlıdır.

### Yönetim API'si (panel)
| Uç | Yetki | Açıklama |
|---|---|---|
| `GET /products` | oturum | Aktif ürünler + aktif paketler + paket modülleri (lisans formu) |
| `GET /catalog` | Admin (`product.manage`) | Tüm katalog (pasifler dahil); paketlerde `module_ids` |
| `POST /catalog/products`, `PATCH /catalog/products/{id}` | Admin | code + name + key_prefix; PATCH: name, is_active, demo_package_id (kod değişmez) |
| `POST /catalog/products/{id}/packages`, `PATCH /catalog/packages/{id}` | Admin | code, name, default_device_limit, sort_order, is_active, module_ids (aynı ürün) |
| `POST /catalog/products/{id}/modules`, `PATCH /catalog/modules/{id}` | Admin | code (küçük harf), name, description, is_active |
| `POST /licenses` | `license.create` | `package_id` zorunlu (aynı ürün, aktif) |
| `POST /licenses/{id}/approve` | `license.create` | `package_id` isteğe bağlı (yoksa demonun paketi) |
| `POST /licenses/{id}/package` | Admin (`license.modules`) | `{package_id}`; cihaz limiti ve ek modüller değişmez; `license.package_change` logu |
| `PUT /licenses/{id}/modules` | Admin (`license.modules`) | `{module_ids: []}` tam liste; eklenen/kaldırılan `license.modules_change` logu |

Lisans detayında `package`, `modules` (`source`: package/license) ve `addon_module_ids` döner. İptal edilmiş lisansta değişiklik 422.


### Pasif ürün ile mevcut lisans
ClientController ürünün varlığını tüm işlemlerde doğrular; aktiflik yalnız demo isteğinde ve
operation=demo challenge'ında aranır (pasif üründe product_code için 422).
operation=activate/check challenge'ları ile activate/check mevcut lisanslarda pasif ürün için de çalışır.
Yeni yönetim lisansı oluşturma aktif ürün gerektirir. İmzalı 7/365 günlük izin ve mevcut paket/modüller
aynı kurallarla yenilenir; ürün pasifliği imzalı lisans iptali üretmez.
İş kuralı: [LICENSING_RULES §32](LICENSING_RULES.md#ürün-paket-ve-modül-2026-11-28-onay).
## Ürün sunucusu için ticari yetki kanıtı (Client 1.0.1)
PanoLicense sunucusuna yeni uç eklenmedi; bu bölüm Client'ın ürün içi (ör. PanoPOS Device Service → WebApi) kanıtıdır.
- **Üretim (cihaz):** `LicenseManager.CreateCommercialProof(challenge)` → `CommercialProofResult`. Belge, açık anahtar ve imza
  tek kilit altında, o anki taze yerel karardan alınır. Ticari işlem kapalıysa (iptal, lisans/offline bitişi, saat şüphesi,
  kesin red, kalıcı yazma hatası, online doğrulama gereksinimi, belge yok) kanıt üretilmez, `Reason` döner. Özel anahtar verilmez.
- **Mesaj:** `panolicense-commercial-proof-v1\n{product}\n{license_key}\n{device_key_hash}\n{sha256(zarf metni)}\n{challenge}`;
  imza ECDSA P-256/SHA-256 DER, standart base64. Challenge 16–256 karakter `[A-Za-z0-9._:-]`.
  Amaç satırı sunucu cihaz kanıtından (`panolicense-device-proof-v1`) farklıdır.
- **Zarf opak taşınır:** kanıt zarf metninin özetine bağlıdır; zarf JSON nesnesi olarak yeniden serialize edilmez, metin alanı olarak taşınır.
- **Doğrulama (ürün sunucusu):** `CommercialProofVerifier(kid→PEM, product).Verify(challenge, zarf, açık anahtar, imza, güvenilir şimdi)`:
  challenge biçimi, P-256 anahtar, zarf imzası, ürün, `device_key_hash = SHA-256(SPKI)`, cihaz aktif, durum active/demo,
  `issued_at - 5 dk ≤ şimdi < min(valid_until, lisans bitişi)`, kanıt imzası. Tek kullanımlık challenge takibi doğrulayanın işidir.
  Hata kodları: `invalid_challenge`, `malformed`, `invalid_device_key`, `unknown_kid`, `bad_signature`, `identity_mismatch`,
  `device_released`, `revoked`, `unknown_status`, `document_from_future`, `license_expired`, `demo_expired`, `offline_grace_exceeded`, `bad_proof`.
- **İptal iletimi:** `GetSignedRevocationDocument()` (cihaz) → `ReadRevocation(zarf)` (sunucu). İmzalı iptal kanıt gerektirmez,
  yalnız kısıtlar; iptal belgesi `Verify` ile ticari kanıt olarak kabul edilmez.
