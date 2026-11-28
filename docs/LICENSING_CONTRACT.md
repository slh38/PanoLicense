# PanoLicense ↔ PanoPOS Ortak Lisans Sözleşmesi

Sürüm: 2.2 · Tarih: 2026-11-28 · Durum: **FAZ 1.2 kararları ürün sahibi tarafından onaylandı (§11).**
2.1: yalnız ortak faz sıralaması kesinleştirildi (§10, CTO çapraz denetimi); iş kuralı değişmedi.
Bu dosya iki repoda (`PanoLicense/docs/` ve `PanoPos/docs/`) aynı içerikle tutulur.
**2.2 geçiş notu:** FAZ 6.2a.2 kapsamında yalnız PanoLicense kopyası güncellendi; PanoPOS salt okunur
olduğundan eşitleme sonraki entegrasyon aşamasında yapılacaktır. PanoPOS halen 2.1 kullanır. PanoLicense iç kuralları: `LICENSING_RULES.md`, istemci HTTP sözleşmesi: `CLIENT_API.md`.
§6 akışı onaylı mimari yöndür; ayrıntılı tasarımı FAZ 4.1–4.2'de yapılır.

## 1. Onaylı temel ilkeler
- **Firma + ürün bazında tek lisans.** Cihaz kotası firmanın bütün şubelerinde ortaktır.
- Her **satış bilgisayarı** ayrı cihaz hakkı kullanır. Mobil garson cihazları bu kotadan düşmez.
- PanoPOS Device Service her satış bilgisayarında **zorunludur**; PanoLicense .NET 8 Client Device Service içinde çalışır.
- Cihaz anahtarı Windows CNG ile, mümkünse TPM'de, **makine kapsamında** ve dışa aktarılamaz tutulur.
- **WebApi ticari işlemlerde asıl yetki otoritesidir.** Desktop yalnız arayüz ve kullanıcı deneyimini yönetir.
- Yetki **modül koduna** göre kontrol edilir; paket adına göre karar verilmez.
- Satış sırasında internet sorgusu yapılmaz.
- 7/365 gün offline politikası ve 6 saatlik online kontrol (LICENSING_RULES §22, §25) aynen korunur.
- Lisansa özel ek modül desteği korunur.
- Demo: Pro paketi, 14 gün, tek cihaz.

## 2. Modül kodları ve paket matrisi (onaylı)
Kodlar değişmez (imzalı belgeye ve PanoPOS koduna girer). Kaynak: PanoLicense `config/catalog.php`.

| Kod | Ad | Lite | Pano | Restaurant | Pro | Geliştirme durumu |
|---|---|---|---|---|---|---|
| `quick_sale` | Hızlı Satış | ✓ | ✓ | ek | ✓ | Mevcut |
| `shift` | Vardiya | | ✓ | ✓ | ✓ | Planlanan (backend çekirdeği var, uçlar kapalı, Desktop yok) |
| `variant` | Renk / Beden | | ✓ | ek | ✓ | Kısmi (backend hazır, Desktop kısmi) |
| `multi_warehouse` | Çoklu depo ve transfer | | ✓ | ✓ | ✓ | Mevcut |
| `bank` | Banka işlemleri | | ✓ | ✓ | ✓ | Mevcut |
| `advanced_pricing` | Gelişmiş fiyat | | ✓ | ✓ | ✓ | Kısmi (toplu fiyat var; cari fiyat tipi yalnız backend) |
| `advanced_reports` | Gelişmiş raporlar | | ✓ | ✓ | ✓ | Mevcut |
| `restaurant` | Restoran / adisyon / mutfak | | | ✓ | ✓ | Kısmi (backend çekirdeği, Desktop yok) |
| `mobile_waiter` | Mobil garson | | | ✓ | ✓ | Planlanan |
| `multi_branch` | Çok şube / merkezi yönetim | | | | ✓ | Planlanan (şube altyapısı var) |
| `customer_display` | Müşteri ekranı | | | | ✓ | Planlanan |
| `erp_integration` | ERP entegrasyonu | | | | ✓ | Planlanan |
| `production` | Üretim | | | | ✓ | Planlanan |
| `fiscal_pos` | Yazarkasa POS | ek | ek | ek | ek | Planlanan |
| `e_invoice` | e-Fatura / e-Arşiv | ek | ek | ek | ek | Planlanan |
| `cloud_backup` | Bulut yedekleme | ek | ek | ek | ek | Planlanan |

- "ek" = pakete dahil değil; lisansa özel ek modül olarak verilir (ör. Restaurant müşterisine `quick_sale`, `variant`).
- Geçerli modüller = paket modülleri + lisansa özel ek modüller (yalnız aktif modüller); imzalı belgede `modules`.
- Planlanan modüller yalnız **açıklamada** "Planlanan" olarak belirtilir; ayrı DB alanı yoktur. Panelde
  tanımlanabilir ve lisansa verilebilir; PanoPOS'ta karşılığı geliştirilene kadar etkisizdir.

## 3. Temel sistem (modül kodu yok, her pakette)
Stok kartı, barkod, birim, KDV, stok sayımı ve stok fişleri; cari kart/hareket, tahsilat/ödeme; **normal kart
tahsilatı**; alış ve satış faturaları, iadeler; temel raporlar; kasa dönemi, sayım, X/Z; kullanıcı, PIN ve yetki
grupları; firma/şube/fiş ayarları; yerel yedekleme; yazıcı desteği (bilgi fişi, raf etiketi); **terazi**.

**Ortak ticari altyapı modüle bağlı değildir:** satış lookup/fiyat (`/satis`), sipariş (`/siparis`), fatura
(`/fatura`), tahsilat (`/tahsilat`) uçları temeldir. `quick_sale` yalnız `/hizli-satis/*` uçlarını ve Hızlı Satış
ekranını kapsar. Restoran ödeme akışı `Adisyon/Siparis → Fatura → Tahsilat` ortak altyapısını kullanır ve
`quick_sale` gerektirmez (§9).

## 4. Modül → PanoPOS uç eşlemesi (FAZ 4.3'te kesinleşir)
| Modül | WebApi kapsamı (mevcut uçlar) | Desktop |
|---|---|---|
| `quick_sale` | `/api/v1/hizli-satis/*` | Hızlı Satış |
| `shift` | `/api/v1/vardiya/*` | (vardiya ekranı yok) |
| `variant` | `/api/v1/renk/*`, `/api/v1/beden/*`, stok kartında varyant yazımı | Stok kartı varyant alanları |
| `multi_warehouse` | ikinci ve sonraki depo, transfer stok fişi (`/depo`, `/stok-fis`) | Şube > Depo, Stok > Transfer |
| `bank` | banka hesapları, transfer ve banka hareketleri (`/api/v1/banka/*`); **normal kart tahsilatı hariç** | Banka İşlemleri |
| `advanced_pricing` | `/stok-kart-fiyat/toplu/*`, cari fiyat tipi politikası | Stok > Fiyat Güncelleme |
| `advanced_reports` | `/rapor/karlilik`, `/rapor/stok-envanter`, `/rapor/stok-hareket-ozeti`, banka bakiyeleri | Raporlar |
| `restaurant` | `/api/v1/masa/*`, `/masa-grup/*`, `/adisyon/*` | Restaurant (ekran yok) |

## 5. Aktivasyon akışı
**Satış bilgisayarı (terminal veya satış yapan ana bilgisayar):**
1. Kurulumda Device Service kurulur; Client makine kapsamlı CNG anahtarını açar/oluşturur.
2. Kurulum ekranında (Desktop → Device Service) demo kaydı veya lisans anahtarıyla aktivasyon yapılır
   (`/client/demo|activate`, challenge + sahiplik kanıtı). Her PC kendi cihaz hakkını kullanır.
   Firmanın bütün şubeleri ve satış PC'leri aynı lisans anahtarını kullanır.
3. Device Service imzalı belgeyi korumalı saklar ve 6 saatlik/arka plan kontrollerini yürütür.
4. Demo tek cihazdır: demo ile yalnız bir satış PC'si çalışır; ek PC için demo onaylanıp cihaz limiti artırılır.

**Ana bilgisayar yalnız sunucu ise** (SQL Server + WebApi, satış yok): Device Service lisans aktivasyonu
yapmaz ve **cihaz hakkı kullanmaz**. WebApi lisans doğrulayıcıdır, lisans tüketicisi değildir.
Ana bilgisayarda satış da yapılıyorsa o da bir satış bilgisayarıdır ve bir hak kullanır.

## 6. Device Service ↔ WebApi cihaz doğrulaması
Device Service yalnız `127.0.0.1` dinler; WebApi terminale doğrudan ulaşmaz. Kanıt Desktop üzerinden taşınır,
ama Desktop'a güvenilmez: her şey imza ile doğrulanır.

1. Desktop, WebApi'den tek kullanımlık **oturum challenge'ı** alır (kısa ömürlü, tenant/şube/cihaz bağlamlı).
2. Desktop'un native (Rust) katmanı challenge'ı mevcut HMAC korumalı kanalla yerel Device Service'e iletir.
   Secret React/JS'e geçmez (mevcut Device kuralı).
3. Device Service: yerel lisans kararı ticari işleme kapalıysa kanıt üretmez ve durum kodunu döner. Açıksa
   challenge mesajını cihaz anahtarıyla imzalar; **güncel imzalı lisans belgesi + açık anahtar + imza** döner.
4. WebApi doğrular:
   - Belge imzası (gömülü PanoLicense açık anahtarı, `kid`) ve ürün `PANOPOS`;
   - `device_key_hash = SHA-256(açık anahtar SPKI)`; challenge imzası bu anahtarla geçerli ve tek kullanımlık;
   - belge `valid_until` ve lisans bitişi WebApi zamanına göre geçmemiş; durum iptal değil;
   - lisans anahtarı firmaya bağlı tek lisansla aynı.
5. Başarılıysa terminal oturumu şunlara bağlanır: lisans anahtarı, cihaz anahtar özeti, `modules`, izin bitişi
   = min(`valid_until`, lisans bitişi). Fiyat, stok, tahsilat gibi kararlar değişmez; yalnız erişim kapısıdır.

WebApi, PanoLicense sunucusuna bağlanmaz; yalnız imzalı belgeyi doğrular. Belge imzası sahte modül/izin
eklenmesini, challenge imzası başka PC'nin belgesinin kopyalanmasını engeller. Ana bilgisayar saat güvenliği
FAZ 4.2'de teknik olarak çözülür.

## 7. Oturumda bitiş, iptal ve modül değişikliği
- **Kapı:** WebApi her ticari istekte oturumun izin bitişini ve gerekli modül kodunu kontrol eder. Bellekten
  yapılır; ağ çağrısı yoktur. Modül yoksa `403 license_module_required`, izin bittiyse `403 license_refresh_required`.
- **Lisans bittiğinde / iptalde / offline izin dolduğunda:** yeni ticari işlemler kapalıdır. **Giriş, veri
  görüntüleme, raporlar ve yedekleme açıktır.**
- **Yenileme:** Device Service yeni imzalı belge aldığında (6 sa kontrol, bağlantı dönüşü, aktivasyon) mevcut
  HMAC SignalR kanalıyla Desktop'a bildirir; Desktop §6 akışıyla oturum lisansını yeniler. Yenileme yereldir,
  internet beklemez; satış yenileme sonucunu beklemez, mevcut oturum kararını kullanır.
- **İptal:** Device Service imzalı iptal öğrenince kanıt üretmeyi bırakır; Desktop imzalı iptal belgesini WebApi'ye
  iletir. WebApi imzası doğrulanmış iptali **kalıcı** kaydeder ve o lisansla açılan tüm terminal oturumlarında
  yeni ticari işlemleri kapatır.
- **Modül değişikliği:** sonraki başarılı online kontrolde gelen belgeyle oturuma uygulanır (LICENSING_RULES §30).
- **Modül kapatıldığında:** mevcut açık işlemler (ör. açık adisyon, yarım belge) güvenli şekilde tamamlanabilir;
  o modülde **yeni işlem açılamaz**. Mevcut veriler korunur ve görüntülenebilir (ör. çoklu depo kapanınca
  depolar ve stokları silinmez).
- Desktop yalnız sunucunun döndüğü modül listesine göre menüyü gizler/gösterir; yetki kararı vermez.

## 8. Windows Service hesabı ve anahtar erişimi
- Device Service mevcut `LocalService` hesabıyla çalışmaya devam eder; ayar/secret `%ProgramData%\PanoPOS\Device\v1`
  altında ACL'lidir. TPM makine anahtarının `LocalService` ile çalıştığı FAZ 4.1'de gerçek cihazda doğrulanır.
- Lisans için: Client `KeyScope = Machine`; durum dizini `%ProgramData%\PanoPOS\License` (yalnız servis hesabı
  yazar, kullanıcılar okuyamaz); ikinci kopya `HKLM\Software\PanoLicense\PANOPOS` (servis hesabına yazma izni
  kurulumda verilir).
- Makine anahtarı yönetici yetkisiyle kurulumda oluşturulur ve yalnız servis hesabına kullanım izni verilir.
  Özel anahtar dışa aktarılamaz; kullanıcı hesapları ve Desktop anahtara erişemez.
- Device Service .NET 10, Client `net8.0`: aynı süreçte kullanılabilir.

## 9. Lite'ta vardiyasız satış ve restoranın ödeme altyapısı
- **Vardiya satış için zorunlu değildir** (doğrulandı): `VardiyaController` açma/kapatma 409 döner; satış,
  fatura ve tahsilat vardiya istemez (tahsilat `VardiyaId = null`); CASH §3 kuralı da budur.
  Lite akışı: kasa dönemi → satış → sayım/Z. `shift` kapalıyken yalnız vardiya uçları/ekranı kapanır.
- **Restoran:** sipariş, fatura ve tahsilat temel uçlardır; `restaurant` modülü masa/adisyon uçlarını açar.
  Bugün ödeme ekranı Desktop'ta `features/quick-sale` içindedir; restoran ekranı yapılırken ödeme bileşeni
  ortak konuma taşınmalıdır (Desktop bağımlılığı oluşmasın). Backend tarafında `/hizli-satis` bağımlılığı yoktur.

## 10. Uygulama fazları ve kabul kriterleri
Ortak sıralama (2026-11-28, kesin). Her faz ayrı görev ve ayrı onaydır; sıra zorunludur. Uygulama ayrıntısı ve
sorumlular: PanoPOS `docs/LICENSING_INTEGRATION.md` §10.

| Faz | Kapsam | Kabul / durum |
|---|---|---|
| FAZ 0–3 | PanoLicense geliştirme (F3b, F4 Client, panel, ürün/paket/modül, §2 katalog) ve canlı yayın; imza anahtarı `k1` | **Tamamlandı** — canlı kabul 12/12 PASS |
| FAZ 4.0 | Entegrasyon rehberi (`LICENSING_INTEGRATION.md`) | **Tamamlandı** |
| FAZ 4.1 | Device Service ve Client entegrasyonu: Client paketi (1.0.1, kanıt API'si), makine anahtarı, ProgramData/HKLM durumu, HMAC uçları (durum, demo, aktivasyon, kontrol, kanıt) ve değişiklik olayı | **Tamamlandı** — kurulu serviste (LocalService) TPM/yazılım anahtarı; servis yeniden başlatma; offline/iptal kalıcılığı; kullanıcı hesabı anahtara erişemez |
| FAZ 4.2 | WebApi lisans doğrulaması: oturum challenge'ı, belge + kanıt doğrulama, firma lisans bağı, kalıcı iptal, ana bilgisayar saat güvenliği | Sahte/başka PC belgesi, eski challenge, süresi geçmiş/iptal belge reddi; saat geri alma süre kazandırmaz; satışta ağ çağrısı yok; eski terminal oturumunun güvenli yenilenmesi; Device Service erişim kaybında güvenli davranış; firma–lisans eşleştirmesi; ana bilgisayar saat güvenliği (yöntemler Codex tasarımı + onay) |
| FAZ 4.3 | WebApi modül kontrolleri (§4) ve hata kodları; lisanssız durumda okuma/rapor/yedek açık | Her modül için açık/kapalı testleri; temel uçlar modülsüz çalışır; ortak ticari uçlardan modül yetkisi atlanamaz; temel/gelişmiş rapor ayrımı; Lite vardiyasız satış; kapanan modülde açık işlem tamamlanır, yeni işlem açılmaz |
| FAZ 5 | Desktop native köprü ve kullanıcı deneyimi: challenge→kanıt→oturum, aktivasyon/demo ekranı, lisans durumu ve kalan offline süre, modüle göre menü, yenileme; ödeme bileşeninin ortaklaştırılması | Menü gizleme, lisans yenileme, iptalde durma; satış akışı bekleme yapmaz; secret JS'te yok |
| FAZ 6 | Gerçek cihaz ve EXE kabul testleri | Gerçek Tauri EXE ve gerçek LAN'da 2+ satış PC'si: cihaz limiti, offline/iptal/modül değişikliği senaryoları |
| FAZ 7 | Setup ve Updater: Device Service zorunlu kurulum, LocalService ve ACL, anahtar hazırlığı, yalnız sunucu ana bilgisayar modu | Temiz Windows 10/11'de kurulum ve güncelleme; yalnız sunucu ana bilgisayar cihaz hakkı kullanmaz |
| FAZ 8 | Lite pilot müşteri | Gerçek işletmede Lite paketle kontrollü kullanım |

## 11. Onaylanan kararlar (2026-11-28)
1. Firma + ürün bazında tek lisans; cihaz kotası bütün şubelerde ortak.
2. Demo 14 gün, tek cihaz, Pro paket.
3. Lisans bittiğinde yeni ticari işlemler kapalı; giriş, veri görüntüleme, rapor ve yedekleme açık.
4. `bank` modülü banka hesaplarını, transferi ve banka hareketlerini kapsar; normal kart tahsilatı temeldir.
5. Çoklu depo modülü kapanınca mevcut veriler korunur.
6. Terazi bütün paketlerde standarttır (temel sistem).
7. Ana bilgisayar saat güvenliği FAZ 4.2'de teknik olarak çözülecek.
8. Mobil garson cihazları satış bilgisayarı kotasından düşmez.
9. Device Service `LocalService` hesabıyla kalır; gerçek cihazda doğrulanır.
10. Modül kapatıldığında mevcut açık işlemler güvenli şekilde tamamlanabilir, yeni işlem açılamaz.
11. Planlanan modüller açıklamada belirtilir; yeni DB alanı açılmaz.

Mobil garson cihazlarının nasıl kimlik doğrulayacağı ve sayılacağı (kota dışı) `mobile_waiter` geliştirilirken
ayrıca tasarlanır; bu sözleşme yalnız satış bilgisayarı kotasından düşmeyeceğini sabitler.

## 12. Süreli ve ömür boyu lisans (v2.2 — FAZ 6.2a.2)

- Lite/Pano/Restaurant/Pro kullanım lisansı `term` veya `perpetual` olabilir.
  Süreli lisans geçerli bitiş günü taşır; ömür boyu lisansın bitişi null'dır. Yapay tarih yoktur.
  Türü olmayan v1 belge yalnız geçerli tarih ile süreli kabul edilir; null tek başına süresiz hak vermez.
- Mevcut süreli belgeler v1 olarak kalır (payload'a yeni tür alanı eklenmez).
  Ömür boyu belge zarf/payload v=2, imzalı `license_type=perpetual`, `expires_at=null` taşır.
  v2 tür kümesi term/perpetual'dır; term geçerli tarih gerektirir. Backend süreli belgeleri v1 üretir.
  Eksik/bilinmeyen/çelişkili tür-tarih reddedilir; imza algoritması, ham payload, kid ve cihaz bağı değişmez.
- Süreli offline sınırı min(issued_at + 7/365 gün, bitişi izleyen 00:00 UTC);
  ömür boyu offline sınırı issued_at + 7/365 gündür. Ömür boyu sınırsız offline değildir.
  Demo yine 14 günlük Pro, tek cihaz ve 7 gün offline'dır.
- Oluşturma ve demodan/term'den perpetual'a geçiş yalnız ADMIN; perpetual → term ve perpetual süre uzatma yok.
  Term dönüşümünde anahtar, cihaz bağları, limit, paket ve ek modüller korunur; audit yazılır.
  İptal dönüşümle kaldırılamaz. Bakım/güncelleme hakkı bu fazda uygulanmaz (FAZ 7).
- **Dağıtım kapalı:** yeni çift sürüm Client, Device Service ve WebApi kabulü tamamlanmadan
  perpetual oluşturma/dönüşüm/v2 üretimi açılmaz. Client 1.0.1 v2 desteklemez.
  Üretim için özellik bayrağına ek ayrı production onayı gerekir; ayrıntı CLIENT_API.
- Yeni tüketici null tarihi yalnız doğrulanmış açık perpetual türünde süresiz yorumlamalı;
  belge yok/bilinmeyen durumdan ayırmalıdır. Sonlu valid_until, saat koruması, son belge sıralaması,
  kalıcı iptal, cihaz limiti ve modül hakları aynen uygulanır.
- v2 issued_at/valid_until UTC ISO 8601, altı basamak kesirli saniye içerir. Client bunları
  hassasiyet kaybetmeden okumalı/saklamalı/karşılaştırmalıdır. Aynı saniyede verilen v1 belgesinden sonra
  sıralama için v2 üretim zamanına bir mikrosaniye eklenir. Eski belge yeni hakları geri alamaz.
  Dönüşüm ile belge snapshot/imzası aynı lisans satır kilidi üzerinden sıralanır.
