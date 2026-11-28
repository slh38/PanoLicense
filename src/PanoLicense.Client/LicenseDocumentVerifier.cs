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
    public const int SupportedVersion = 2;

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
        var document = VerifySignature(envelopeJson, out error);
        if (document is null) return null;
        return expected.Matches(document) ? document : Fail("identity_mismatch", out error);
    }

    /// <summary>
    /// Yalnız imza + biçim doğrulaması. Kimlik eşleşmesini ÇAĞIRAN yapmalıdır; bu yüzden internal'dır
    /// (dışarıda <see cref="Verify"/> veya <see cref="CommercialProofVerifier"/> kullanılır).
    /// </summary>
    internal LicenseDocument? VerifySignature(string envelopeJson, out string? error) =>
        VerifyCore(envelopeJson, legacyRevocationOnly: false, out error);

    // Bu dar yol public ticari Verify tarafından çağrılmaz.
    internal LicenseDocument? ReadRevocationDocument(string envelopeJson)
    {
        var document = VerifyCore(envelopeJson, legacyRevocationOnly: true, out _);
        return document is { Status: "revoked" } ? document : null;
    }

    private LicenseDocument? VerifyCore(string envelopeJson, bool legacyRevocationOnly, out string? error)
    {
        error = null;
        try
        {
            using var envelope = JsonDocument.Parse(envelopeJson);
            var root = envelope.RootElement;

            if (!root.TryGetProperty("v", out var v) || (v.GetInt32() != SupportedVersion && !(legacyRevocationOnly && v.GetInt32() == 1)))
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
            if (document is null || document.Version != v.GetInt32())
                return Fail("bad_payload", out error);
            if (document.Version == 1)
                return document.Status == "revoked" && document.IssuedAt != default
                    ? document : Fail("unsupported_version", out error);

            using var content = JsonDocument.Parse(payload);
            var fields = content.RootElement;
            if (!fields.TryGetProperty("license_type", out var type) || type.ValueKind != JsonValueKind.String
                || !fields.TryGetProperty("expires_at", out var expiry)
                || !ValidV2(document, expiry))
                return Fail("invalid_license_term", out error);

            return document;
        }
        catch (Exception e) when (e is JsonException or FormatException or KeyNotFoundException or InvalidOperationException or ArgumentOutOfRangeException)
        {
            return Fail("malformed", out error);
        }
    }

    private static bool ValidV2(LicenseDocument document, JsonElement expiry)
    {
        if (document.LicenseType == "perpetual")
        {
            if (expiry.ValueKind != JsonValueKind.Null || document.Status == "demo") return false;
        }
        else if (document.LicenseType == "term")
        {
            if (expiry.ValueKind != JsonValueKind.String
                || !DateOnly.TryParseExact(document.ExpiresAt, "yyyy-MM-dd", System.Globalization.CultureInfo.InvariantCulture,
                    System.Globalization.DateTimeStyles.None, out var day)
                || day == DateOnly.MaxValue) return false;
        }
        else return false;

        if (document.IssuedAt == default || document.ValidUntil == default
            || document.OfflineGraceDays is not (7 or 365)
            || (document.Status == "demo" && (document.OfflineGraceDays != 7 || document.DeviceLimit != 1))) return false;

        return document.ValidUntil <= document.IssuedAt.AddDays(document.OfflineGraceDays)
            && (document.LicenseEndUtc is not { } end || document.ValidUntil <= end);
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
