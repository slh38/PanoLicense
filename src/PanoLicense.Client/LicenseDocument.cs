using System.Text.Json.Serialization;

namespace PanoLicense.Client;

/// <summary>
/// Sunucunun imzaladığı lisans belgesinin içeriği (payload). Yalnız <see cref="LicenseDocumentVerifier"/>
/// imzayı doğruladıktan sonra oluşturulur; elle oluşturulmuş bir örneğe güvenilmez.
/// Sözleşme: docs/CLIENT_API.md
/// </summary>
public sealed record LicenseDocument
{
    [JsonPropertyName("v")] public int Version { get; init; }
    [JsonPropertyName("license_key")] public string LicenseKey { get; init; } = "";
    [JsonPropertyName("product")] public string Product { get; init; } = "";
    [JsonPropertyName("customer")] public string Customer { get; init; } = "";

    /// <summary>Sunucuda saklanan durum: demo | active | revoked.</summary>
    [JsonPropertyName("status")] public string Status { get; init; } = "";

    /// <summary>Belgenin üretildiği andaki görünen durum (bilgi amaçlı).</summary>
    [JsonPropertyName("state")] public string State { get; init; } = "";

    /// <summary>Lisans politikası: 7 veya 365 gün. Eski belgelerde varsayılan 7.</summary>
    [JsonPropertyName("offline_grace_days")] public int OfflineGraceDays { get; init; } = 7;

    /// <summary>Paket kodu (ör. PRO). Bilgi amaçlıdır; özellik kararı <see cref="Modules"/> ile verilir.</summary>
    [JsonPropertyName("package")] public string? Package { get; init; }

    /// <summary>Geçerli modül kodları: paket + lisansa özel ek modüller (sunucuda birleştirilmiş, imzalı). Eski belgede boş.</summary>
    [JsonPropertyName("modules")] public IReadOnlyList<string> Modules { get; init; } = [];

    [JsonPropertyName("device_limit")] public int DeviceLimit { get; init; }

    /// <summary>Belgenin bağlı olduğu cihazın parmak izi (SHA-256 hex).</summary>
    [JsonPropertyName("fingerprint")] public string Fingerprint { get; init; } = "";

    [JsonPropertyName("device_key_hash")] public string DeviceKeyHash { get; init; } = "";

    /// <summary>active | released</summary>
    [JsonPropertyName("device_status")] public string DeviceStatus { get; init; } = "";

    /// <summary>Lisans bitiş günü (yyyy-MM-dd). Lisans bu günün sonuna (UTC) kadar geçerlidir.</summary>
    [JsonPropertyName("expires_at")] public string ExpiresAt { get; init; } = "";

    /// <summary>Sunucunun belgeyi ürettiği an (UTC); son başarılı doğrulama zamanı.</summary>
    [JsonPropertyName("issued_at")] public DateTimeOffset IssuedAt { get; init; }

    /// <summary>Sunucuya ulaşmadan çalışılabilecek son an (UTC): issued_at + lisans politikası (7/365 gün), lisans bitişini geçmez.</summary>
    [JsonPropertyName("valid_until")] public DateTimeOffset ValidUntil { get; init; }

    /// <summary>Lisansın bittiği an: bitiş gününü izleyen günün 00:00 UTC'si.</summary>
    public DateTimeOffset LicenseEndUtc =>
        DateTimeOffset.ParseExact(ExpiresAt, "yyyy-MM-dd", System.Globalization.CultureInfo.InvariantCulture,
            System.Globalization.DateTimeStyles.AssumeUniversal).AddDays(1);
}
