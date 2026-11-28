using System.Security.Cryptography;
using System.Text;
using System.Text.RegularExpressions;

namespace PanoLicense.Client;

/// <summary>
/// Ticari yetki kanıtı: cihaz, ürünün kendi sunucusunun (ör. PanoPOS WebApi) tek kullanımlık challenge'ını,
/// güncel imzalı lisans belgesine bağlı olarak cihaz anahtarıyla imzalar. Belge ve imza aynı anlık lisans durumundan gelir.
/// Özel anahtar dışarı verilmez; yalnız imza ve açık anahtar döner.
/// </summary>
/// <param name="DocumentEnvelope">PanoLicense imzalı belge zarfı. <b>Opak metin olarak taşınmalıdır</b> (yeniden serialize edilmez):
/// kanıt mesajı bu metnin SHA-256 özetine bağlıdır.</param>
/// <param name="DevicePublicKeyPem">Cihaz P-256 açık anahtarı (SPKI PEM). Özeti belgedeki <c>device_key_hash</c> ile aynıdır.</param>
/// <param name="Signature">Mesajın cihaz anahtarıyla ECDSA P-256/SHA-256 imzası (DER, standart base64).</param>
/// <param name="AllowedUntil">Bilgi: min(belge <c>valid_until</c>, lisans bitişi). Doğrulayan kendisi belgeden hesaplar.</param>
public sealed record CommercialProof(
    string Challenge,
    string DocumentEnvelope,
    string DevicePublicKeyPem,
    string Signature,
    string LicenseKey,
    IReadOnlyList<string> Modules,
    DateTimeOffset AllowedUntil);

/// <summary>Kanıt üretim sonucu. Başarısızsa <see cref="Proof"/> null'dır ve <see cref="Reason"/> nedeni verir.</summary>
public sealed record CommercialProofResult(bool Succeeded, string? Reason, CommercialProof? Proof, LicenseStatus Status);

/// <summary>Doğrulama sonucu. Geçerliyse doğrulanmış belge ve izin bitişi döner.</summary>
public sealed record CommercialProofCheck(bool Valid, string? Error, LicenseDocument? Document, DateTimeOffset? AllowedUntil);

/// <summary>İmzası doğrulanmış iptal belgesi (ürün sunucusunun kalıcı iptal kaydı için).</summary>
public sealed record SignedRevocation(string LicenseKey, string DeviceKeyHash, DateTimeOffset IssuedAt);

/// <summary>
/// Kanıt mesajı biçimi (iki taraf aynı kodu kullanır). Satırlar <c>\n</c> ile birleşir:
/// <code>
/// panolicense-commercial-proof-v1
/// {product}
/// {license_key}
/// {device_key_hash}
/// {document_sha256}      // zarf metninin UTF-8 baytlarının küçük harf hex SHA-256 özeti
/// {challenge}
/// </code>
/// Amaç satırı PanoLicense sunucusunun cihaz kanıtından (<c>panolicense-device-proof-v1</c>) farklıdır; imzalar karıştırılamaz.
/// </summary>
public static class CommercialProofFormat
{
    public const string Purpose = "panolicense-commercial-proof-v1";

    /// <summary>Challenge: 16–256 karakter, yalnız harf, rakam ve <c>. _ : -</c> (satır sonu/boşluk yok).</summary>
    public static bool IsValidChallenge(string? challenge) =>
        challenge is not null && Regex.IsMatch(challenge, "^[A-Za-z0-9._:-]{16,256}$");

    public static string DocumentHash(string documentEnvelope) =>
        Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(documentEnvelope))).ToLowerInvariant();

    public static string BuildMessage(string challenge, string product, string licenseKey, string deviceKeyHash, string documentEnvelope) =>
        string.Join('\n', Purpose, product, licenseKey, deviceKeyHash.ToLowerInvariant(), DocumentHash(documentEnvelope), challenge);
}

/// <summary>
/// Ürün sunucusu (ör. PanoPOS WebApi, .NET 8) için kanıt doğrulayıcı. Ağ çağrısı yapmaz. Zaman güvenilir sunucu saatinden verilir.
/// Kontroller: challenge biçimi ve eşleşmesi, P-256 açık anahtar, belge imzası (gömülü kid), ürün, belge–anahtar bağı,
/// cihaz aktif, durum active/demo, izin bitişi ve belge zamanı, kanıt imzası. Tek kullanımlık challenge takibi çağıranın işidir.
/// </summary>
public sealed class CommercialProofVerifier : IDisposable
{
    private readonly LicenseDocumentVerifier _documents;
    private readonly string _product;

    public CommercialProofVerifier(IReadOnlyDictionary<string, string> signingPublicKeysByKid, string productCode)
    {
        if (string.IsNullOrWhiteSpace(productCode)) throw new ArgumentException("Ürün kodu gerekli.", nameof(productCode));
        _documents = new LicenseDocumentVerifier(signingPublicKeysByKid);
        _product = productCode;
    }

    /// <param name="expectedChallenge">Sunucunun verdiği ve henüz tüketilmemiş challenge.</param>
    /// <param name="nowUtc">Sunucunun güvenilir zamanı.</param>
    public CommercialProofCheck Verify(string expectedChallenge, string documentEnvelope, string devicePublicKeyPem,
        string signature, DateTimeOffset nowUtc)
    {
        CommercialProofCheck Fail(string error) => new(false, error, null, null);

        if (!CommercialProofFormat.IsValidChallenge(expectedChallenge)) return Fail("invalid_challenge");
        if (string.IsNullOrEmpty(documentEnvelope) || string.IsNullOrEmpty(devicePublicKeyPem) || string.IsNullOrEmpty(signature))
            return Fail("malformed");

        using var deviceKey = ECDsa.Create();
        string keyHash;
        try
        {
            if (devicePublicKeyPem.Length > 1024) return Fail("invalid_device_key");
            deviceKey.ImportFromPem(devicePublicKeyPem);
            keyHash = DeviceProof.PublicKeyHash(deviceKey); // P-256 değilse ArgumentException
        }
        catch (Exception e) when (e is ArgumentException or CryptographicException)
        {
            return Fail("invalid_device_key");
        }

        var document = _documents.VerifySignature(documentEnvelope, out var error);
        if (document is null) return Fail(error ?? "bad_document");
        if (!new LicenseIdentity(_product, document.LicenseKey, document.Fingerprint, keyHash).Matches(document))
            return Fail("identity_mismatch");
        if (document.DeviceStatus != "active") return Fail("device_released");
        if (document.Status == "revoked") return Fail("revoked");
        if (document.Status is not ("active" or "demo")) return Fail("unknown_status");

        if (nowUtc < document.IssuedAt - OfflineLicenseEvaluator.ClockTolerance) return Fail("document_from_future");
        var allowedUntil = document.AllowedUntil;
        if (document.LicenseEndUtc is { } end && nowUtc >= end) return Fail(document.Status == "demo" ? "demo_expired" : "license_expired");
        if (nowUtc >= allowedUntil) return Fail("offline_grace_exceeded");

        byte[] signatureBytes;
        try
        {
            signatureBytes = Convert.FromBase64String(signature);
        }
        catch (FormatException)
        {
            return Fail("bad_proof");
        }
        var message = CommercialProofFormat.BuildMessage(expectedChallenge, _product, document.LicenseKey, keyHash, documentEnvelope);
        if (!deviceKey.VerifyData(Encoding.UTF8.GetBytes(message), signatureBytes, HashAlgorithmName.SHA256, DSASignatureFormat.Rfc3279DerSequence))
            return Fail("bad_proof");

        return new CommercialProofCheck(true, null, document, allowedUntil);
    }

    /// <summary>
    /// İmzalı iptal belgesini okur (kanıt gerekmez: iptal sunucu imzalıdır ve yalnız kısıtlar). İptal değilse null.
    /// </summary>
    public SignedRevocation? ReadRevocation(string documentEnvelope)
    {
        var document = _documents.ReadRevocationDocument(documentEnvelope);
        return document is { Status: "revoked" } && document.Product == _product && document.LicenseKey.Length > 0
            ? new SignedRevocation(document.LicenseKey, document.DeviceKeyHash, document.IssuedAt)
            : null;
    }

    public void Dispose() => _documents.Dispose();
}
