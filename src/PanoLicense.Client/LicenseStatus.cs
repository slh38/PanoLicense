namespace PanoLicense.Client;

/// <summary>
/// Lisansın bilgi görüntüsü. State alanı anlıktır; ticari izin her okumada yöneticinin güncel kararından gelir. Satış akışı yalnız bunu (veya <see cref="LicenseManager.AllowsCommercialOperations"/>)
/// okur; okumak ağ, disk veya kriptografi işlemi başlatmaz.
/// </summary>
public sealed record LicenseStatus(
    LicenseState State,
    string Reason,
    string? LicenseKey = null,
    string? CustomerName = null,
    string? ServerStatus = null,
    string? LicenseExpiresOn = null,
    int OfflineGraceDays = 7,
    DateTimeOffset? CommercialAllowedUntil = null,
    DateTimeOffset? LastOnlineAt = null,
    DateTimeOffset? NextOnlineCheckAt = null,
    string? LastError = null,
    bool HardwareBackedKey = false,
    string? Package = null,
    IReadOnlyList<string>? Modules = null,
    string? LicenseType = null)
{
    [System.Text.Json.Serialization.JsonIgnore]
    internal Func<bool>? CommercialDecision { get; init; }

    /// <summary>Eski görüntüde bile güncel yönetici kararını okur. Yöneticiye bağlı olmayan görüntü izin vermez.</summary>
    public bool AllowsCommercialOperations => CommercialDecision?.Invoke() ?? false;

    internal static LicenseStatus NotStarted { get; } = new(LicenseState.Unknown, "not_started");
}

/// <summary>Demo kaydı için kurulum ekranında girilen bilgiler.</summary>
public sealed record DemoRegistration(
    string CustomerName,
    string? City = null,
    string? Phone = null,
    string? Email = null,
    string? TaxNumber = null,
    string? InstallerUsername = null);

/// <summary>Demo/aktivasyon/kontrol çağrısının sonucu.</summary>
public sealed record LicenseOperationResult(LicenseCallOutcome Outcome, string? ErrorCode, string? Message, LicenseStatus Status)
{
    public bool Succeeded => Outcome == LicenseCallOutcome.Success;
}
