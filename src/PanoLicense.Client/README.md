# PanoLicense.Client

PanoLicense lisans sunucusunun .NET 8 istemci kütüphanesi (net8.0; .NET 8+ uygulamalarda kullanılır).

- `LicenseManager` — demo/aktivasyon/kontrol, CNG/TPM cihaz anahtarı, korumalı durum, 7/365 offline, kalıcı iptal,
  `AllowsCommercialOperations`, `HasModule(code)` (ağ/disk kullanmaz).
- `LicenseDocumentVerifier` — imzalı lisans belgesini (ES256) gömülü açık anahtarla doğrular (ağ yok).

Paket **sunucu imza açık anahtarını içermez**; ürün kendi kodunda `SigningPublicKeys = { ["k1"] = PEM }` olarak gömer
ve SPKI SHA-256 parmak izini testle sabitler. Özel anahtar hiçbir zaman istemcide bulunmaz.

Sözleşme ve kullanım: PanoLicense repo `docs/CLIENT_API.md`, `docs/CLIENT_PACKAGE.md`.

## 1.1.0 — tek v2
Ticari izin yalnız v2 belgeyle verilir. LicenseType term/perpetual, ExpiresAt nullable'dır;
LicenseEndUtc da nullable, AllowedUntil ise her zaman sonlu offline sınırıdır.
LicenseStatus.LicenseType null ise doğrulanmış tür yoktur; expires null tek başına ömür boyu anlamına gelmez.
ReadRevocation yalnız geçmiş iptal için v1 okuyabilir; aktif v1 ticari izin vermez.
Mevcut cihaz anahtarı, korumalı durum, iptal ve güvenilir saat geçmişi silinerek yükseltme yapılmaz.
