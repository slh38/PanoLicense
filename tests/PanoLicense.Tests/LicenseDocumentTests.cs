using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;
using PanoLicense.Client;

namespace PanoLicense.Tests;

/// <summary>
/// Fixtures/php-es256-documents.json: PHP openssl_sign ile Backend ES256 sözleşmesine göre üretilmiş izole test belgeleri.
/// Bu testler PHP imzasının .NET'te doğrulandığını (dil arası uyum) ve offline kurallarını kanıtlar.
/// </summary>
public class LicenseDocumentTests : IDisposable
{
    private readonly JsonNode _fixture;
    private readonly LicenseDocumentVerifier _verifier;
    private readonly string _fingerprint;

    public LicenseDocumentTests()
    {
        _fixture = JsonNode.Parse(File.ReadAllText(Path.Combine(AppContext.BaseDirectory, "Fixtures", "php-es256-documents.json")))!;
        _verifier = new LicenseDocumentVerifier(new Dictionary<string, string>
        {
            [_fixture["kid"]!.GetValue<string>()] = _fixture["public_key_pem"]!.GetValue<string>(),
        });
        _fingerprint = _fixture["fingerprint"]!.GetValue<string>();
    }

    private LicenseIdentity Identity => new("PANOPOS", "PAN-FIX0-0000-0001", _fingerprint,
        _fixture["device_key_hash"]!.GetValue<string>());

    public void Dispose() => _verifier.Dispose();

    private string Envelope(string name) => _fixture[name]!.ToJsonString();

    private LicenseDocument Doc(string name) =>
        _verifier.Verify(Envelope(name), Identity, out var error) ?? throw new Xunit.Sdk.XunitException($"Doğrulanamadı: {error}");

    private static DateTimeOffset At(string iso) => DateTimeOffset.Parse(iso);

    [Theory]
    [InlineData("active", "active", 3)]
    [InlineData("demo", "demo", 1)]
    [InlineData("revoked", "revoked", 3)]
    public void Php_signed_documents_verify_in_dotnet(string name, string status, int deviceLimit)
    {
        var doc = Doc(name);

        Assert.Equal(status, doc.Status);
        Assert.Equal(deviceLimit, doc.DeviceLimit);
        Assert.Equal("Yıldız Büfe", doc.Customer);
        Assert.Equal(At("2026-11-27T10:00:00Z"), doc.IssuedAt);
        Assert.Equal(At("2026-12-04T10:00:00Z"), doc.ValidUntil);
    }

    [Fact]
    public void Tampered_payload_is_rejected()
    {
        var env = JsonNode.Parse(Envelope("active"))!;
        var payload = JsonNode.Parse(Base64UrlDecode(env["payload"]!.GetValue<string>()))!;
        payload["valid_until"] = "2099-01-01T00:00:00Z";
        env["payload"] = Base64UrlEncode(payload.ToJsonString());

        Assert.Null(_verifier.Verify(env.ToJsonString(), Identity, out var error));
        Assert.Equal("bad_signature", error);
    }

    [Fact]
    public void Signature_from_another_document_is_rejected()
    {
        var env = JsonNode.Parse(Envelope("revoked"))!;
        env["sig"] = JsonNode.Parse(Envelope("active"))!["sig"]!.GetValue<string>();

        Assert.Null(_verifier.Verify(env.ToJsonString(), Identity, out var error));
        Assert.Equal("bad_signature", error);
    }

    [Theory]
    [InlineData("kid", "baska", "unknown_kid")]
    [InlineData("alg", "none", "unsupported_alg")]
    [InlineData("alg", "HS256", "unsupported_alg")]
    public void Unknown_key_or_algorithm_is_rejected(string field, string value, string expected)
    {
        var env = JsonNode.Parse(Envelope("active"))!;
        env[field] = value;

        Assert.Null(_verifier.Verify(env.ToJsonString(), Identity, out var error));
        Assert.Equal(expected, error);
    }

    [Fact]
    public void Garbage_input_is_rejected_without_throwing()
    {
        Assert.Null(_verifier.Verify("{bozuk", Identity, out var error));
        Assert.Equal("malformed", error);
    }

    // --- 7 günlük offline kuralı (belge 27.11 10:00'da alındı, valid_until 04.12 10:00) ---

    [Fact]
    public void Online_active_document_is_active()
    {
        var r = OfflineLicenseEvaluator.Evaluate(Doc("active"), Identity, At("2026-11-27T10:00:05Z"), null, online: true);

        Assert.Equal(LicenseState.Active, r.State);
        Assert.True(r.AllowsCommercialOperations);
    }

    [Fact]
    public void Offline_within_seven_days_is_grace_with_remaining_time()
    {
        var r = OfflineLicenseEvaluator.Evaluate(Doc("active"), Identity, At("2026-12-01T10:00:00Z"), At("2026-11-30T09:00:00Z"), online: false);

        Assert.Equal(LicenseState.OfflineGrace, r.State);
        Assert.Equal(TimeSpan.FromDays(3), r.OfflineRemaining);
        Assert.True(r.AllowsCommercialOperations);
    }

    [Fact]
    public void After_seven_days_offline_commercial_operations_stop()
    {
        var r = OfflineLicenseEvaluator.Evaluate(Doc("active"), Identity, At("2026-12-04T10:00:00Z"), null, online: false);

        Assert.Equal(LicenseState.OfflineExpired, r.State);
        Assert.Equal("offline_grace_exceeded", r.Reason);
        Assert.False(r.AllowsCommercialOperations);
    }

    [Theory]
    [InlineData("2026-11-27T09:00:00Z", null)]                    // belgenin üretiminden önceye
    [InlineData("2026-11-29T10:00:00Z", "2026-12-02T10:00:00Z")]  // daha önce görülen ana göre geri
    public void Clock_rollback_requires_online_check(string now, string? lastSeen)
    {
        var r = OfflineLicenseEvaluator.Evaluate(Doc("active"), Identity, At(now), lastSeen is null ? null : At(lastSeen), online: false);

        Assert.Equal(LicenseState.OfflineExpired, r.State);
        Assert.True(r.ClockRollbackDetected);
        Assert.False(r.AllowsCommercialOperations);
    }

    [Fact]
    public void Small_clock_drift_is_tolerated()
    {
        var r = OfflineLicenseEvaluator.Evaluate(Doc("active"), Identity, At("2026-11-27T09:57:00Z"), null, online: false);

        Assert.Equal(LicenseState.OfflineGrace, r.State);
    }

    [Fact]
    public void Document_for_another_device_is_not_accepted()
    {
        var r = OfflineLicenseEvaluator.Evaluate(Doc("active"), Identity with { Fingerprint = new string('0', 64) }, At("2026-11-28T10:00:00Z"), null, online: false);

        Assert.Equal(LicenseState.Unknown, r.State);
        Assert.Equal("identity_mismatch", r.Reason);
    }

    [Fact]
    public void Revoked_document_blocks_even_offline()
    {
        var r = OfflineLicenseEvaluator.Evaluate(Doc("revoked"), Identity, At("2026-11-28T10:00:00Z"), null, online: false);

        Assert.Equal(LicenseState.Revoked, r.State);
        Assert.False(r.AllowsCommercialOperations);
    }

    [Fact]
    public void Demo_is_allowed_until_demo_end()
    {
        var demo = Doc("demo"); // expires_at 2026-12-11

        Assert.Equal(LicenseState.Demo, OfflineLicenseEvaluator.Evaluate(demo, Identity, At("2026-11-30T10:00:00Z"), null, online: false).State);
        Assert.Equal(LicenseState.Expired, OfflineLicenseEvaluator.Evaluate(demo, Identity, At("2026-12-12T00:00:00Z"), null, online: true).State);
    }

    [Fact]
    public void Last_seen_never_moves_backwards()
    {
        var seen = At("2026-12-02T10:00:00Z");

        Assert.Equal(seen, OfflineLicenseEvaluator.NextLastSeen(At("2026-11-29T10:00:00Z"), seen));
        Assert.Equal(At("2026-12-03T10:00:00Z"), OfflineLicenseEvaluator.NextLastSeen(At("2026-12-03T10:00:00Z"), seen));
    }

    [Theory]
    [InlineData("product")]
    [InlineData("license")]
    [InlineData("fingerprint")]
    [InlineData("device_key")]
    public void Valid_signature_cannot_be_used_for_another_identity(string field)
    {
        var expected = field switch
        {
            "product" => Identity with { Product = "OTHER" },
            "license" => Identity with { LicenseKey = "PAN-OTHER" },
            "fingerprint" => Identity with { Fingerprint = new string('0', 64) },
            _ => Identity with { DeviceKeyHash = new string('0', 64) },
        };
        Assert.Null(_verifier.Verify(Envelope("active"), expected, out var error));
        Assert.Equal("identity_mismatch", error);
        var result = OfflineLicenseEvaluator.Evaluate(Doc("active"), expected, At("2026-11-28T10:00:00Z"), null, false);
        Assert.False(result.AllowsCommercialOperations);
    }

    [Fact]
    public void Device_proof_uses_p256_sha256_der_and_spki_hash()
    {
        using var key = System.Security.Cryptography.ECDsa.Create(System.Security.Cryptography.ECCurve.NamedCurves.nistP256);
        var signature = DeviceProof.Sign(key, "challenge-message");
        Assert.True(key.VerifyData(Encoding.UTF8.GetBytes("challenge-message"), Convert.FromBase64String(signature),
            System.Security.Cryptography.HashAlgorithmName.SHA256, System.Security.Cryptography.DSASignatureFormat.Rfc3279DerSequence));
        Assert.False(key.VerifyData(Encoding.UTF8.GetBytes("changed"), Convert.FromBase64String(signature),
            System.Security.Cryptography.HashAlgorithmName.SHA256, System.Security.Cryptography.DSASignatureFormat.Rfc3279DerSequence));
        Assert.Equal(Convert.ToHexString(System.Security.Cryptography.SHA256.HashData(key.ExportSubjectPublicKeyInfo())).ToLowerInvariant(),
            DeviceProof.PublicKeyHash(key));
        using var publicKey = System.Security.Cryptography.ECDsa.Create();
        publicKey.ImportFromPem(_fixture["device_public_key_pem"]!.GetValue<string>());
        Assert.Equal(Identity.DeviceKeyHash, DeviceProof.PublicKeyHash(publicKey));
    }
    [Fact]
    public void Special_offline_document_verifies_and_uses_signed_365_day_deadline()
    {
        var document = Doc("special");
        Assert.Equal(365, document.OfflineGraceDays);
        var before = OfflineLicenseEvaluator.Evaluate(document, Identity, document.ValidUntil.AddSeconds(-1), null, false);
        Assert.True(before.AllowsCommercialOperations);
        var atEnd = OfflineLicenseEvaluator.Evaluate(document, Identity, document.ValidUntil, null, false);
        Assert.False(atEnd.AllowsCommercialOperations);
        Assert.Equal("offline_grace_exceeded", atEnd.Reason);
        Assert.False(OfflineLicenseEvaluator.Evaluate(document, Identity,
            document.LicenseEndUtc!.Value, null, false).AllowsCommercialOperations);
    }

    [Fact]
    public void Tampering_offline_policy_breaks_signature_and_old_standard_defaults_to_seven()
    {
        Assert.Equal(7, Doc("active").OfflineGraceDays);
        var env = JsonNode.Parse(Envelope("special"))!;
        var payload = JsonNode.Parse(Base64UrlDecode(env["payload"]!.GetValue<string>()))!;
        payload["offline_grace_days"] = 7;
        env["payload"] = Base64UrlEncode(payload.ToJsonString());
        Assert.Null(_verifier.Verify(env.ToJsonString(), Identity, out var error));
        Assert.Equal("bad_signature", error);
    }
    private static string Base64UrlDecode(string s)
    {
        s = s.Replace('-', '+').Replace('_', '/');
        return Encoding.UTF8.GetString(Convert.FromBase64String(s.PadRight(s.Length + (4 - s.Length % 4) % 4, '=')));
    }

    private static string Base64UrlEncode(string s) =>
        Convert.ToBase64String(Encoding.UTF8.GetBytes(s)).TrimEnd('=').Replace('+', '-').Replace('/', '_');
}
