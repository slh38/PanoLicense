using System.Net;
using System.Net.Http.Json;
using System.Runtime.Versioning;
using System.Text.Json;
using System.Text.Json.Nodes;
using PanoLicense.Client;

namespace PanoLicense.Tests;

/// <summary>
/// Yerel gerçek Laravel API ile uçtan uca doğrulama (docs/TESTING.md "F4 uçtan uca").
/// Yalnız PANOLICENSE_E2E_URL tanımlıysa çalışır; aksi hâlde hiçbir şey yapmadan geçer.
/// Yalnız loopback adreslerine izin verilir (canlı sunucuya karşı çalıştırılamaz).
/// Gerekli ortam değişkenleri: PANOLICENSE_E2E_URL, PANOLICENSE_E2E_KID, PANOLICENSE_E2E_PUBKEY_FILE,
/// PANOLICENSE_E2E_ADMIN_USER, PANOLICENSE_E2E_ADMIN_PASSWORD (yerel test Admin'i).
/// </summary>
[SupportedOSPlatform("windows")]
public class RealApiEndToEndTests
{
    private static string? Env(string name) => Environment.GetEnvironmentVariable(name);

    [Fact]
    public async Task Demo_activation_check_offline_and_revocation_against_local_api()
    {
        if (Env("PANOLICENSE_E2E_URL") is not { } url || !OperatingSystem.IsWindows()) return;
        var server = new Uri(url);
        Assert.True(server.IsLoopback, "Uçtan uca test yalnız yerel API'ye karşı çalışır.");

        var run = Guid.NewGuid().ToString("N")[..8];
        var dir = Path.Combine(Path.GetTempPath(), "panolicense-e2e-" + run);
        var keyStore = new CngDeviceKeyStore($"PanoLicense-E2E-{run}");
        var fingerprint = DeviceFingerprint.Hash("e2e-" + run);
        var slots = new IStateSlot[] { new FileStateSlot(Path.Combine(dir, "license.state")), new FileStateSlot(Path.Combine(dir, "mirror.state")) };
        LicenseClientOptions Options(Uri target) => new()
        {
            ServerUrl = target,
            ProductCode = "PANOPOS",
            SigningPublicKeys = new Dictionary<string, string> { [Env("PANOLICENSE_E2E_KID")!] = File.ReadAllText(Env("PANOLICENSE_E2E_PUBKEY_FILE")!) },
            FingerprintProvider = () => fingerprint,
            DeviceName = "E2E " + run,
            AppVersion = "f4-e2e",
        };
        var offline = new Uri("http://127.0.0.1:9"); // kapalı port: bağlantı hatası

        try
        {
            using var admin = await AdminClient.LoginAsync(server);
            var licenseKey = await admin.CreateLicenseAsync($"E2E Market {run}");

            await using (var m = new LicenseManager(Options(server), keyStore, slots))
            {
                await m.StartAsync();
                Assert.True(m.Current.Reason == "not_activated", m.Current.Reason);

                var demo = await m.RegisterDemoAsync(new DemoRegistration($"E2E Demo {run}", InstallerUsername: "e2e"));
                Assert.True(demo.Succeeded, demo.ErrorCode);
                Assert.Equal(LicenseState.Demo, m.Current.State);

                var wrong = await m.ActivateAsync("PAN-0000-0000-0000");
                Assert.Equal(LicenseCallOutcome.Denied, wrong.Outcome);
                Assert.Equal(LicenseState.Demo, m.Current.State); // yanlış anahtar mevcut durumu bozmaz

                var activated = await m.ActivateAsync(licenseKey.ToLowerInvariant());
                Assert.True(activated.Succeeded, activated.ErrorCode);
                Assert.Equal(LicenseState.Active, m.Current.State);
                Assert.Equal(licenseKey, m.Current.LicenseKey);

                await m.CheckNowAsync();
                Assert.Equal(LicenseState.Active, m.Current.State);
                Assert.Null(m.Current.LastError);
                Console.WriteLine($"E2E anahtar donanımda (TPM): {m.Current.HardwareBackedKey}");
            }

            // Sunucuya ulaşılamıyor: imzalı belgeyle offline çalışma, bağlantı hatası red değildir.
            await using (var m = new LicenseManager(Options(offline), keyStore, slots))
            {
                await m.StartAsync();
                Assert.Equal(LicenseState.OfflineGrace, m.Current.State);
                await m.CheckNowAsync();
                Assert.Equal(LicenseState.OfflineGrace, m.Current.State);
                Assert.Equal(1, m.Scheduler.ConsecutiveFailures);
                Assert.True(m.AllowsCommercialOperations);
            }

            await admin.RevokeAsync(licenseKey);

            await using (var m = new LicenseManager(Options(server), keyStore, slots))
            {
                await m.StartAsync();
                await m.CheckNowAsync();
                Assert.Equal(LicenseState.Revoked, m.Current.State);
            }

            // İptal öğrenildikten sonra bağlantı kesilse ve yeniden başlasa da kapalı kalır.
            await using (var m = new LicenseManager(Options(offline), keyStore, slots))
            {
                await m.StartAsync();
                Assert.Equal(LicenseState.Revoked, m.Current.State);
                Assert.False(m.AllowsCommercialOperations);
            }
        }
        finally
        {
            keyStore.Delete();
            if (Directory.Exists(dir)) Directory.Delete(dir, recursive: true);
        }
    }

    /// <summary>Yerel panel API'si (Sanctum SPA çerezi) ile test lisansı oluşturma ve iptal.</summary>
    private sealed class AdminClient : IDisposable
    {
        private readonly HttpClient _http;
        private readonly CookieContainer _cookies = new();
        private readonly Uri _server;

        private AdminClient(Uri server)
        {
            _server = server;
            _http = new HttpClient(new HttpClientHandler { CookieContainer = _cookies }) { BaseAddress = server };
            _http.DefaultRequestHeaders.Accept.ParseAdd("application/json");
            _http.DefaultRequestHeaders.Referrer = server;
            _http.DefaultRequestHeaders.Add("Origin", server.GetLeftPart(UriPartial.Authority));
        }

        public static async Task<AdminClient> LoginAsync(Uri server)
        {
            var c = new AdminClient(server);
            (await c._http.GetAsync("/sanctum/csrf-cookie")).EnsureSuccessStatusCode();
            await c.PostAsync("/api/v1/auth/login", new
            {
                username = Environment.GetEnvironmentVariable("PANOLICENSE_E2E_ADMIN_USER"),
                password = Environment.GetEnvironmentVariable("PANOLICENSE_E2E_ADMIN_PASSWORD"),
            });
            return c;
        }

        public async Task<string> CreateLicenseAsync(string customer)
        {
            var products = JsonNode.Parse(await _http.GetStringAsync("/api/v1/products"))!;
            var productId = (products is JsonArray a ? a : products["data"]!.AsArray())
                .First(p => p!["code"]!.GetValue<string>() == "PANOPOS")!["id"]!.GetValue<int>();
            var json = await PostAsync("/api/v1/licenses", new
            {
                customer = new { name = customer },
                product_id = productId,
                device_limit = 1,
                starts_at = DateTime.UtcNow.ToString("yyyy-MM-dd"),
                expires_at = DateTime.UtcNow.AddYears(1).ToString("yyyy-MM-dd"),
            });
            return json["data"]!["license_key"]!.GetValue<string>();
        }

        public async Task RevokeAsync(string licenseKey)
        {
            var list = JsonNode.Parse(await _http.GetStringAsync($"/api/v1/licenses?q={licenseKey}"))!;
            var id = list["data"]![0]!["id"]!.GetValue<int>();
            await PostAsync($"/api/v1/licenses/{id}/revoke", new { reason = "F4 uçtan uca test" });
        }

        private async Task<JsonNode> PostAsync(string path, object body)
        {
            var token = _cookies.GetCookies(_server)["XSRF-TOKEN"]?.Value;
            using var request = new HttpRequestMessage(HttpMethod.Post, path) { Content = JsonContent.Create(body) };
            if (token is not null) request.Headers.Add("X-XSRF-TOKEN", Uri.UnescapeDataString(token));
            using var response = await _http.SendAsync(request);
            var text = await response.Content.ReadAsStringAsync();
            Assert.True(response.IsSuccessStatusCode, $"{path}: {(int)response.StatusCode} {text}");
            return string.IsNullOrWhiteSpace(text) ? new JsonObject() : JsonNode.Parse(text) ?? new JsonObject();
        }

        public void Dispose() => _http.Dispose();
    }
}
