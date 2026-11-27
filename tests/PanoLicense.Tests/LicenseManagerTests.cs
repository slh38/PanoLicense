using System.Runtime.Versioning;
using System.Security.Cryptography;
using System.Text;
using PanoLicense.Client;
using Xunit.Abstractions;

namespace PanoLicense.Tests;

/// <summary>F4 kabul senaryoları (docs/TESTING.md): saatler sahte, gerçek bekleme yok.</summary>
public class LicenseManagerTests
{
    private const string Key = "PAN-AAAA-BBBB-CCCC";

    private static TestDevice WithLicense(int graceDays = 7, string expires = "2027-12-31")
    {
        var d = new TestDevice();
        d.Server.Licenses[Key] = new FakeLicenseServer.License { GraceDays = graceDays, ExpiresAt = expires };
        return d;
    }

    private static async Task<LicenseManager> Activated(TestDevice d)
    {
        var m = await d.StartAsync();
        var result = await m.ActivateAsync(" pan-aaaa-bbbb-cccc ");
        Assert.True(result.Succeeded, result.ErrorCode);
        return m;
    }

    private static void GoOffline(TestDevice d) =>
        d.Server.Override = _ => throw new HttpRequestException("ağ yok");

    [Fact]
    public async Task Activation_uses_challenge_proof_and_gives_active_license()
    {
        var d = WithLicense();
        await using var m = await Activated(d);

        Assert.Equal(LicenseState.Active, m.Current.State);
        Assert.True(m.AllowsCommercialOperations);
        Assert.Equal(Key, m.Current.LicenseKey);
        Assert.Equal("Ada Market", m.Current.CustomerName);
        Assert.Equal(1, d.Server.Calls("challenge"));
        Assert.NotNull(d.File.Data);
        Assert.NotNull(d.Registry.Data);
    }

    [Fact]
    public async Task Restart_without_network_uses_signed_offline_grace_then_stops_at_valid_until()
    {
        var d = WithLicense();
        await (await Activated(d)).DisposeAsync();
        GoOffline(d);

        d.Time.Restart(TestDevice.T0.AddDays(3));
        await using var m = await d.StartAsync();
        Assert.Equal(LicenseState.OfflineGrace, m.Current.State);
        Assert.Equal(TestDevice.T0.AddDays(7), m.Current.CommercialAllowedUntil);

        // İzin bitişinde saatlik kontrolü beklemeden kapanır.
        d.Time.Advance(TimeSpan.FromDays(4));
        Assert.False(m.AllowsCommercialOperations);
        Assert.Equal(LicenseState.OfflineExpired, await m.GetStateAsync());
        m.RunLocalCheck();
        Assert.Equal(LicenseState.OfflineExpired, m.Current.State);
        Assert.Equal("offline_grace_exceeded", m.Current.Reason);
    }

    [Fact]
    public async Task Special_365_day_policy_keeps_working_offline_for_months()
    {
        var d = WithLicense(graceDays: 365);
        await (await Activated(d)).DisposeAsync();
        GoOffline(d);

        d.Time.Restart(TestDevice.T0.AddDays(300));
        await using var m = await d.StartAsync();
        Assert.Equal(LicenseState.OfflineGrace, m.Current.State);
        Assert.Equal(365, m.Current.OfflineGraceDays);
        Assert.True(m.AllowsCommercialOperations);
    }

    [Fact]
    public async Task Offline_grace_never_exceeds_license_end()
    {
        var d = WithLicense(graceDays: 365, expires: "2026-12-01");
        await (await Activated(d)).DisposeAsync();
        GoOffline(d);

        d.Time.Restart(DateTimeOffset.Parse("2026-12-02T00:00:01Z"));
        await using var m = await d.StartAsync();
        Assert.Equal(LicenseState.Expired, m.Current.State);
        Assert.False(m.AllowsCommercialOperations);
    }

    [Fact]
    public async Task Clock_rollback_at_startup_requires_online_check()
    {
        var d = WithLicense();
        await (await Activated(d)).DisposeAsync();
        GoOffline(d);

        d.Time.Restart(TestDevice.T0.AddDays(2));
        await (await d.StartAsync()).DisposeAsync(); // watermark +2 gün

        d.Time.Restart(TestDevice.T0.AddDays(1)); // kullanıcı saati 1 gün geri aldı
        await using var m = await d.StartAsync();
        Assert.Equal(LicenseState.OfflineExpired, m.Current.State);
        Assert.Equal("clock_rollback", m.Current.Reason);

        // İnternet gelince imzalı sunucu zamanıyla kontrollü iyileşme.
        d.Server.Override = null;
        await m.CheckNowAsync();
        Assert.Equal(LicenseState.Active, m.Current.State);
    }

    [Fact]
    public async Task Frozen_or_slightly_rolled_back_clock_does_not_stop_time_in_session()
    {
        var d = WithLicense();
        await using var m = await Activated(d);
        GoOffline(d);

        // Duvar saati sabit, gerçek zaman (monoton) 8 gün ilerliyor.
        for (var i = 0; i < 8 * 24; i++)
        {
            d.Time.Advance(TimeSpan.FromHours(1));
            d.Time.SetWall(TestDevice.T0.AddMinutes(-4)); // tolerans içinde geri: her seferinde süre kazanamaz
        }
        Assert.False(m.AllowsCommercialOperations);
        m.RunLocalCheck();
        Assert.False(m.Current.AllowsCommercialOperations);
    }

    [Fact]
    public async Task Signed_revocation_is_permanent_even_if_old_state_copy_is_restored()
    {
        var d = WithLicense();
        await using (var first = await Activated(d))
        {
            var oldFile = d.File.Data!.ToArray();
            d.Server.Licenses[Key].Status = "revoked";
            d.Time.Advance(TimeSpan.FromHours(6));
            await first.CheckNowAsync();
            Assert.Equal(LicenseState.Revoked, first.Current.State);

            d.File.Data = oldFile; // eski aktif belgeli yedek geri yüklendi
        }

        GoOffline(d);
        d.Time.Restart(TestDevice.T0.AddDays(1));
        await using var m = await d.StartAsync();
        Assert.Equal(LicenseState.Revoked, m.Current.State);
        Assert.False(m.AllowsCommercialOperations);
    }

    [Fact]
    public async Task Revocation_stays_closed_in_memory_when_persisting_fails()
    {
        var d = WithLicense();
        await using var m = await Activated(d);
        d.File.Fail = d.Registry.Fail = true;
        d.Server.Licenses[Key].Status = "revoked";

        await m.CheckNowAsync();

        Assert.Equal(LicenseState.Revoked, m.Current.State);
        Assert.Equal("state_write_failed", m.Current.LastError);
    }

    [Fact]
    public async Task Definitive_denial_is_not_a_connection_error_and_survives_restart()
    {
        var d = WithLicense();
        await (await Activated(d)).DisposeAsync();
        d.Server.Licenses[Key].Devices.Clear(); // yetkili cihazı serbest bıraktı

        await using (var m = await d.StartAsync())
        {
            await m.CheckNowAsync();
            Assert.Equal("device_not_activated", m.Current.Reason);
            Assert.False(m.AllowsCommercialOperations);
            Assert.Equal(0, m.Scheduler.ConsecutiveFailures); // kademeli tekrar yok
        }

        GoOffline(d);
        d.Time.Restart(TestDevice.T0.AddHours(1));
        await using (var m = await d.StartAsync())
            Assert.Equal("device_not_activated", m.Current.Reason); // eski olumlu belgeye dönülmez

        // Kanıtlı yeniden aktivasyon daha yeni imzalı belgeyle açar.
        d.Server.Override = null;
        d.Time.Advance(TimeSpan.FromMinutes(5));
        await using (var m = await d.StartAsync())
        {
            Assert.True((await m.ActivateAsync(Key)).Succeeded);
            Assert.Equal(LicenseState.Active, m.Current.State);
        }
    }

    [Fact]
    public async Task Connection_errors_keep_offline_right_and_back_off_without_extending_it()
    {
        var d = WithLicense();
        await using var m = await Activated(d);
        var until = m.Current.CommercialAllowedUntil;

        d.Server.Override = p => FakeLicenseServer.Error(503, "signing_unavailable");
        await m.CheckNowAsync();
        Assert.Equal(LicenseState.OfflineGrace, m.Current.State);
        Assert.Equal(until, m.Current.CommercialAllowedUntil);
        Assert.Equal(1, m.Scheduler.ConsecutiveFailures);

        d.Server.Override = p => new HttpResponseMessage(System.Net.HttpStatusCode.OK)
            { Content = new StringContent("<html>giriş</html>", Encoding.UTF8, "text/html") }; // yakalama portalı
        await m.CheckNowAsync();
        Assert.Equal(LicenseState.OfflineGrace, m.Current.State);
        Assert.Equal(2, m.Scheduler.ConsecutiveFailures);
    }

    [Fact]
    public async Task Wrong_key_activation_does_not_break_current_license()
    {
        var d = WithLicense();
        await using var m = await Activated(d);

        var result = await m.ActivateAsync("PAN-YOKK-YOKK-YOKK");

        Assert.Equal(LicenseCallOutcome.Denied, result.Outcome);
        Assert.Equal("license_not_found", result.ErrorCode);
        Assert.Equal(LicenseState.Active, m.Current.State);
    }

    [Fact]
    public async Task Concurrent_triggers_produce_a_single_online_check()
    {
        var d = WithLicense();
        await using var m = await Activated(d);
        d.Server.Gate = new TaskCompletionSource();

        var checks = Enumerable.Range(0, 5).Select(_ => m.CheckNowAsync()).ToArray();
        m.NotifyNetworkAvailable();
        Assert.True(m.AllowsCommercialOperations); // satış ağ sonucunu beklemez
        d.Server.Gate.SetResult();
        await Task.WhenAll(checks);

        Assert.Equal(1, d.Server.Calls("check"));
    }

    [Fact]
    public async Task Tampered_or_copied_state_is_ignored()
    {
        var d = WithLicense();
        await (await Activated(d)).DisposeAsync();
        GoOffline(d);

        // Başka cihaz anahtarı (kopyalanmış durum dosyaları): kabul edilmez.
        await using (var other = await d.StartAsync(new MemoryKeyStore()))
            Assert.Equal("not_activated", other.Current.Reason);

        // Bozulmuş yuvalar: offline hak yeniden başlamaz.
        d.File.Data![^5] ^= 1;
        d.Registry.Data![^5] ^= 1;
        await using var m = await d.StartAsync();
        Assert.False(m.AllowsCommercialOperations);
    }

    [Fact]
    public async Task Older_signed_document_cannot_replace_newer_one()
    {
        var d = WithLicense();
        await using var m = await Activated(d);
        d.Time.Advance(TimeSpan.FromHours(6));
        await m.CheckNowAsync();

        // Sunucu (veya araya giren) eski tarihli, geçerli imzalı belge döndürürse kabul edilmez.
        var stale = d.Server.Sign(Key, d.Server.Licenses[Key], d.Fingerprint, KeyHash(d), TestDevice.T0);
        d.Server.Override = p => p == "check"
            ? FakeLicenseServer.Json(200, new System.Text.Json.Nodes.JsonObject { ["license_document"] = System.Text.Json.Nodes.JsonNode.Parse(stale) })
            : null;
        var before = m.Current.LastOnlineAt;

        await m.CheckNowAsync();

        Assert.Equal("stale_document", m.Current.LastError);
        Assert.Equal(before, m.Current.LastOnlineAt);
    }

    [Fact]
    public async Task Demo_registration_returns_demo_state()
    {
        var d = new TestDevice();
        await using var m = await d.StartAsync();

        var result = await m.RegisterDemoAsync(new DemoRegistration("Deneme Büfe", InstallerUsername: "i.simsek"));

        Assert.True(result.Succeeded, result.ErrorCode);
        Assert.Equal(LicenseState.Demo, m.Current.State);
        Assert.StartsWith("PAN-DEMO", m.Current.LicenseKey);
        Assert.True(m.AllowsCommercialOperations);
    }

    [Fact]
    public void Challenge_is_signed_only_when_every_field_matches()
    {
        var device = new DeviceIdentity(new string('a', 64), "pem", new string('b', 64), null, null);
        string Message(string field, string value)
        {
            var values = new Dictionary<string, string>
            {
                ["purpose"] = "panolicense-device-proof-v1", ["challenge_id"] = "c1", ["operation"] = "check",
                ["product"] = "PANOPOS", ["license_key"] = Key, ["fingerprint"] = device.Fingerprint,
                ["device_key_hash"] = device.KeyHash,
            };
            values[field] = value;
            return System.Text.Json.JsonSerializer.Serialize(values);
        }

        Assert.True(LicenseApiClient.ChallengeMatches(Message("purpose", "panolicense-device-proof-v1"), "c1", "check", "PANOPOS", Key, device));
        foreach (var field in new[] { "purpose", "challenge_id", "operation", "product", "license_key", "fingerprint", "device_key_hash" })
            Assert.False(LicenseApiClient.ChallengeMatches(Message(field, "x"), "c1", "check", "PANOPOS", Key, device));
        Assert.False(LicenseApiClient.ChallengeMatches("not json", "c1", "check", "PANOPOS", Key, device));
    }

    private static string KeyHash(TestDevice d)
    {
        using var key = d.Keys.OpenOrCreate();
        return DeviceProof.PublicKeyHash(key);
    }
}

public class CheckSchedulerTests
{
    private static readonly DateTimeOffset Now = DateTimeOffset.Parse("2026-11-27T10:00:00Z");

    [Fact]
    public void Retry_starts_at_15_minutes_and_backs_off_to_six_hours()
    {
        var expected = new[] { 15, 30, 60, 120, 240, 360, 360 };
        for (var i = 0; i < expected.Length; i++)
            Assert.Equal(TimeSpan.FromMinutes(expected[i]), CheckScheduler.RetryDelay(i));
    }

    [Fact]
    public void Jitter_is_small_and_bounded()
    {
        var max = new CheckScheduler(() => 1);
        max.OnFailure(Now);
        Assert.Equal(Now + TimeSpan.FromMinutes(15) + TimeSpan.FromMinutes(1.5), max.NextOnlineAt);
        max.OnSuccess(Now);
        Assert.Equal(Now + TimeSpan.FromHours(6) + TimeSpan.FromMinutes(5), max.NextOnlineAt);

        var none = new CheckScheduler(() => 0);
        none.OnSuccess(Now);
        Assert.Equal(Now + TimeSpan.FromHours(6), none.NextOnlineAt);
    }

    [Fact]
    public void Network_return_skips_backoff_even_after_success()
    {
        var s = new CheckScheduler(() => 0);
        s.OnFailure(Now);
        s.OnFailure(Now);
        Assert.True(s.OnNetworkRestored(Now.AddMinutes(1)));
        Assert.Equal(Now.AddMinutes(1), s.NextOnlineAt);

        s.OnSuccess(Now);
        Assert.True(s.OnNetworkRestored(Now.AddMinutes(1)));
        Assert.Equal(Now.AddMinutes(1), s.NextOnlineAt);
    }

    [Fact]
    public void Startup_honours_last_online_time_and_urgent_need_does_not_break_backoff()
    {
        var s = new CheckScheduler(() => 0);
        s.Start(Now, Now.AddHours(-2));
        Assert.Equal(Now.AddHours(4), s.NextOnlineAt);
        s.RequestSoon(Now);
        Assert.Equal(Now, s.NextOnlineAt);

        s.OnFailure(Now);
        s.RequestSoon(Now.AddMinutes(1));
        Assert.Equal(Now.AddMinutes(15), s.NextOnlineAt); // hata beklemesi korunur
    }
}

[SupportedOSPlatform("windows")]
public class CngDeviceKeyStoreTests(ITestOutputHelper output)
{
    [Fact]
    public void Tpm_is_used_when_available_otherwise_software_fallback_works()
    {
        if (!OperatingSystem.IsWindows()) return;
        var store = new CngDeviceKeyStore($"PanoLicense-Test-{Guid.NewGuid():N}", allowTpm: true);
        try
        {
            using var key = store.OpenOrCreate();
            var sig = Convert.FromBase64String(DeviceProof.Sign(key, "tpm"));
            Assert.True(key.VerifyData(Encoding.UTF8.GetBytes("tpm"), sig, HashAlgorithmName.SHA256, DSASignatureFormat.Rfc3279DerSequence));
            Assert.Equal(64, DeviceProof.PublicKeyHash(key).Length);
            output.WriteLine($"TPM: {store.IsHardwareBacked}");
        }
        finally
        {
            store.Delete();
        }
    }

    [Fact]
    public void Software_cng_key_is_persistent_p256_and_not_exportable()
    {
        if (!OperatingSystem.IsWindows()) return;
        var store = new CngDeviceKeyStore($"PanoLicense-Test-{Guid.NewGuid():N}", allowTpm: false);
        try
        {
            string hash;
            using (var key = store.OpenOrCreate())
            {
                hash = DeviceProof.PublicKeyHash(key);
                Assert.Throws<CryptographicException>(() => key.ExportParameters(true));
                var sig = Convert.FromBase64String(DeviceProof.Sign(key, "mesaj"));
                Assert.True(key.VerifyData(Encoding.UTF8.GetBytes("mesaj"), sig, HashAlgorithmName.SHA256, DSASignatureFormat.Rfc3279DerSequence));
            }
            using var reopened = store.OpenOrCreate();
            Assert.Equal(hash, DeviceProof.PublicKeyHash(reopened)); // aynı anahtar yeniden açılır
        }
        finally
        {
            store.Delete();
        }
    }
}
