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
7. **Lisans bazlı offline çalışma:** standart 7 gün, yalnız Admin tarafından verilen özel izin 365 gündür.
   Varsayılan ve mevcut lisanslar 7 gündür. Ayrıntılı politika ve iptal sınırı §22'dedir.
8. **Süre dolunca ticari işlemler durur:** lisans süresi dolduğunda (ve iptal / offline süre aşımında)
   istemci satış, tahsilat, belge kesme gibi ticari işlemleri durdurur.
   Client ticari izin otoritesi: `LicenseManager.AllowsCommercialOperations`; enum tek başına karar değildir.
9. **Bağımsız sunucu:** lisans sunucusu hiçbir üründen bağımsızdır; ürünler yalnız istemcidir ve
   sunucunun imzalı kararını uygular. Özel imza anahtarı hiçbir zaman istemciye gönderilmez.
10. **PanoPOS entegrasyonu** (kalan offline süre gösterimi dahil) sonraki aşamadır.

## Panel kullanıcı kuralları (2026-11-22)
11. **Giriş kullanıcı adı + parolayla** yapılır. İlk Admin sunucuda `panolicense:create-admin` komutuyla,
    parolayı Admin'in kendisi yazarak oluşturulur. Diğer kullanıcıları Admin panelden açar (rol: User).
12. **User varsayılan yetkileri:** yeni lisans verme, süresi dolacak lisansları görme, lisans arama.
    - Arama **tüm lisanslarda** yapılır (firma adı veya lisans kodu); sonuçlarda yalnız görüntüleme vardır.
    - "Süresi dolacak" = aktif olup süresi **±30 gün** içinde olan (yaklaşan + yeni dolmuş) lisanslar.
    - Filtresiz tüm lisans listesi, süre uzatma ve iptal yalnız Admin'dedir (Admin istersen User'a uzatma/iptal yetkisi verebilir).
13. **Lisansı kim verdi her zaman görünür:** lisans kaydında veren kullanıcı tutulur; liste ve detayda gösterilir.
    Tüm işlemler (giriş, lisans/firma/kullanıcı oluşturma, uzatma, iptal) denetim kaydına yazılır.
14. Aynı firma + ürün için aktif lisans varken ikinci lisans verilemez; yenileme süre uzatmayla yapılır.

## Demo kuralları (2026-11-25)
15. **PanoPOS kurulumunda demo** seçilince istemci lisans sunucusuna kayıt gönderir; sunucu **14 günlük demo**
    açar (cihaz limiti 1). Firma bilgisini kurulumu yapan girer.
16. **Kurulumu yapan:** kurulum ekranına isteğe bağlı olarak personelin **lisans sunucusundaki kullanıcı adı**
    (ör. `i.simsek`) yazılır ve lisansta "Kurulumu yapan" olarak görünür. Boş bırakılırsa **son kullanıcı** kurmuştur.
    Yazılan ad panelde yoksa ham hâliyle saklanır ve "panelde yok" diye gösterilir (doğrulanmış bir kimlik değildir).
17. **Bir cihaz bir ürün için yalnız bir kez demo alır.** Yeniden kurulum demo süresini sıfırlamaz; mevcut kayıt döner.
18. **Demo onayı = lisans vermek:** `license.create` yetkisi olan (Admin veya yetkili personel) eksik firma bilgilerini
    tamamlar, cihaz limiti ve süreyi girer, onaylar. Lisans anahtarı değişmez; onaylayan "Lisansı veren" olur,
    kurulumu yapan bilgisi korunur. Onaylanmayacak demo iptal edilir (silinmez).
19. **Cihaz limiti uygulanır:** aktif cihaz sayısı limiti aşamaz; aynı cihaz tekrar gelirse yer tüketmez.
    Yetkili kullanıcı (`device.release`, varsayılan Admin) cihazı serbest bırakarak yer açar; kayıt silinmez.
20. İnternet yokken demonun PanoPOS'ta ne zaman duracağı PanoPOS tarafının konusudur (bu repo kapsamı dışında).

## İmzalı lisans ve offline (2026-11-27)
21. **İmza: ECDSA P-256 (ES256).** Sunucu her istemci yanıtında cihaza bağlı imzalı belge verir; özel anahtar
    yalnız sunucuda kalır, istemci açık anahtarla doğrular. İmzasız belge verilmez.
22. **Offline politika (2026-11-27 ek onay):** lisans bazında offline_grace_days yalnız 7 (standart/varsayılan)
    veya 365 (özel) olabilir; cihaz bazında politika yoktur. Mevcut lisanslar 7 kalır. 365 verme ve politika
    değiştirme yalnız Admin yetkisidir; User'a devredilemez. Yeni lisans/demo onayında alan verilmezse 7 olur.
    Onaylanmamış 14 günlük demo yalnız 7 kullanır; özel politika verilemez. Demo ticari lisansa onaylanırken
    Admin 365 seçebilir. İlk aktivasyon ve her offline izin yenilemesi internetten, cihaz sahiplik kanıtıyla yapılır.
    Belge bitişi min(üretim anı + lisansın 7/365 günü, ticari lisans bitişini izleyen 00:00 UTC) olur.
    Offline izin ticari lisans bitişini aşamaz. Süre aşımı veya şüpheli saatte yeniden online doğrulama gerekir.
    Gerçek politika değişiklikleri kim/zaman/lisans/eski-yeni değerle aynı transaction'da loglanır.
    **365 günlük belge, cihaz internete bağlanmadığı sürece uzaktan iptal edilemez; en fazla imzalı bitişine
    (lisans daha erken bitiyorsa o ana) kadar kullanılabilir.** İptal veya 365→7 değişikliği önceden verilmiş
    imzalı belgeyi uzaktan değiştirmez. Yeni politika online yenilemede alınır; öğrenilmiş iptal Client korumalı durumunda kalıcıdır.
23. Belge ürün, lisans ve cihaz anahtarıyla bağlıdır; fingerprint tek başına cihaz kimliği değildir.
    Her cihaz ayrı ECDSA P-256 anahtarına sahiptir. Demo/aktivasyon/kontrol kısa ömürlü tek kullanımlık
    challenge ile özel anahtar sahipliği ister. Aynı anahtarla doğrulanmış tekrar ek kota tüketmez.
24. Eski anahtarsız aktif kayıt otomatik anahtara bağlanmaz. Yetkili release sonrası kanıtlı aktivasyon
    kota içinde aynı kaydı bağlar; anahtara bağlı kayıt başka anahtarla otomatik devralınamaz.
25. **Nihai Client kontrol politikası (2026-11-27 onay, F4 ile uygulandı):**
    Program açılışında yerel lisans doğrulanır; çalışma sırasında saatte bir yerel kontrol yapılır.
    Online lisans doğrulaması 6 saatte birdir. İnternet geri geldiğinde kalan offline süre, periyodik
    takvim veya önceki hata beklemesinden bağımsız olarak ilk fırsatta online doğrulama yapılır.
    Bağlantı hatasında ilk tekrar 15 dakika sonradır; devam eden hatalarda kademeli bekleme uygulanır.
    Sunucuya aynı anda yüklenmeyi azaltmak için küçük rastgele gecikme (jitter) kullanılır.
    Standart ve özel offline lisanslar aynı kontrol politikasına tabidir; §22'deki 7/365 gün yalnız
    offline çalışma hakkıdır, online kontrol sıklığını değiştirmez.
    **Satış sırasında kesinlikle internet sorgusu yapılmaz.** Lisans kontrolleri arka planda, kullanıcı
    arayüzünü engellemeden çalışır; satış yalnız güncel yerel lisans kararını kullanır.
    Sunucudan doğrulanarak öğrenilen iptal kalıcı ve korumalı saklanır. Bağlantı yeniden kesilse,
    program yeniden başlasa veya eski aktif belge geri yüklense de ilgili lisans ticari işlemleri açamaz.
    Uygulama/saklama şartları: [ARCHITECTURE §9](ARCHITECTURE.md#9-f4-güvenlik-şartları).
    Bu politika F4 Client (LicenseManager) ile uygulandı (2026-11-27, yerel; PanoPOS entegrasyonu F5).

## Durum sözlüğü (Client)
| Durum | Anlamı | Ticari işlem |
|---|---|---|
| `Active` | Online doğrulandı, süre içinde | Açık |
| `OfflineGrace` | Sunucuya ulaşılamıyor, imzalı 7/365 günlük izin dolmadı | Açık |
| `Expired` | Lisans süresi veya offline süre doldu | Kapalı |
| `Revoked` | Admin iptal etti | Kapalı |
| `DeviceLimitExceeded` | Satın alınan cihaz limiti dolu | Kapalı |
| `Unknown` | Henüz doğrulanmadı / belge yok | Kapalı |


## F4 son düzeltmeler — çalışma sürekliliği kararı (2026-11-27)
- Normal elektrik kesintisi, Windows çökmesi veya beklenmedik kapanış tek başına geçerli offline lisansı kilitlemez.
- Kesin tespit edilen saat manipülasyonu veya bozuk/geçersiz imzalı durum/belge online doğrulama gerektirir.
  Bozuk kayıt tespiti yazılabildiğinde kalıcı requires_online işaretiyle korunur.
- Kalıcı kaydedilmiş iptal eski aktif belgeyle geri alınamaz. Bir durum yuvasına bile yazma hatası tespit edilirse
  mevcut süreçte ticari izin kapanır; sağlıklı kalıcı yazım tamamlanınca normal lisans kararı tekrar uygulanır.
- Normal kapanışta zaman kaydedilir; beklenmedik kapanış için sürekli disk yazımı veya otomatik online kilit eklenmez.
- **Kabul edilen sınır:** hiçbir kalıcı yere yazılamadan çökülürse yeni öğrenilmiş iptal/kayıt hatası sonraki
  açılışta kesin bilinemeyebilir. Saat dondurulup zaman kaydı öncesi süreç/Windows tekrar tekrar çökertilirse
  kaydedilmemiş süre geri kazanılabilir. Güvenilir kalıcı saat olmadan bu senaryo normal kesintiden kesin ayrılamaz.
  Kullanıcı onayıyla çalışma sürekliliği tercih edilir; böyle bir açılışta önceki geçerli offline belge kullanılabilir.
  Bu sınır teknik olarak giderildi veya 7/365 gün gerçek zaman garantisi sağlandı diye raporlanmaz.
## Ürün, paket ve modül (2026-11-28 onay)
26. **Katalog:** ürün (yazılım: PanoPOS, Mezbaha, Mobilya …) → ürüne ait paketler → ürüne ait modüller. Paket–modül
    eşleşmesi panelden yapılır. Ürün/paket/modül tanımlama ve düzenleme yalnız Admin'dir. Kodlar (ürün `MEZBAHA`,
    paket `PRO`, modül `variant`) imzalı belgeye ve ürün yazılımına girdiği için değişmez; adlar değişebilir.
    Kayıtlar silinmez, pasifleştirilir; pasif paket yeni lisansta seçilemez, pasif modül hiçbir lisansta geçerli değildir.
27. **PanoPOS başlangıç kataloğu (2026-11-28 onay):** Lite, Pano, Restaurant, Pro paketleri ve 16 modül;
    matris ve modül kodları LICENSING_CONTRACT §2'dedir (kaynak `config/catalog.php`). Demo Pro, 14 gün, tek cihaz.
    Katalog `panolicense:catalog-sync` ile yalnız eksik kayıt eklenerek uygulanır; mevcut ayarlar ezilmez, silme yok.
    Demo paketi Pro, süresi 14 gün. Mevcut lisanslar Pro'ya eşlendi.
28. **Lisans verirken ürün ve paket seçilir** (paket zorunlu, aynı ürüne ait ve aktif). Cihaz limiti paketin varsayılanıyla
    dolar, değiştirilebilir. Demo onayında paket seçilebilir (verilmezse Pro kalır).
29. **Geçerli modüller = paket modülleri + lisansa özel ek modüller** (yalnız aktif modüller). İmzalı belge bu listenin
    tamamını taşır. Ek modül verme/kaldırma ve paket değiştirme yalnız Admin'dir ve loglanır.
30. **Paket değişikliği cihaz limitini ve ek modülleri değiştirmez.** Paket/modül değişiklikleri cihazda §22 offline
    politikasına uygun olarak sonraki başarılı online kontrolde uygulanır; o ana kadar son imzalı liste geçerlidir.
31. Client modül kontrolü (`HasModule`) ağ veya disk kullanmaz; lisans ticari işleme kapalıysa hiçbir modül açık değildir.
32. **Ürün pasifleştirme (2026-11-27 ek onay):** pasif ürüne yeni lisans ve demo verilmez.
    Mevcut lisansın cihaz aktivasyonu, activate/check challenge'ı, online kontrolü ve 7/365 günlük
    offline izin yenilemesi ürün pasifken de çalışır. Ürün pasifleştirme lisans iptali değildir.
    Lisansın süre/iptal, cihaz kotası ve sahiplik kanıtı kontrolleri sürer; paket/modül kuralları değişmez.
33. Planlanan (henüz geliştirilmemiş) modüller yalnız açıklamada belirtilir; ayrı DB alanı yoktur. PanoPOS tarafı
    kararları (tek lisans + ortak kota, lisans bitince okuma/rapor/yedek açık, modül kapanınca açık işlem tamamlanır):
    LICENSING_CONTRACT §7, §11.