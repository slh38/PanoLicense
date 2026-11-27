using System.Text;
using System.Text.Json.Nodes;
using PanoLicense.Client;

namespace PanoLicense.Tests;

/// <summary>Paket/modül: imzalı belgedeki geçerli modüller, ağ/disk olmadan HasModule, sonraki online kontrolde güncelleme.</summary>
public class ModuleTests
{
    private const string Key = "PAN-MODL-0000-0001";

    private static async Task<(TestDevice Device, LicenseManager Manager)> Activated(params string[] modules)
    {
        var d = new TestDevice();
        d.Server.Licenses[Key] = new FakeLicenseServer.License { Package = "PRO", Modules = modules.ToList() };
        var m = await d.StartAsync();
        Assert.True((await m.ActivateAsync(Key)).Succeeded);
        return (d, m);
    }

    [Fact]
    public async Task Signed_modules_are_exposed_and_checked_in_memory()
    {
        var (d, m) = await Activated("variant", "shift");
        await using var _ = m;

        Assert.Equal("PRO", m.Current.Package);
        Assert.Equal(["shift", "variant"], m.Current.Modules);
        var calls = d.Server.Calls("check") + d.Server.Calls("challenge");
        Assert.True(m.HasModule("variant"));
        Assert.False(m.HasModule("Variant")); // kod birebir
        Assert.False(m.HasModule("backup"));
        Assert.Equal(calls, d.Server.Calls("check") + d.Server.Calls("challenge")); // ağ çağrısı yok
    }

    [Fact]
    public async Task Module_changes_apply_only_after_next_successful_online_check()
    {
        var (d, m) = await Activated("variant");
        await using var _ = m;
        d.Server.Licenses[Key].Modules.Add("backup"); // panelde ek modül verildi
        d.Server.Licenses[Key].Package = "LITE";

        Assert.False(m.HasModule("backup")); // online kontrol olmadan değişmez

        d.Server.Override = _ => throw new HttpRequestException("ağ yok");
        await m.CheckNowAsync();
        Assert.False(m.HasModule("backup")); // bağlantı hatası eski imzalı listeyi korur
        Assert.True(m.HasModule("variant"));

        d.Server.Override = null;
        d.Time.Advance(TimeSpan.FromMinutes(15));
        await m.CheckNowAsync();
        Assert.True(m.HasModule("backup"));
        Assert.Equal("LITE", m.Current.Package);
    }

    [Fact]
    public async Task Offline_restart_keeps_last_signed_modules_and_closed_license_has_none()
    {
        var (d, first) = await Activated("variant");
        await first.DisposeAsync();
        d.Server.Override = _ => throw new HttpRequestException("ağ yok");

        d.Time.Restart(TestDevice.T0.AddDays(2));
        await using (var m = await d.StartAsync())
            Assert.True(m.HasModule("variant"));

        d.Time.Restart(TestDevice.T0.AddDays(8)); // 7 günlük offline izin bitti
        await using (var m = await d.StartAsync())
            Assert.False(m.HasModule("variant"));
    }

    [Fact]
    public void Modules_are_covered_by_the_signature()
    {
        var d = new TestDevice();
        var lic = new FakeLicenseServer.License { Modules = ["variant"] };
        var keyHash = new string('b', 64);
        var env = JsonNode.Parse(d.Server.Sign(Key, lic, d.Fingerprint, keyHash))!;
        using var verifier = new LicenseDocumentVerifier(new Dictionary<string, string> { [FakeLicenseServer.Kid] = d.Server.PublicKeyPem });
        var identity = new LicenseIdentity("PANOPOS", Key, d.Fingerprint, keyHash);

        Assert.Equal(["variant"], verifier.Verify(env.ToJsonString(), identity, out _)!.Modules);

        var payload = Encoding.UTF8.GetString(Convert.FromBase64String(Pad(env["payload"]!.GetValue<string>())));
        var forged = payload.Replace("[\"variant\"]", "[\"variant\",\"backup\"]");
        env["payload"] = Convert.ToBase64String(Encoding.UTF8.GetBytes(forged)).TrimEnd('=').Replace('+', '-').Replace('/', '_');
        Assert.Null(verifier.Verify(env.ToJsonString(), identity, out var error));
        Assert.Equal("bad_signature", error);
    }

    [Fact]
    public void Older_php_document_without_modules_has_no_modules()
    {
        var fixture = JsonNode.Parse(File.ReadAllText(Path.Combine(AppContext.BaseDirectory, "Fixtures", "php-es256-documents.json")))!;
        using var verifier = new LicenseDocumentVerifier(new Dictionary<string, string>
        {
            [fixture["kid"]!.GetValue<string>()] = fixture["public_key_pem"]!.GetValue<string>(),
        });
        var doc = verifier.Verify(fixture["active"]!.ToJsonString(), new LicenseIdentity("PANOPOS", "PAN-FIX0-0000-0001",
            fixture["fingerprint"]!.GetValue<string>(), fixture["device_key_hash"]!.GetValue<string>()), out var error);

        Assert.NotNull(doc);
        Assert.Null(doc!.Package);
        Assert.Empty(doc.Modules);
    }

    private static string Pad(string b64url)
    {
        var s = b64url.Replace('-', '+').Replace('_', '/');
        return s.PadRight(s.Length + (4 - s.Length % 4) % 4, '=');
    }
}
