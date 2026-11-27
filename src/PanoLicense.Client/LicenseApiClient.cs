using System.Net;
using System.Net.Http.Json;
using System.Security.Cryptography;
using System.Text.Json;
using System.Text.Json.Nodes;

namespace PanoLicense.Client;

/// <summary>Sunucu çağrısının sınıfı. Bağlantı hatası ile kesin lisans reddi birbirinden ayrılır.</summary>
public enum LicenseCallOutcome
{
    /// <summary>Sunucu yanıt verdi ve imzalı belge döndü.</summary>
    Success = 0,

    /// <summary>Kesin lisans/cihaz reddi (ör. license_revoked, device_not_activated). Tekrar deneme/fallback ile aşılmaz.</summary>
    Denied = 1,

    /// <summary>İstek reddedildi ama lisans kararı değil (ör. form doğrulama hatası 422).</summary>
    Rejected = 2,

    /// <summary>Ağ hatası, zaman aşımı, 5xx/429/503 veya geçersiz yanıt. Yalnız mevcut offline hak kullanılabilir.</summary>
    Unavailable = 3,
}

internal sealed record ApiResult(LicenseCallOutcome Outcome, int HttpStatus, string? ErrorCode, string? Message,
    string? DocumentJson = null, string? LicenseKey = null, bool Created = false)
{
    public static ApiResult Unavailable(string code, int status = 0) => new(LicenseCallOutcome.Unavailable, status, code, null);
}

internal sealed record DeviceIdentity(string Fingerprint, string PublicKeyPem, string KeyHash, string? Name, string? AppVersion);

/// <summary>
/// /api/v1/client uçlarının HTTP istemcisi (docs/CLIENT_API.md). Her işlem için yeni challenge alır,
/// mesaj bağlamını kendi isteğiyle karşılaştırır ve yalnız eşleşirse cihaz anahtarıyla imzalar.
/// Durum tutmaz ve diske yazmaz.
/// </summary>
internal sealed class LicenseApiClient : IDisposable
{
    public const string ProofPurpose = "panolicense-device-proof-v1";

    /// <summary>Sunucunun kesin karar kodları (ClientLicenseException). Diğer hatalar karar sayılmaz.</summary>
    internal static readonly HashSet<string> DenialCodes = new(StringComparer.Ordinal)
    {
        "license_not_found", "product_mismatch", "license_revoked", "license_expired", "demo_expired",
        "device_limit_exceeded", "device_not_activated", "device_key_mismatch", "device_reenrollment_required",
    };

    private readonly HttpClient _http;
    private readonly string _productCode;

    public LicenseApiClient(LicenseClientOptions options, HttpMessageHandler? handler = null)
    {
        var baseUri = new Uri(options.ServerUrl!.AbsoluteUri.TrimEnd('/') + "/api/v1/client/");
        _http = handler is null ? new HttpClient() : new HttpClient(handler, disposeHandler: false);
        _http.BaseAddress = baseUri;
        _http.Timeout = options.RequestTimeout;
        _http.DefaultRequestHeaders.Accept.ParseAdd("application/json");
        _productCode = options.ProductCode;
    }

    public static string NormalizeKey(string licenseKey) => licenseKey.Trim().ToUpperInvariant();

    /// <param name="operation">demo | activate | check</param>
    /// <param name="licenseKey">Normalize edilmiş anahtar; demo için boş.</param>
    public async Task<ApiResult> CallAsync(string operation, string licenseKey, DeviceIdentity device, ECDsa deviceKey,
        JsonObject? extra, CancellationToken ct)
    {
        // Kullanılmış/süresi dolmuş challenge yerine bir kez yeni challenge ile denenir.
        for (var attempt = 0; ; attempt++)
        {
            var challenge = await PostAsync("challenge", new JsonObject
            {
                ["operation"] = operation,
                ["product_code"] = _productCode,
                ["license_key"] = operation == "demo" ? null : licenseKey,
                ["device"] = DeviceJson(device),
            }, ct).ConfigureAwait(false);
            if (challenge.Result is { } failed) return failed;

            var root = challenge.Body!.Value;
            var challengeId = root.TryGetProperty("challenge_id", out var id) ? id.GetString() : null;
            var message = root.TryGetProperty("message", out var m) ? m.GetString() : null;
            if (challengeId is null || message is null ||
                !ChallengeMatches(message, challengeId, operation, _productCode, licenseKey, device))
                return ApiResult.Unavailable("challenge_mismatch", challenge.Status);

            var body = extra?.DeepClone().AsObject() ?? new JsonObject();
            body["product_code"] = _productCode;
            if (operation != "demo") body["license_key"] = licenseKey;
            body["device"] = DeviceJson(device);
            body["proof"] = new JsonObject
            {
                ["challenge_id"] = challengeId,
                ["signature"] = DeviceProof.Sign(deviceKey, message),
            };

            var response = await PostAsync(operation, body, ct).ConfigureAwait(false);
            if (response.Result is { ErrorCode: "invalid_device_proof" } && attempt == 0) continue;
            if (response.Result is { } error)
                return error.ErrorCode == "invalid_device_proof" ? error with { Outcome = LicenseCallOutcome.Unavailable } : error;

            var ok = response.Body!.Value;
            if (!ok.TryGetProperty("license_document", out var doc) || doc.ValueKind != JsonValueKind.Object)
                return ApiResult.Unavailable("missing_document", response.Status);

            return new ApiResult(LicenseCallOutcome.Success, response.Status, null, null, doc.GetRawText(),
                ok.TryGetProperty("license_key", out var k) ? k.GetString() : null,
                ok.TryGetProperty("created", out var c) && c.ValueKind == JsonValueKind.True);
        }
    }

    /// <summary>İmzalanacak mesajın tüm bağlamı beklenen istekle aynı olmalı; değilse imzalanmaz.</summary>
    internal static bool ChallengeMatches(string message, string challengeId, string operation, string product,
        string licenseKey, DeviceIdentity device)
    {
        try
        {
            using var doc = JsonDocument.Parse(message);
            var r = doc.RootElement;
            string? S(string name) => r.TryGetProperty(name, out var v) && v.ValueKind == JsonValueKind.String ? v.GetString() : null;
            return S("purpose") == ProofPurpose && S("challenge_id") == challengeId && S("operation") == operation &&
                   S("product") == product && S("license_key") == licenseKey &&
                   S("fingerprint") == device.Fingerprint && S("device_key_hash") == device.KeyHash;
        }
        catch (JsonException)
        {
            return false;
        }
    }

    private static JsonObject DeviceJson(DeviceIdentity d) => new()
    {
        ["fingerprint"] = d.Fingerprint,
        ["public_key"] = d.PublicKeyPem,
        ["name"] = Truncate(d.Name, 100),
        ["app_version"] = Truncate(d.AppVersion, 30),
    };

    private static string? Truncate(string? value, int max) =>
        string.IsNullOrWhiteSpace(value) ? null : value.Length <= max ? value : value[..max];

    private readonly record struct Posted(int Status, JsonElement? Body, ApiResult? Result);

    private async Task<Posted> PostAsync(string path, JsonObject body, CancellationToken ct)
    {
        HttpResponseMessage response;
        try
        {
            response = await _http.PostAsJsonAsync(path, body, ct).ConfigureAwait(false);
        }
        catch (OperationCanceledException) when (!ct.IsCancellationRequested)
        {
            return new(0, null, ApiResult.Unavailable("timeout"));
        }
        catch (HttpRequestException)
        {
            return new(0, null, ApiResult.Unavailable("network_error"));
        }

        using (response)
        {
            var status = (int)response.StatusCode;
            JsonElement? json = null;
            try
            {
                if (response.Content.Headers.ContentType?.MediaType?.Contains("json", StringComparison.OrdinalIgnoreCase) == true)
                {
                    var text = await response.Content.ReadAsStringAsync(ct).ConfigureAwait(false);
                    using var parsed = JsonDocument.Parse(text);
                    json = parsed.RootElement.Clone();
                }
            }
            catch (Exception e) when (e is JsonException or HttpRequestException)
            {
                json = null;
            }
            catch (OperationCanceledException) when (!ct.IsCancellationRequested)
            {
                return new(status, null, ApiResult.Unavailable("timeout", status));
            }

            // Yakalama portalı/proxy gibi JSON olmayan yanıtlar karar değildir.
            if (json is not { ValueKind: JsonValueKind.Object } obj)
                return new(status, null, ApiResult.Unavailable("invalid_response", status));

            if (response.IsSuccessStatusCode) return new(status, obj, null);

            var code = obj.TryGetProperty("error", out var e1) ? e1.GetString() : null;
            var message = obj.TryGetProperty("message", out var m1) ? m1.GetString() : null;

            if (status is 408 or 429 || status >= 500)
                return new(status, null, new ApiResult(LicenseCallOutcome.Unavailable, status, code ?? "server_unavailable", message));
            if (code is not null && DenialCodes.Contains(code))
                return new(status, null, new ApiResult(LicenseCallOutcome.Denied, status, code, message));
            if (code == "invalid_device_proof")
                return new(status, null, new ApiResult(LicenseCallOutcome.Rejected, status, code, message));
            return new(status, null, new ApiResult(LicenseCallOutcome.Rejected, status,
                code ?? (status == (int)HttpStatusCode.UnprocessableEntity ? "validation_failed" : "request_rejected"), message));
        }
    }

    public void Dispose() => _http.Dispose();
}
