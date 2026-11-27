using PanoLicense.Client;

namespace PanoLicense.Tests;

public class F4CorrectionsTests
{
    private const string Key = "PAN-AAAA-BBBB-CCCC";

    private static async Task<(TestDevice Device, LicenseManager Manager)> Activated()
    {
        var d = new TestDevice();
        d.Server.Licenses[Key] = new FakeLicenseServer.License();
        var m = await d.StartAsync();
        Assert.True((await m.ActivateAsync(Key)).Succeeded);
        return (d, m);
    }

    private static void Offline(TestDevice d) =>
        d.Server.Override = _ => throw new HttpRequestException("offline");

    /// <summary>Kill/elektrik kesintisi modeli: normal Dispose'un yazımını kalıcı test yuvalarına uygulama.</summary>
    private static async Task Crash(TestDevice d, LicenseManager m)
    {
        var file = d.File.Data?.ToArray();
        var registry = d.Registry.Data?.ToArray();
        await m.DisposeAsync();
        d.File.Data = file;
        d.Registry.Data = registry;
    }

    [Fact, Trait("Finding", "1")]
    public async Task Any_state_write_failure_blocks_current_and_cached_commercial_permission()
    {
        var (d, m) = await Activated();
        await using (m)
        {
            var cached = m.Current;
            d.File.Fail = true; // Tek yuva bile başarısızsa süreçte izin kapalı.
            await m.CheckNowAsync();
            Assert.Equal("state_write_failed", m.Current.LastError);
            Assert.False(m.AllowsCommercialOperations);
            Assert.False(cached.AllowsCommercialOperations);
            Assert.False(m.Current.AllowsCommercialOperations);
            Assert.Equal(LicenseState.Unknown, await m.GetStateAsync());
            d.File.Fail = false;
            m.RunLocalCheck();
            Assert.True(m.AllowsCommercialOperations); // kalıcı yazım iyileşince normal hak
        }
    }

    [Fact, Trait("Finding", "1")]
    public async Task Durable_revocation_survives_crash_and_old_file_restoration()
    {
        var (d, m) = await Activated();
        var oldFile = d.File.Data!.ToArray();
        d.Server.Licenses[Key].Status = "revoked";
        await m.CheckNowAsync();
        await Crash(d, m);
        d.File.Data = oldFile; // kayıt defterindeki kalıcı iptal baskındır.
        Offline(d);
        d.Time.Restart(TestDevice.T0.AddHours(1));
        await using var restarted = await d.StartAsync();
        Assert.Equal(LicenseState.Revoked, restarted.Current.State);
        Assert.False(restarted.Current.AllowsCommercialOperations);
    }

    [Fact, Trait("Finding", "1")]
    public async Task Partial_write_keeps_durable_revocation_after_crash()
    {
        var (d, m) = await Activated();
        d.Registry.Fail = true;
        d.Server.Licenses[Key].Status = "revoked";
        await m.CheckNowAsync();
        Assert.Equal("state_write_failed", m.Current.LastError);
        await Crash(d, m);
        d.Registry.Fail = false;
        Offline(d);
        d.Time.Restart(TestDevice.T0.AddHours(1));
        await using var restarted = await d.StartAsync();
        Assert.Equal(LicenseState.Revoked, restarted.Current.State);
        Assert.False(restarted.AllowsCommercialOperations);
    }

    [Fact, Trait("Finding", "1")]
    public async Task Total_write_failure_blocks_process_but_unrecorded_crash_is_accepted_continuity_boundary()
    {
        var (d, m) = await Activated();
        d.File.Fail = d.Registry.Fail = true;
        d.Server.Licenses[Key].Status = "revoked";
        await m.CheckNowAsync();
        Assert.False(m.AllowsCommercialOperations);
        await Crash(d, m);
        d.File.Fail = d.Registry.Fail = false;
        Offline(d);
        d.Time.Restart(TestDevice.T0.AddHours(1));
        await using var restarted = await d.StartAsync();
        // Kullanıcı kararı: kalıcı iz bırakılamayan çökmede eski geçerli offline belge kilitlenmez.
        Assert.True(restarted.AllowsCommercialOperations);
    }

    [Fact, Trait("Finding", "2")]
    public async Task Clean_shutdown_saves_elapsed_time_for_short_frozen_clock_sessions()
    {
        var (d, m) = await Activated();
        Offline(d);
        d.Time.Advance(TimeSpan.FromMinutes(4));
        d.Time.SetWall(TestDevice.T0);
        await m.DisposeAsync(); // 4 dakikalık monoton süre kaydedilir.
        d.Time.Restart(TestDevice.T0);
        await using (var second = await d.StartAsync())
        {
            d.Time.Advance(TimeSpan.FromMinutes(4));
            d.Time.SetWall(TestDevice.T0);
        } // toplam 8 dakika kaydedilir.
        d.Time.Restart(TestDevice.T0);
        await using var third = await d.StartAsync();
        Assert.Equal("clock_rollback", third.Current.Reason);
        Assert.False(third.AllowsCommercialOperations);
    }

    [Fact, Trait("Finding", "2")]
    public async Task Crash_with_normal_clock_preserves_valid_offline_license_without_extra_writes()
    {
        var (d, m) = await Activated();
        Offline(d);
        var writes = d.File.Writes;
        d.Time.Advance(TimeSpan.FromMinutes(4));
        for (var i = 0; i < 10000; i++) Assert.True(m.AllowsCommercialOperations);
        Assert.Equal(writes, d.File.Writes); // satış/izin okuması disk yazmaz.
        await Crash(d, m);
        d.Time.Restart(TestDevice.T0.AddMinutes(5));
        await using var restarted = await d.StartAsync();
        Assert.True(restarted.AllowsCommercialOperations);
    }

    [Fact, Trait("Finding", "2")]
    public async Task Frozen_clock_crash_before_checkpoint_is_an_explicit_continuity_boundary()
    {
        var (d, m) = await Activated();
        Offline(d);
        d.Time.Advance(TimeSpan.FromMinutes(4));
        d.Time.SetWall(TestDevice.T0);
        await Crash(d, m);
        d.Time.Restart(TestDevice.T0);
        await using var restarted = await d.StartAsync();
        Assert.True(restarted.AllowsCommercialOperations); // güvenilir kayıt yok, kesinti için online kilit yok.
    }

    [Fact, Trait("Finding", "2")]
    public async Task Detected_clock_manipulation_blocks_permission_without_waiting_for_local_timer()
    {
        var (d, m) = await Activated();
        await m.DisposeAsync();
        Offline(d);
        await using var restarted = await d.StartAsync();
        d.Time.Advance(TimeSpan.FromMinutes(6));
        d.Time.SetWall(TestDevice.T0);
        Assert.False(restarted.AllowsCommercialOperations);
        Assert.False(restarted.Current.AllowsCommercialOperations);
        m = restarted;
        m.RunLocalCheck();
        Assert.Equal("clock_rollback", m.Current.Reason);
    }

    [Fact, Trait("Finding", "3")]
    public async Task Cached_status_and_pending_online_request_cannot_extend_deadline()
    {
        var (d, m) = await Activated();
        await using (m)
        {
            var cached = m.Current;
            var until = cached.CommercialAllowedUntil!.Value;
            d.Time.Advance(until - TestDevice.T0 - TimeSpan.FromSeconds(1));
            d.Server.Gate = new TaskCompletionSource();
            var pending = m.CheckNowAsync();
            try
            {
                d.Time.Advance(TimeSpan.FromSeconds(1));
                Assert.False(m.AllowsCommercialOperations);
                Assert.False(m.Current.AllowsCommercialOperations);
                Assert.False(cached.AllowsCommercialOperations);
                Assert.Equal(LicenseState.OfflineExpired, await m.GetStateAsync());
            }
            finally { d.Server.Gate.SetResult(); await pending; }
        }
    }

    [Fact, Trait("Finding", "3")]
    public async Task Cached_status_observes_new_revocation_and_disposal()
    {
        var (d, m) = await Activated();
        var cached = m.Current;
        d.Server.Licenses[Key].Status = "revoked";
        await m.CheckNowAsync();
        Assert.False(cached.AllowsCommercialOperations);
        await m.DisposeAsync();
        Assert.False(cached.AllowsCommercialOperations);
        Assert.False(new LicenseStatus(LicenseState.Active, "unbound").AllowsCommercialOperations);
    }

    [Fact, Trait("Finding", "3")]
    public async Task Invalid_stored_signature_requires_online_even_with_a_valid_mirror()
    {
        var (d, m) = await Activated();
        await m.DisposeAsync();
        d.File.Data![^5] ^= 1;
        Offline(d);
        await using var restarted = await d.StartAsync();
        Assert.False(restarted.AllowsCommercialOperations);
        Assert.Equal("online_verification_required", restarted.Current.Reason);
        d.Server.Override = null;
        await restarted.CheckNowAsync();
        Assert.True(restarted.AllowsCommercialOperations);
    }

    [Fact, Trait("Finding", "4")]
    public async Task Reconnection_after_success_checks_soon_and_coalesces_notifications_during_and_after_check()
    {
        var (d, m) = await Activated();
        await using (m)
        {
            d.Time.Advance(TimeSpan.FromMinutes(1));
            m.NotifyNetworkAvailable(); // önceki kontrol başarılıydı.
            var due = m.Scheduler.NextOnlineAt;
            Assert.True(due <= d.Time.GetUtcNow().AddSeconds(30));
            m.NotifyNetworkAvailable();
            Assert.Equal(due, m.Scheduler.NextOnlineAt);
            d.Server.Gate = new TaskCompletionSource();
            var check = m.CheckNowAsync();
            m.NotifyNetworkAvailable();
            d.Server.Gate.SetResult();
            await check;
            var normalDue = m.Scheduler.NextOnlineAt;
            m.NotifyNetworkAvailable(); // aynı Windows olay dalgasının geç gelen tekrarı
            Assert.Equal(normalDue, m.Scheduler.NextOnlineAt);
            Assert.Equal(1, d.Server.Calls("check"));
        }
    }
    [Fact, Trait("Finding", "3")]
    public async Task Detected_corruption_remains_online_required_across_restart_until_verified()
    {
        var (d, m) = await Activated();
        await m.DisposeAsync();
        d.File.Data![^5] ^= 1;
        Offline(d);
        var first = await d.StartAsync();
        Assert.False(first.AllowsCommercialOperations);
        await first.DisposeAsync(); // bozuk kopya onarılsa da gereklilik kalıcıdır.
        await using var second = await d.StartAsync();
        Assert.Equal("online_verification_required", second.Current.Reason);
        Assert.False(second.Current.AllowsCommercialOperations);
        d.Server.Override = null;
        await second.CheckNowAsync();
        Assert.True(second.AllowsCommercialOperations);
    }

    [Fact, Trait("Finding", "4")]
    public async Task Reconnection_after_failure_skips_backoff_and_remains_single_flight()
    {
        var (d, m) = await Activated();
        await using (m)
        {
            Offline(d);
            await m.CheckNowAsync();
            Assert.Equal(1, m.Scheduler.ConsecutiveFailures);
            var backoff = m.Scheduler.NextOnlineAt;
            d.Time.Advance(TimeSpan.FromMinutes(1));
            d.Server.Override = null;
            m.NotifyNetworkAvailable();
            Assert.True(m.Scheduler.NextOnlineAt < backoff);
            d.Server.Gate = new TaskCompletionSource();
            var one = m.CheckNowAsync();
            var two = m.CheckNowAsync();
            Assert.Same(one, two);
            m.NotifyNetworkAvailable();
            d.Server.Gate.SetResult();
            await Task.WhenAll(one, two);
            Assert.Equal(0, m.Scheduler.ConsecutiveFailures);
        }
    }
}