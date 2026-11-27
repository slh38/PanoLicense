# PanoLicense.Client NuGet Paketi

Sürüm: **1.0.1** (resmî) · Hedef: `net8.0` (bağımlılık yok) · Uyumluluk: .NET 8 (PanoPOS WebApi) ve .NET 10 (PanoPOS Device Service)
doğrulandı (2026-11-28). Kullanım rehberi PanoPOS tarafında: `PanoPos/docs/LICENSING_INTEGRATION.md` §3.

## Üretim
```
powershell -ExecutionPolicy Bypass -File tools\pack-client.ps1
```
- Çıktı: `artifacts/nuget/PanoLicense.Client.<sürüm>.nupkg` + `.sha256` (klasör Git'e girmez).
- Resmî paket yalnız **commit edilmiş temiz** `src/PanoLicense.Client`'tan üretilir; betik kirli ağaçta durur
  (deneme için `-AllowDirty`, bu paket dağıtılmaz). Nuspec içindeki `repository commit` kaynağı gösterir.
- Yeniden üretilebilir: `Deterministic` + `ContinuousIntegrationBuild`; aynı commit'ten aynı SHA-256 üretilir
  (deneme: art arda iki paketleme aynı hash).
- Betik paket içeriğini denetler: yalnız `lib/net8.0/PanoLicense.Client.dll`, `README.md`, nuspec ve NuGet meta dosyaları;
  `PRIVATE KEY`, `DEV_TEST_`, `DB_PASSWORD`, `APP_KEY=` izi varsa durur. DLL'de yerel yol ve PEM yoktur.

## Sürüm kuralı
- Sürüm yalnız `src/PanoLicense.Client/PanoLicense.Client.csproj` → `<Version>` içinde değişir (SemVer).
- Yama (1.0.x): davranış değişmeyen düzeltme; küçük (1.x.0): geriye uyumlu ekleme; büyük (x.0.0): sözleşme kırılımı.
- Aynı sürüm numarasıyla farklı içerik **asla** yayınlanmaz; düzeltme yeni sürümdür.
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
