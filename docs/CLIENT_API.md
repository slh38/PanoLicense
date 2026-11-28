# İstemci API Sözleşmesi (PanoPOS ↔ PanoLicense)

## Güncel hedef ile mevcut uygulama ayrımı — FAZ 6.2a

Ana sözleşme [v2.3 §12](LICENSING_CONTRACT.md) iki repoda aynıdır.
**Backend yerelde uygulandı (FAZ 6.2a.2b); tüketiciler bekliyor:** süreli/demo/ömür boyu tek v2; zorunlu imzalı license_type;
term için geçerli expires_at, perpetual için açık null. Aktif v1 üretimi ve Client 1.1.0 kaynağında ticari kabul kaldırıldı; PanoPOS tüketici yükseltmesi bekliyor,
yalnız geçmiş iptal kayıtları Client 1.1.0'da dar v1 okuyucusuyla doğrulanır; ticari/modül/rapor yetkisi vermez.
Client 1.1.0 hedeflenir; Device Service ve WebApi aynı sürümü kullanacak. Null tek başına süresiz demek değildir.

Offline term/demo için min(issued_at + 7/365 gün, lisans sonu), perpetual için issued_at + 7/365 gündür.
Demo 14 günlük Pro/tek cihaz/7 gün kalır. İmza, challenge, cihaz bağı, saat, iptal ve eski belge koruması değişmez.
Yeni Client'ta genel v1/v2 ticari uyumluluk talimatı geçersizdir.
HTTP /api/v1 ve kanıt amaç metinlerindeki v1 belge sürümünden bağımsızdır; bu kararla değiştirilmez.

**Mevcut yerel Backend:** tüm lisans/iptal belgeleri tek v2; resmî Client 1.0.1 v2 desteklemez.
8b0b5cd'nin eski v1/v2 üretimi alttaki tarihsel bölümde korunur; güncel talimat değildir.
Client 1.1.0 kaynak geliştirmesi tamamlandı; resmî 1.1.0 paket üretildi; koordineli tüketici dağıtımı ve gerçek API/DB E2E kabulü bekliyor. İki geçici bayrak şimdilik kapalıdır,
koordineli dağıtım ve kabul sonrasında kaldırılacaktır. TEST kayıtları ve kalıcı güvenlik geçmişi silinmez.

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

Zarf alanları: v=2, kid, alg=ES256, payload (base64url), sig (base64url DER).
Mevcut sözleşme korunur: ECDSA P-256/SHA-256, payload'un çözülmüş ham baytları, DER imza;
bu özel zarf JWS değildir. İstemci gömülü açık anahtarı kid ile seçer.
Payload: v, **license_type (term|perpetual)**, license_key, product, customer, status, state, device_limit, fingerprint,
**device_key_hash**, device_status, expires_at, issued_at, valid_until, **offline_grace_days** (7 veya 365).

LicenseDocumentVerifier.Verify ve OfflineLicenseEvaluator.Evaluate için LicenseIdentity zorunludur:
beklenen ürün, lisans anahtarı, fingerprint ve **yerel cihaz anahtarından hesaplanan** özet.
Bağlam belge içinden kopyalanmaz. Farklı ürün/lisans/cihaz/anahtar veya eski eksik anahtar bağı reddedilir.
İmza doğrulanmadan belge değerlendirilmez. Client açılışta CNG anahtarını açar; diskteki özete tek başına güvenmez.

term/demo için valid_until = min(issued_at + lisansın offline_grace_days değeri, lisans bitişini izleyen 00:00 UTC); perpetual için valid_until = issued_at + offline_grace_days.
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

## Tarihsel Backend / v1-v2 uygulaması (FAZ 6.2a.2; 6.2a.2b ile üretim değişti)

Bu bölüm 8b0b5cd kodunu tarif eder. Önceki v2.2 kararı tarihçedir; güncel hedef v2.3 §12'dir.

### Yönetim
- POST /licenses ve /licenses/{id}/approve: isteğe bağlı license_type (term/perpetual).
  Alan yoksa geriye uyumlu term; term expires_at geçerli YYYY-MM-DD gerektirir.
  Perpetual için açık expires_at:null zorunludur; null dışı bir tarih kabul edilmez.
- Perpetual seçimi ayrıca can:license.perpetual (yalnız Admin); User'a izin kaydı eklemek yetki vermez.
- POST /licenses/{id}/convert-perpetual: { "license_type": "perpetual" }.
  Yalnız Admin, dağıtım açık, status=active ve geçerli term gerekir (süresi dolmuş term de dönüştürülebilir).
  Tarih/anahtar/paket/cihaz alanları bu uçtan düzenlenmez. Başarılı yanıt LicenseResource.
  Tekrar/ters dönüşüm, demo veya revoked: 422. Yetkisiz: 403. Audit: license.convert_perpetual,
  data.from=term, to=perpetual, previous_expires_at; kim/zaman/IP aynı transaction içinde.
- Perpetual için extend 422. Liste/detay/client durum yanıtında license_type ve nullable expires_at bulunur.
  Süresi dolacak listesi perpetual içermez; aktif sayaç/dağılım içerir.

### Belge
- Term/demo zarf ve payload v=1, tarih zorunlu; mevcut v1 alanları/üretimi korunur.
- Perpetual zarf ve payload v=2; license_type="perpetual", expires_at=null.
  v2 term de sözleşmede tanımlıdır (geçerli tarih zorunlu), fakat Backend mevcut term için v1 üretir.
- Eksik/bilinmeyen tür veya çelişkili tarih imzalanmaz. Null tarihli v1 asla üretilmez.
  ES256/P-256/SHA-256/DER, kid, ham payload baytları, challenge ve cihaz anahtar bağı aynıdır.
- v2 valid_until = issued_at + offline_grace_days; v1 min(offline sonu, ticari bitişi izleyen UTC 00:00).
  v2 zamanları UTC, altı basamak kesirli ISO 8601'dir. Aynı saniyedeki v1'den sonra sıralama için
  v2 issued_at'a 1 mikrosaniye eklenir; valid_until aynı issued_at üzerinden hesaplanır.
  Önceki çift sürüm ticari okuma talimatı geçersizdir. Yeni Client v2 zaman hassasiyetini, son belge ve kalıcı iptal korumasını koruyacaktır.
- Kontrol yanıtının snapshot/imzası lisans satır kilidi içinde alınır: eski in-flight nesne,
  dönüşüm tamamlandıktan sonra daha yeni zamanlı v1 üretemez.
- Client 1.0.1 v2'yi unsupported_version ile reddeder; tüketiciler yükseltilmeden özellik açılmaz.
  Paket bu fazda değiştirilmedi/üretilmedi. v2 reddini bağlantı hatası gibi yorumlayıp eski belgeye dönmek yasaktır.

### Varsayılan kapalı güvenli dağıtım
- LICENSE_PERPETUAL_ENABLED=false: oluşturma ve demo/term dönüşümü 422; mevcut perpetual için imzalı
  yanıt üretimi 503 signing_unavailable. Süreli v1 ve demo normal çalışır.
- Production'da yalnız enabled=true yeterli değildir: ayrıca LICENSE_PERPETUAL_PRODUCTION_APPROVED=true gerekir.
  İki değer de varsayılan false. Mevcut .env değiştirilmedi; örnek dosyada yalnız kapalı değerler bulunur.
- Ayrı yayın onayından önce: Client 1.1.0 tek v2, aktif v1 reddi, geçmiş v1 iptal okuması ve antirollback/iptal kabulü; tüm Device Service ve WebApi
  tüketicilerinin yeni Client'a geçişi; panel/Desktop null-tür ayrımı; izole migration kabulü tamamlanmalıdır.
  Bayrak kapatılması önceden verilmiş offline belgeyi geriye dönük iptal etmez.

## FAZ 6.2a.2b — güncel tek v2 üretim ve kapalı dağıtım

- Süreli/demo/perpetual ve yeni iptal yanıtları v2'dir. license_type zorunlu; term geçerli YYYY-MM-DD
  bitiş gerektirir, perpetual açık expires_at:null gerektirir; demo perpetual olamaz. Signer v1 kabul etmez.
- issued_at ve valid_until UTC ISO 8601 altı basamak kesirle yazılır; üretim zamanı için önceki +1 mikrosaniye
  kuralı sürer. Term/demo offline sınırı lisans bitişini aşmaz; perpetual yalnız 7/365 gün alır.
- Geçici LICENSE_PERPETUAL_ENABLED=false iken tüm client challenge/demo/activate/check 503
  signing_unavailable döner; anahtar yükleme ve işleme/kayıt öncesinde kapı kapanır. V1 fallback yoktur.
  Production'da enabled=true tek başına yetmez; LICENSE_PERPETUAL_PRODUCTION_APPROVED=true da gerekir.
  İki gerçek varsayılan kapalıdır ve bu görevde açılmadı; kabul sonrası kaldırılması ayrı adımdır.
- Admin oluşturma/onay/dönüşüm yetkileri değişmedi. Kapalı özellikte perpetual oluşturma/dönüşüm yine 422'dir.
  Yönetimde süreli lisans kaydı oluşturulabilir; kapalı dağıtım cihaz belgesi üretmez.
- Kaydedilmiş eski iptal/cihaz/audit ve zaman durumu değiştirilmedi. Dar v1 geçmiş iptal okuyucusu
  Client 1.1.0 görevidir; bu sunucuda aktif v1 üretimini tutmak için gerekçe değildir.
- Ortak v2.3 sözleşmesi değiştirilmedi; §12 gerçek durumundaki commit numaraları tarihli snapshot'tır.
  Bu yerel Backend ilerlemesi STATUS'tadır. Client/panel/PanoPOS entegrasyonu tamamlanmadı; canlı dağıtım kapalı.

## Client 1.1.0 — FAZ 6.2a.3 API'si (resmî paket hazır; tüketici kabulü bekliyor)

- LicenseDocumentVerifier.Verify yalnız zarf/payload v=2 kabul eder; v1 ticari yolda unsupported_version.
  Eksik/bilinmeyen/çelişkili license_type/expires_at invalid_license_term ile reddedilir.
  term geçerli YYYY-MM-DD; perpetual açık JSON null; eksik alan null sayılmaz. Demo perpetual olamaz.
- Lisans politikası yalnız 7/365, demo yalnız 7 ve tek cihazdır. valid_until issued_at + politika süresini
  veya term bitişini aşamaz. Geçersiz süre penceresi reddedilir; expired/revoked belgeler ticari izin vermez.
- LicenseDocument.LicenseType: string ("term" | "perpetual"); ExpiresAt: string?;
  LicenseEndUtc: DateTimeOffset? (perpetual null); AllowedUntil: DateTimeOffset (sonlu).
  DateTimeOffset kesirli UTC hassasiyeti doğrulama, korumalı saklama ve karşılaştırmada korunur.
- LicenseStatus.LicenseType: string?; doğrulanmış güncel tür yoksa null.
  LicenseExpiresOn hâlâ string?; yalnız doğrulanmış perpetual türüyle null ömür boyu anlamına gelir.
  Ticari izin yine LicenseManager.AllowsCommercialOperations; görüntüdeki tür/modül alanları yetki vermez.
- LicenseManager.CreateCommercialProof / CommercialProofVerifier.Verify aynı imza ve opak zarf sözleşmesini
  korur; perpetual için AllowedUntil yalnız valid_until, term için min(valid_until, LicenseEndUtc).
- CommercialProofVerifier.ReadRevocation v2 iptal ve geçmiş v1 iptali okuyabilir. V1 aktif/demo reddedilir;
  v1'e public ticari Verify üzerinden geçiş yoktur. Ürün/kimlik bağı ve sunucu imzası doğrulanır.
- Korumalı mevcut v1 iptal belgesi açılışta yalnız iptal yolundan yüklenebilir ve orijinal string olarak
  GetSignedRevocationDocument ile iletilebilir. Revoked listesi, watermark, newest_issued_at, cihaz anahtarı
  ve saklama şeması sıfırlanmaz. Eski aktif v1 yüklenirse ticari izin açılmaz, online v2 gerekir.
- Device Service tüketimi: doğrulanmış LicenseType + LicenseExpiresOn ayrımını yerel yanıta ekleme sonraki fazdır.
  WebApi tüketimi: aynı 1.1.0 paketiyle Verify/ReadRevocation; nullable LicenseEndUtc kullanan çağrı varsa
  uygun nullable denetimi gerekir. Mevcut PanoPOS kaynakları bu tur değiştirilmedi/derlenmedi.
