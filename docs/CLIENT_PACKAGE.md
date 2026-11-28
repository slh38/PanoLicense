# PanoLicense.Client NuGet Paketi

**FAZ 6.2a.3 tamamlandı ve onaylandı; resmî paket üretildi:** PanoLicense.Client **1.1.0**,
tek v2 kullanım belgesi; aktif v1 ticari kabulü yok, yalnız geçmiş iptal okumaya dar v1 desteği.
Device Service ve WebApi aynı sabit sürüme geçecektir. Güncel karar ortak sözleşme v2.3 §12'dir.
Ürün satışa çıkmadığından ticari geriye uyumluluk zorunlu değildir; 1.1.0 sürüm numarası ürün sahibince
bu geçiş için açıkça onaylanmıştır. Aşağıdaki 1.0.1 sürümü/hash kaydı mevcut resmî paket tarihçesidir;
yeni geçişte 1.0.1 kullanma talimatı değildir. 1.1.0 hash/kaynak kaydı aşağıdadır; PanoPOS entegrasyonu ve gerçek API/DB E2E kabulü bekliyor.

Sürüm: **1.1.0** (resmî; 1.0.1 arşivi korunur) · Hedef: `net8.0` (bağımlılık yok) · Uyumluluk: .NET 8 (PanoPOS WebApi) ve .NET 10 (PanoPOS Device Service)
doğrulandı (2026-11-28). Kullanım rehberi PanoPOS tarafında: `PanoPos/docs/LICENSING_INTEGRATION.md` §3.

## Üretim
```
powershell -ExecutionPolicy Bypass -File tools\pack-client.ps1
```
- Çıktı: `artifacts/nuget/PanoLicense.Client.<sürüm>.nupkg` + `.sha256` (çıktı klasörü varsayılan Git ignore kapsamındadır; bu kapanışın açık onayıyla yalnız resmî 1.1.0 nupkg ve .sha256 arşiv kaydı Git'e alındı).
- Resmî paket yalnız **commit edilmiş temiz** `src/PanoLicense.Client`'tan üretilir; betik kirli ağaçta durur
  (deneme için `-AllowDirty`, bu paket dağıtılmaz). Nuspec içindeki `repository commit` kaynağı gösterir.
- Yeniden üretilebilir: `Deterministic` + `ContinuousIntegrationBuild` ve ZIP giriş zamanlarının 1980-01-01 olarak sabitlenmesi; aynı commit'ten aynı SHA-256 üretilir
  (deneme: art arda iki paketleme aynı hash).
- Betik paket içeriğini denetler: yalnız `lib/net8.0/PanoLicense.Client.dll`, `README.md`, nuspec ve NuGet meta dosyaları;
  `PRIVATE KEY`, `DEV_TEST_`, `DB_PASSWORD`, `APP_KEY=` izi varsa durur. DLL'de yerel yol ve PEM yoktur.

## Sürüm kuralı
- Sürüm yalnız `src/PanoLicense.Client/PanoLicense.Client.csproj` → `<Version>` içinde değişir (SemVer).
- Yama (1.0.x): davranış değişmeyen düzeltme; küçük (1.x.0): geriye uyumlu ekleme; büyük (x.0.0): sözleşme kırılımı.
- Aynı sürüm numarasıyla farklı içerik **asla** yayınlanmaz; düzeltme yeni sürümdür.
- **Tarihsel istisna:** 1.0.1, yama numarasıyla yayınlandığı hâlde geriye uyumlu public API eklemesi içerir
  (`CreateCommercialProof`, `GetSignedRevocationDocument`, `CommercialProofVerifier`, `CommercialProofFormat`).
  SemVer'e göre 1.1.0 olmalıydı; paket yayınlanıp PanoPOS'ta hash ile kullanıldığı için yeniden numaralandırılmaz.
  Bu tek seferlik istisnadır; bundan sonraki public API eklemeleri küçük sürüm (1.x.0) artışıyla yapılır.
- Her sürüm için `CLIENT_PACKAGE.md`'ye SHA-256 yazılır; tüketici (PanoPOS) hash'i doğrular.

## Paket içeriği ve güvenlik
- Paket **sunucu imza açık anahtarını içermez**; ürün kendi kodunda gömer (aşağıda). Özel anahtar hiçbir yerde yoktur.
- `.env`, parola, test verisi, fixture, PHP veya panel dosyası pakete girmez.

## Açık anahtarın ürüne güvenli eklenmesi
1. Kaynak: bu repodaki `docs/keys/panolicense-k1.pub.pem` (commit `426184a`); canlı sunucudaki anahtarla aynı.
2. Doğrulama: `openssl pkey -pubin -in panolicense-k1.pub.pem -outform DER | openssl dgst -sha256` →
   `4bb026c7bdf8b7184c284ae45859857b22ab3261d7a75023a973cb76f97d04b8`.
3. Ürün kodunda **sabit** olarak tek bir kaynak dosyada tutulur (`kid → PEM`); `appsettings`, kayıt defteri veya kullanıcının
   değiştirebileceği bir dosyadan **okunmaz** (değiştirilirse sahte belge kabul edilir).
4. Ürün testleri gömülü PEM'in SPKI SHA-256 değerini yukarıdaki değerle karşılaştırır (değişiklik testte yakalanır).
5. Anahtar değişirse yeni `kid` eklenir, eski `kid` geçiş bitene kadar korunur.

## Sürüm geçmişi
| Sürüm | Tarih | Kaynak | Not |
|---|---|---|---|
| 1.0.0 | 2026-11-28 | `2a91b681a6a3b28a32b46e2dccd145b76031366a` | F4 Client + modüller (`HasModule`); ilk paket. 41 389 bayt. SHA-256 `3f7fc9b3db1df9e28a200ba5eea94ad4976f4eecfa77d8046d5ee70770eb2d91` |
| 1.0.1 | 2026-11-28 | `846a407eb9014e25930d1c29722ffd60f61ccaee` | Ticari yetki kanıtı: `LicenseManager.CreateCommercialProof(challenge)`, `GetSignedRevocationDocument()`; ürün sunucusu için `CommercialProofVerifier` (`Verify`, `ReadRevocation`), `CommercialProofFormat`. Mevcut davranış değişmedi. 45 473 bayt. SHA-256 `5bf8aee31d031aa4dfe9a21b31b2a3e5e3ace88a06544e67a8c3b7e83653d954` |

## 1.1.0 kabul ve üretim kaydı — resmî paket hazır

- csproj Version=1.1.0; net8.0 ve ek bağımlılık olmama politikası korundu.
  Kullanım belgesi yalnız v2; dar v1 geçmiş iptal desteği CLIENT_API son bölümde.
- API farkları: LicenseDocument.LicenseType eklendi; ExpiresAt string? ve LicenseEndUtc DateTimeOffset?
  oldu; sonlu AllowedUntil eklendi. LicenseStatus'a son isteğe bağlı LicenseType parametresi eklendi.
  Mevcut LicenseManager ve CommercialProofVerifier metot adları/kanıt biçimi korunur.
  Nullable bitiş ve status constructor/deconstruction imzası nedeniyle tüketiciler yeniden derlenmeli;
  eski binary'ye DLL değiştirme yöntemiyle dağıtılmaz. 1.1.0 sürümü bu satış öncesi geçiş için açıkça onaylı istisnadır.
- Yeni 26 v2 vaka geçti; ardından bütün Client test paketi bir kez 111/111 PASS (gerçek API E2E kapısı
  kapalı; DB/ağ senaryosu fiilen çalışmadı). Release build 0 uyarı/0 hata. PanoPOS kabulü bekliyor.
- 1.0.1 paketi olduğu gibi korunur; doğrulanan SHA-256:
  5bf8aee31d031aa4dfe9a21b31b2a3e5e3ace88a06544e67a8c3b7e83653d954.
- **Kod onayı sonrasındaki kapanış:** kaynak commit/push → temiz Client ağacını doğrulama →
  mevcut tools/pack-client.ps1 (AllowDirty kullanılmadan) → 1.1.0 nupkg + .sha256 →
  nuspec repository commit ve içerik/gizli bilgi kontrolü → mevcut standartla yeniden üretim/hash doğrulama →
  bu belgeye kaynak commit/boyut/SHA-256 kaydı. Kapanışta bu sıra uygulandı; doğrulanmış paket kaydı aşağıdadır.
- Device Service ve WebApi aynı sabit [1.1.0] sürümüne koordineli geçecek.
  Gömülü k1 ve fingerprint korunacak. Client/paket/sözleşme sürümü tek başına dağıtım izni değildir;
  Backend'in iki geçici bayrağı kabul tamamlanana kadar kapalı kalır.

Paketleme düzeltmesi (FAZ 6.2a.3): temiz üretimde DLL ve tüm giriş içerikleri aynı olmasına rağmen NuGet ZIP zamanları değişiyordu. Mevcut betik hash hesaplamadan önce yalnız ZIP zamanlarını sabitler; eski 1.0.1 arşivi değiştirilmez.

## Resmî 1.1.0 arşiv kaydı (2026-11-28)

| Alan | Değer |
|---|---|
| Sürüm | 1.1.0 |
| Client kod commit'i | c2257ff34c040c0446b9f84806fe4f5316a8f3fe |
| Paket kaynak commit'i (nuspec repository commit) | 4e6e04515ede37f3b69ad2b72a047cd83812a75e |
| Paket | artifacts/nuget/PanoLicense.Client.1.1.0.nupkg |
| SHA dosyası | artifacts/nuget/PanoLicense.Client.1.1.0.nupkg.sha256 |
| Boyut | 46 740 bayt |
| SHA-256 | b381300fa3552109c0b84dcf27f7eef3d29be7864c9e2f4bfd128f71b112e1be |

- Temiz, commit edilmiş Client kaynağından Release üretim; AllowDirty kullanılmadı.
  Nuspec 1.1.0 ve kaynak commit doğrulandı. İki ayrı temiz CI üretiminde paket baytları/SHA-256 eşit.
- İlk gerçek temiz üretim kontrolünde yalnız ZIP zamanları değiştiği görüldü; DLL ve tüm giriş
  içerikleri aynıydı. Mevcut paket betiğinde ZIP zamanları sabitlendi, düzeltme kaynak commit'e alındı.
  Yukarıdaki hash yalnız bu son, doğrulanmış resmî arşive aittir; ara paketler yayımlanmadı.
- Paket içerik/gizli bilgi kontrolü geçti; test, fixture, özel anahtar, env ve geçici dosya içermez.
  net8.0 ve bağımlılıksız paket politikası korunur.
- 111 başarılı Client testi tekrarlanmadı. Gerçek API/DB E2E ve PanoPOS koordineli kabulü henüz yapılmadı.
  Backend dağıtım bayrakları kapalıdır; bu paket kaydı canlı dağıtım onayı değildir.
