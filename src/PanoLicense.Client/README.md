# PanoLicense.Client

PanoLicense lisans sunucusunun .NET 8 istemci kütüphanesi (net8.0; .NET 8+ uygulamalarda kullanılır).

- `LicenseManager` — demo/aktivasyon/kontrol, CNG/TPM cihaz anahtarı, korumalı durum, 7/365 offline, kalıcı iptal,
  `AllowsCommercialOperations`, `HasModule(code)` (ağ/disk kullanmaz).
- `LicenseDocumentVerifier` — imzalı lisans belgesini (ES256) gömülü açık anahtarla doğrular (ağ yok).

Paket **sunucu imza açık anahtarını içermez**; ürün kendi kodunda `SigningPublicKeys = { ["k1"] = PEM }` olarak gömer
ve SPKI SHA-256 parmak izini testle sabitler. Özel anahtar hiçbir zaman istemcide bulunmaz.

Sözleşme ve kullanım: PanoLicense repo `docs/CLIENT_API.md`, `docs/CLIENT_PACKAGE.md`.
