using System.Security.Cryptography;
using System.Text.Json;

namespace PanoLicense.Client;

/// <summary>
/// Sunucudan gelen imzalı lisans belgesini (ES256: ECDSA P-256 + SHA-256) doğrular.
/// Açık anahtarlar istemciye gömülür ve <c>kid</c> ile seçilir; özel anahtar istemcide asla bulunmaz.
/// .NET'in yerleşik <see cref="ECDsa"/> sınıfını kullanır, ek paket gerektirmez.
/// </summary>
public sealed class LicenseDocumentVerifier : IDisposable
{
    public const string Algorithm = "ES256";
    public const int SupportedVersion = 1;

    private readonly Dictionary<string, ECDsa> _keys = new(StringComparer.Ordinal);

    /// <param name="publicKeysPemByKid">kid → açık anahtar (PEM, "BEGIN PUBLIC KEY").</param>
    public LicenseDocumentVerifier(IReadOnlyDictionary<string, string> publicKeysPemByKid)
    {
        if (publicKeysPemByKid.Count == 0)
            throw new ArgumentException("En az bir açık anahtar gerekir.", nameof(publicKeysPemByKid));

        foreach (var (kid, pem) in publicKeysPemByKid)
        {
            var key = ECDsa.Create();
            key.ImportFromPem(pem);
            if (key.ExportParameters(false).Curve.Oid.Value != "1.2.840.10045.3.1.7")
            {
                key.Dispose();
                throw new ArgumentException($"'{kid}' anahtarı P-256 değil.", nameof(publicKeysPemByKid));
            }
            _keys[kid] = key;
        }
    }

    /// <summary>
    /// Zarfı (license_document JSON'u) doğrular. Başarılıysa imzalı içeriği döner; aksi hâlde <c>null</c>
    /// ve <paramref name="error"/> (ör. "bad_signature", "unknown_kid").
    /// </summary>
    public LicenseDocument? Verify(string envelopeJson, LicenseIdentity expected, out string? error)
    {
        error = null;
        try
        {
            using var envelope = JsonDocument.Parse(envelopeJson);
            var root = envelope.RootElement;

            if (!root.TryGetProperty("v", out var v) || v.GetInt32() != SupportedVersion)
                return Fail("unsupported_version", out error);
            if (root.GetProperty("alg").GetString() != Algorithm)
                return Fail("unsupported_alg", out error);
            if (!_keys.TryGetValue(root.GetProperty("kid").GetString() ?? "", out var key))
                return Fail("unknown_kid", out error);

            var payload = Base64UrlDecode(root.GetProperty("payload").GetString());
            var signature = Base64UrlDecode(root.GetProperty("sig").GetString());

            // Sunucu (PHP openssl_sign) DER biçiminde imzalar.
            if (!key.VerifyData(payload, signature, HashAlgorithmName.SHA256, DSASignatureFormat.Rfc3279DerSequence))
                return Fail("bad_signature", out error);

            var document = JsonSerializer.Deserialize<LicenseDocument>(payload);
            if (document is null || document.Version != SupportedVersion)
                return Fail("bad_payload", out error);

            if (!expected.Matches(document))
                return Fail("identity_mismatch", out error);

            return document;
        }
        catch (Exception e) when (e is JsonException or FormatException or KeyNotFoundException or InvalidOperationException)
        {
            return Fail("malformed", out error);
        }
    }

    public void Dispose()
    {
        foreach (var key in _keys.Values) key.Dispose();
        _keys.Clear();
    }

    private static LicenseDocument? Fail(string code, out string? error)
    {
        error = code;
        return null;
    }

    private static byte[] Base64UrlDecode(string? value)
    {
        if (string.IsNullOrEmpty(value)) throw new FormatException("Boş base64url.");
        var s = value.Replace('-', '+').Replace('_', '/');
        return Convert.FromBase64String(s.PadRight(s.Length + (4 - s.Length % 4) % 4, '='));
    }
}
