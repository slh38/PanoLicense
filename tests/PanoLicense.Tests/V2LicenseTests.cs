using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;
using PanoLicense.Client;

namespace PanoLicense.Tests;

public class V2LicenseTests
{
    private const string Key = "PAN-V200-0000-0001";
    private const string Challenge = "webapi-v2-challenge-123456";

    private static Dictionary<string, string> Keys(TestDevice d) => new() { [FakeLicenseServer.Kid] = d.Server.PublicKeyPem };
    private static LicenseIdentity Identity(TestDevice d)
    {
        using var key = d.Keys.OpenOrCreate();
        return new(FakeLicenseServer.Product, Key, d.Fingerprint, DeviceProof.PublicKeyHash(key));
    }
    private static JsonObject Payload(string envelope)
    {
        var value = JsonNode.Parse(envelope)!["payload"]!.GetValue<string>().Replace('-', '+').Replace('_', '/');
        return JsonNode.Parse(Convert.FromBase64String(value.PadRight((value.Length + 3) / 4 * 4, '=')))!.AsObject();
    }
    private static FakeLicenseServer.License License(string type = "term", int days = 7, string status = "active") => new()
    {
        LicenseType = type, ExpiresAt = type == "perpetual" ? null : "2030-12-31",
        GraceDays = days, Status = status,
    };
    private static string Sign(TestDevice d, FakeLicenseServer.License license, DateTimeOffset? issued = null) =>
        d.Server.Sign(Key, license, d.Fingerprint, Identity(d).DeviceKeyHash, issued);

    [Theory]
    [InlineData("term", 7, "active")]
    [InlineData("term", 365, "active")]
    [InlineData("term", 7, "demo")]
    [InlineData("perpetual", 7, "active")]
    [InlineData("perpetual", 365, "active")]
    public void V2_preserves_type_fractional_time_and_finite_offline_bound(string type, int days, string status)
    {
        var d = new TestDevice();
        d.Time.Advance(TimeSpan.FromTicks(1234560));
        using var verifier = new LicenseDocumentVerifier(Keys(d));
        var doc = verifier.Verify(Sign(d, License(type, days, status)), Identity(d), out var error);
        Assert.NotNull(doc);
        Assert.Null(error);
        Assert.Equal(2, doc.Version);
        Assert.Equal(type, doc.LicenseType);
        Assert.Equal(d.Time.GetUtcNow(), doc.IssuedAt);
        Assert.Equal(doc.IssuedAt.AddDays(days), doc.AllowedUntil);
        Assert.Equal(type == "perpetual", doc.LicenseEndUtc is null);
        Assert.True(OfflineLicenseEvaluator.Evaluate(doc, Identity(d), doc.AllowedUntil.AddTicks(-1), null, false).AllowsCommercialOperations);
        Assert.False(OfflineLicenseEvaluator.Evaluate(doc, Identity(d), doc.AllowedUntil, null, false).AllowsCommercialOperations);
    }

    [Theory]
    [InlineData("missing_type")]
    [InlineData("null_type")]
    [InlineData("unknown_type")]
    [InlineData("missing_term_date")]
    [InlineData("null_term_date")]
    [InlineData("bad_date")]
    [InlineData("overflow_date")]
    [InlineData("perpetual_with_date")]
    [InlineData("missing_perpetual_null")]
    [InlineData("perpetual_demo")]
    [InlineData("demo_365")]
    [InlineData("unknown_policy")]
    [InlineData("oversized_window")]
    [InlineData("mismatched_version")]
    public void Malformed_signed_contract_is_rejected_without_throwing(string mutation)
    {
        var d = new TestDevice();
        var payload = Payload(Sign(d, License()));
        switch (mutation)
        {
            case "missing_type": payload.Remove("license_type"); break;
            case "null_type": payload["license_type"] = null; break;
            case "unknown_type": payload["license_type"] = "other"; break;
            case "missing_term_date": payload.Remove("expires_at"); break;
            case "null_term_date": payload["expires_at"] = null; break;
            case "bad_date": payload["expires_at"] = "2027-02-30"; break;
            case "overflow_date": payload["expires_at"] = "9999-12-31"; break;
            case "perpetual_with_date": payload["license_type"] = "perpetual"; break;
            case "missing_perpetual_null": payload["license_type"] = "perpetual"; payload.Remove("expires_at"); break;
            case "perpetual_demo": payload["license_type"] = "perpetual"; payload["expires_at"] = null; payload["status"] = "demo"; break;
            case "demo_365": payload["status"] = "demo"; payload["offline_grace_days"] = 365; break;
            case "unknown_policy": payload["offline_grace_days"] = 8; break;
            case "oversized_window": payload["valid_until"] = d.Time.GetUtcNow().AddDays(8).ToString("O"); break;
            case "mismatched_version": payload["v"] = 1; break;
        }
        using var verifier = new LicenseDocumentVerifier(Keys(d));
        Assert.Null(verifier.Verify(d.Server.SignPayload(payload, 2), Identity(d), out _));
    }

    [Fact]
    public void V1_active_never_grants_proof_or_offline_right_but_v1_revocation_is_readable()
    {
        var d = new TestDevice();
        var payload = Payload(Sign(d, License()));
        payload["v"] = 1;
        payload.Remove("license_type");
        var active = d.Server.SignPayload(payload);
        using var verifier = new LicenseDocumentVerifier(Keys(d));
        using var proofVerifier = new CommercialProofVerifier(Keys(d), FakeLicenseServer.Product);
        Assert.Null(verifier.Verify(active, Identity(d), out var error));
        Assert.Equal("unsupported_version", error);
        Assert.Null(proofVerifier.ReadRevocation(active));
        var raw = JsonSerializer.Deserialize<LicenseDocument>(payload.ToJsonString())!;
        Assert.False(OfflineLicenseEvaluator.Evaluate(raw, Identity(d), d.Time.GetUtcNow(), null, false).AllowsCommercialOperations);
        using var key = d.Keys.OpenOrCreate();
        var message = CommercialProofFormat.BuildMessage(Challenge, FakeLicenseServer.Product, Key, Identity(d).DeviceKeyHash, active);
        var signature = Convert.ToBase64String(key.SignData(Encoding.UTF8.GetBytes(message), HashAlgorithmName.SHA256, DSASignatureFormat.Rfc3279DerSequence));
        Assert.False(proofVerifier.Verify(Challenge, active, key.ExportSubjectPublicKeyInfoPem(), signature, d.Time.GetUtcNow()).Valid);
        payload["status"] = "revoked";
        var revoked = d.Server.SignPayload(payload);
        Assert.NotNull(proofVerifier.ReadRevocation(revoked));
        Assert.Null(verifier.Verify(revoked, Identity(d), out _));
        using var other = new CommercialProofVerifier(Keys(d), "OTHER");
        Assert.Null(other.ReadRevocation(revoked));
        var tampered = JsonNode.Parse(revoked)!;
        tampered["sig"] = "AA";
        Assert.Null(proofVerifier.ReadRevocation(tampered.ToJsonString()));
    }

    [Theory]
    [InlineData(7)]
    [InlineData(365)]
    public async Task Perpetual_manager_proof_restart_and_offline_expiry(int days)
    {
        var d = new TestDevice();
        d.Server.Licenses[Key] = License("perpetual", days);
        Assert.Null(d.Create().Current.LicenseType);
        await using (var manager = await d.StartAsync())
        {
            Assert.True((await manager.ActivateAsync(Key)).Succeeded);
            Assert.Equal("perpetual", manager.Current.LicenseType);
            Assert.Null(manager.Current.LicenseExpiresOn);
            var proof = manager.CreateCommercialProof(Challenge).Proof!;
            using var verifier = new CommercialProofVerifier(Keys(d), FakeLicenseServer.Product);
            Assert.True(verifier.Verify(Challenge, proof.DocumentEnvelope, proof.DevicePublicKeyPem, proof.Signature, d.Time.GetUtcNow()).Valid);
        }
        d.Server.Override = _ => FakeLicenseServer.Error(503, "unavailable");
        d.Time.Restart(TestDevice.T0.AddHours(1));
        await using var restarted = await d.StartAsync();
        Assert.True(restarted.AllowsCommercialOperations);
        d.Time.Advance(TimeSpan.FromDays(days));
        Assert.False(restarted.AllowsCommercialOperations);
        Assert.False(restarted.CreateCommercialProof(Challenge).Succeeded);
    }

    [Fact]
    public async Task Conversion_rejects_older_term_document_and_preserves_revocation_after_restart()
    {
        var d = new TestDevice();
        d.Server.Licenses[Key] = License();
        string old;
        await using (var manager = await d.StartAsync())
        {
            Assert.True((await manager.ActivateAsync(Key)).Succeeded);
            old = manager.CreateCommercialProof(Challenge).Proof!.DocumentEnvelope;
            d.Time.Advance(TimeSpan.FromTicks(10));
            d.Server.Licenses[Key].LicenseType = "perpetual";
            d.Server.Licenses[Key].ExpiresAt = null;
            await manager.CheckNowAsync();
            Assert.Null(manager.Current.LastError);
            d.Server.Override = path => path == "check" ? FakeLicenseServer.Json(200, new JsonObject
                { ["license_key"] = Key, ["license_document"] = JsonNode.Parse(old) }) : null;
            await manager.CheckNowAsync();
            Assert.Equal("stale_document", manager.Current.LastError);
            Assert.Equal("perpetual", manager.Current.LicenseType);
            d.Server.Override = null;
            d.Time.Advance(TimeSpan.FromTicks(10));
            d.Server.Licenses[Key].Status = "revoked";
            await manager.CheckNowAsync();
            Assert.False(manager.AllowsCommercialOperations);
        }
        d.Server.Override = _ => FakeLicenseServer.Error(503, "offline");
        d.Time.Restart(d.Time.GetUtcNow().AddMinutes(1));
        await using var restarted = await d.StartAsync();
        Assert.Equal(LicenseState.Revoked, restarted.Current.State);
        Assert.NotNull(restarted.GetSignedRevocationDocument());
        Assert.False(restarted.CreateCommercialProof(Challenge).Succeeded);
    }

    [Fact]
    public async Task Legacy_signed_revocation_and_security_state_survive_upgrade_without_reset()
    {
        var d = new TestDevice();
        d.Server.Licenses[Key] = License();
        await using (var manager = await d.StartAsync())
            Assert.True((await manager.ActivateAsync(Key)).Succeeded);
        var payload = Payload(Sign(d, License(status: "revoked")));
        payload["v"] = 1;
        payload.Remove("license_type");
        var legacy = d.Server.SignPayload(payload);
        using var deviceKey = d.Keys.OpenOrCreate();
        var store = new ProtectedStateStore([d.File, d.Registry]);
        var state = store.Load(deviceKey, Identity(d).DeviceKeyHash, FakeLicenseServer.Product, out _)!;
        Assert.True(store.Save(state with { Document = legacy, Revoked = [Key] }, deviceKey, Identity(d).DeviceKeyHash));
        d.Server.Override = _ => FakeLicenseServer.Error(503, "offline");
        await using var upgraded = await d.StartAsync();
        Assert.Equal(LicenseState.Revoked, upgraded.Current.State);
        Assert.Equal(legacy, upgraded.GetSignedRevocationDocument());
        Assert.Null(upgraded.Current.LicenseType);
        var after = store.Load(deviceKey, Identity(d).DeviceKeyHash, FakeLicenseServer.Product, out _)!;
        Assert.Contains(Key, after.Revoked);
        Assert.True(after.Watermark >= state.Watermark);
        Assert.Equal(state.NewestIssuedAt, after.NewestIssuedAt);
        Assert.False(upgraded.CreateCommercialProof(Challenge).Succeeded);
    }
    [Fact]
    public async Task Persisted_active_v1_is_not_commercial_and_security_watermarks_are_not_reset()
    {
        var d = new TestDevice();
        d.Server.Licenses[Key] = License();
        await using (var manager = await d.StartAsync())
            Assert.True((await manager.ActivateAsync(Key)).Succeeded);
        using var deviceKey = d.Keys.OpenOrCreate();
        var store = new ProtectedStateStore([d.File, d.Registry]);
        var state = store.Load(deviceKey, Identity(d).DeviceKeyHash, FakeLicenseServer.Product, out _)!;
        var payload = Payload(state.Document!);
        payload["v"] = 1;
        payload.Remove("license_type");
        Assert.True(store.Save(state with { Document = d.Server.SignPayload(payload) }, deviceKey, Identity(d).DeviceKeyHash));
        d.Server.Override = _ => FakeLicenseServer.Error(503, "offline");
        await using var upgraded = await d.StartAsync();
        Assert.False(upgraded.AllowsCommercialOperations);
        Assert.False(upgraded.CreateCommercialProof(Challenge).Succeeded);
        Assert.Null(upgraded.Current.LicenseType);
        var after = store.Load(deviceKey, Identity(d).DeviceKeyHash, FakeLicenseServer.Product, out _)!;
        Assert.Equal(state.NewestIssuedAt, after.NewestIssuedAt);
        Assert.True(after.Watermark >= state.Watermark);
        Assert.NotNull(after.Document);
    }

    [Fact]
    public async Task Perpetual_clock_rollback_does_not_extend_the_trusted_offline_deadline()
    {
        var d = new TestDevice();
        d.Server.Licenses[Key] = License("perpetual", 365);
        await using var manager = await d.StartAsync();
        Assert.True((await manager.ActivateAsync(Key)).Succeeded);
        var deadline = manager.Current.CommercialAllowedUntil;
        using var verifier = new LicenseDocumentVerifier(Keys(d));
        var doc = verifier.Verify(Sign(d, d.Server.Licenses[Key]), Identity(d), out _)!;
        var rollback = OfflineLicenseEvaluator.Evaluate(doc, Identity(d), TestDevice.T0.AddHours(-1), null, false);
        Assert.Equal("clock_rollback", rollback.Reason);
        Assert.False(rollback.AllowsCommercialOperations);
        // Online çapasına sahip süreç monoton saati kullanır; duvar saati geri alınsa da süre kazanılmaz.
        d.Time.SetWall(TestDevice.T0.AddHours(-1));
        manager.RunLocalCheck();
        Assert.Equal(deadline, manager.Current.CommercialAllowedUntil);
        Assert.Equal(365, manager.Current.OfflineGraceDays);
        d.Time.Advance(TimeSpan.FromDays(365));
        Assert.False(manager.AllowsCommercialOperations);
        Assert.False(manager.CreateCommercialProof(Challenge).Succeeded);
    }
}