using System.Net;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;
using PanoLicense.Client;

namespace PanoLicense.Tests;

/// <summary>Denetlenebilir saat: duvar saati ve monoton sayaç ayrı ayrı oynatılabilir (gerçek bekleme yok).</summary>
internal sealed class ManualTime(DateTimeOffset start) : TimeProvider
{
    private DateTimeOffset _wall = start;
    private long _ticks;

    public override DateTimeOffset GetUtcNow() => _wall;
    public override long GetTimestamp() => _ticks;
    public override long TimestampFrequency => TimeSpan.TicksPerSecond;

    /// <summary>Gerçek zaman geçer: duvar saati ve monoton sayaç birlikte ilerler.</summary>
    public void Advance(TimeSpan by)
    {
        _wall += by;
        _ticks += by.Ticks;
    }

    /// <summary>Yalnız duvar saati değişir (kullanıcı saati değiştirdi).</summary>
    public void SetWall(DateTimeOffset value) => _wall = value;

    /// <summary>Uygulama kapalıyken geçen süre: monoton sayaç sıfırdan başlar gibi davranır.</summary>
    public void Restart(DateTimeOffset wall)
    {
        _wall = wall;
        _ticks += 1_000_000_000; // yeni süreç: önceki sayaçla ilişkisiz
    }
}

internal sealed class MemorySlot : IStateSlot
{
    public byte[]? Data;
    public bool Fail;
    public int Writes;

    public byte[]? Read() => Data?.ToArray();

    public void Write(byte[] data)
    {
        if (Fail) throw new IOException("yazılamadı");
        Writes++;
        Data = data.ToArray();
    }
}

internal sealed class MemoryKeyStore : IDeviceKeyStore
{
    private readonly ECParameters _parameters = ECDsa.Create(ECCurve.NamedCurves.nistP256).ExportParameters(true);
    public bool IsHardwareBacked => false;

    public ECDsa OpenOrCreate()
    {
        var key = ECDsa.Create();
        key.ImportParameters(_parameters);
        return key;
    }
}

/// <summary>
/// Sunucunun istemci uçlarını (challenge/demo/activate/check) sözleşmeye uygun taklit eder ve belgeleri
/// PHP LicenseSigner ile aynı biçimde (ES256, DER, base64url) imzalar. Gerçek API uçtan uca testi ayrıca yapılır.
/// </summary>
internal sealed class FakeLicenseServer : HttpMessageHandler
{
    public const string Kid = "t1";
    public const string Product = "PANOPOS";
    private readonly ECDsa _signing = ECDsa.Create(ECCurve.NamedCurves.nistP256);
    private readonly TimeProvider _time;
    private readonly Dictionary<string, string> _challenges = new();

    public FakeLicenseServer(TimeProvider time) => _time = time;

    public string PublicKeyPem => _signing.ExportSubjectPublicKeyInfoPem();

    public sealed class License
    {
        public string Status = "active";
        public string? ExpiresAt = "2027-12-31";
        public string LicenseType = "term";
        public int Version = 2;
        public int GraceDays = 7;
        public string Package = "PRO";
        public List<string> Modules = new();
        public string Customer = "Ada Market";
        public HashSet<string> Devices = new();
    }

    public Dictionary<string, License> Licenses { get; } = new();

    /// <summary>Uçtan bağımsız zorunlu yanıt (ör. 503, HTML). null ise normal davranış.</summary>
    public Func<string, HttpResponseMessage?>? Override;

    /// <summary>check uçlarının bekletilmesi (tek uçuş testi).</summary>
    public TaskCompletionSource? Gate;

    public int Calls(string path) => _calls.TryGetValue(path, out var n) ? n : 0;
    private readonly Dictionary<string, int> _calls = new();

    protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken ct)
    {
        var path = request.RequestUri!.AbsolutePath.Split('/').Last();
        lock (_calls) _calls[path] = Calls(path) + 1;
        if (Override?.Invoke(path) is { } forced) return forced;
        if (path == "check" && Gate is { } gate) await gate.Task.ConfigureAwait(false);

        var body = JsonNode.Parse(await request.Content!.ReadAsStringAsync(ct).ConfigureAwait(false))!.AsObject();
        var device = body["device"]!.AsObject();
        var fingerprint = device["fingerprint"]!.GetValue<string>();
        using var deviceKey = ECDsa.Create();
        deviceKey.ImportFromPem(device["public_key"]!.GetValue<string>());
        var keyHash = Convert.ToHexString(SHA256.HashData(deviceKey.ExportSubjectPublicKeyInfo())).ToLowerInvariant();

        if (path == "challenge")
        {
            var id = Convert.ToHexString(RandomNumberGenerator.GetBytes(32)).ToLowerInvariant();
            var message = JsonSerializer.Serialize(new Dictionary<string, string?>
            {
                ["purpose"] = "panolicense-device-proof-v1", ["challenge_id"] = id,
                ["operation"] = body["operation"]!.GetValue<string>(), ["product"] = Product,
                ["license_key"] = body["license_key"]?.GetValue<string>() ?? "",
                ["fingerprint"] = fingerprint, ["device_key_hash"] = keyHash,
                ["expires_at"] = Iso(_time.GetUtcNow().AddSeconds(120)),
            });
            lock (_challenges) _challenges[id] = message;
            return Json(200, new JsonObject { ["challenge_id"] = id, ["message"] = message, ["expires_at"] = "x" });
        }

        var proof = body["proof"]!.AsObject();
        string? stored;
        lock (_challenges)
            if (_challenges.Remove(proof["challenge_id"]!.GetValue<string>(), out stored) == false) stored = null;
        if (stored is null || !deviceKey.VerifyData(Encoding.UTF8.GetBytes(stored), Convert.FromBase64String(proof["signature"]!.GetValue<string>()),
                HashAlgorithmName.SHA256, DSASignatureFormat.Rfc3279DerSequence))
            return Error(403, "invalid_device_proof");

        string key;
        if (path == "demo")
        {
            key = $"PAN-DEMO-{Licenses.Count + 1:0000}-0001";
            Licenses[key] = new License { Status = "demo", Customer = body["customer"]!["name"]!.GetValue<string>(), ExpiresAt = "2026-12-11" };
        }
        else key = body["license_key"]!.GetValue<string>();

        if (!Licenses.TryGetValue(key, out var lic)) return Error(404, "license_not_found");
        var deviceId = fingerprint + keyHash;
        if (path is "activate" or "demo")
        {
            if (lic.Status == "revoked") return Error(403, "license_revoked");
            lic.Devices.Add(deviceId);
        }
        else if (!lic.Devices.Contains(deviceId)) return Error(403, "device_not_activated");

        var doc = Sign(key, lic, fingerprint, keyHash);
        return Json(path == "check" ? 200 : 201, new JsonObject
        {
            ["license_key"] = key, ["status"] = lic.Status, ["created"] = path != "check",
            ["license_document"] = JsonNode.Parse(doc),
        });
    }

    public string Sign(string key, License lic, string fingerprint, string keyHash, DateTimeOffset? issued = null)
    {
        var issuedAt = issued ?? _time.GetUtcNow();
        // Kesirli UTC zaman korunur.
        var grace = lic.Status == "demo" ? 7 : lic.GraceDays;
        DateTimeOffset? licenseEnd = lic.ExpiresAt is null ? null : DateTimeOffset.Parse(lic.ExpiresAt + "T00:00:00Z").AddDays(1);
        var validUntil = licenseEnd is { } end && end < issuedAt.AddDays(grace) ? end : issuedAt.AddDays(grace);
        var payload = JsonSerializer.Serialize(new Dictionary<string, object?>
        {
            ["v"] = lic.Version, ["license_type"] = lic.LicenseType, ["license_key"] = key, ["product"] = Product, ["customer"] = lic.Customer,
            ["status"] = lic.Status, ["state"] = lic.Status, ["offline_grace_days"] = grace, ["device_limit"] = lic.Status == "demo" ? 1 : 3,
            ["package"] = lic.Package, ["modules"] = lic.Modules.OrderBy(m => m, StringComparer.Ordinal).ToArray(),
            ["fingerprint"] = fingerprint, ["device_key_hash"] = keyHash, ["device_status"] = "active",
            ["expires_at"] = lic.ExpiresAt, ["issued_at"] = Iso(issuedAt), ["valid_until"] = Iso(validUntil),
        });
        return SignPayload(JsonNode.Parse(payload)!.AsObject());
    }

    public string SignPayload(JsonObject payload, int? envelopeVersion = null)
    {
        var bytes = Encoding.UTF8.GetBytes(payload.ToJsonString());
        var sig = _signing.SignData(bytes, HashAlgorithmName.SHA256, DSASignatureFormat.Rfc3279DerSequence);
        return new JsonObject { ["v"] = envelopeVersion ?? payload["v"]!.GetValue<int>(), ["kid"] = Kid,
            ["alg"] = "ES256", ["payload"] = B64(bytes), ["sig"] = B64(sig) }.ToJsonString();
    }

    public static HttpResponseMessage Error(int status, string code) =>
        Json(status, new JsonObject { ["error"] = code, ["message"] = code });

    public static HttpResponseMessage Json(int status, JsonNode body) =>
        new((HttpStatusCode)status) { Content = new StringContent(body.ToJsonString(), Encoding.UTF8, "application/json") };

    private static string Iso(DateTimeOffset t) => t.UtcDateTime.ToString("yyyy-MM-dd'T'HH:mm:ss.ffffff'Z'");
    private static string B64(byte[] b) => Convert.ToBase64String(b).TrimEnd('=').Replace('+', '-').Replace('/', '_');
}

/// <summary>Test cihazı: aynı anahtar/yuvalarla yeniden başlatılabilir.</summary>
internal sealed class TestDevice
{
    public static readonly DateTimeOffset T0 = DateTimeOffset.Parse("2026-11-27T10:00:00Z");
    public readonly ManualTime Time = new(T0);
    public readonly FakeLicenseServer Server;
    public readonly MemoryKeyStore Keys = new();
    public readonly MemorySlot File = new();
    public readonly MemorySlot Registry = new();
    public string Fingerprint = new('a', 64);

    public TestDevice() => Server = new FakeLicenseServer(Time);

    public LicenseManager Create(IDeviceKeyStore? keys = null) => new(
        new LicenseClientOptions
        {
            ServerUrl = new Uri("https://lisans.test"),
            ProductCode = FakeLicenseServer.Product,
            SigningPublicKeys = new Dictionary<string, string> { [FakeLicenseServer.Kid] = Server.PublicKeyPem },
            FingerprintProvider = () => Fingerprint,
            DeviceName = "Kasa 1",
        },
        keys ?? Keys, [File, Registry], Server, Time, random: () => 0.5, background: false);

    public async Task<LicenseManager> StartAsync(IDeviceKeyStore? keys = null)
    {
        var m = Create(keys);
        await m.StartAsync();
        return m;
    }
}
