namespace PanoLicense.Client;

/// <summary>
/// İstemcinin gördüğü lisans durumu. Ticari işlemler yalnız <see cref="Active"/> ve
/// <see cref="OfflineGrace"/> durumlarında açıktır (docs/LICENSING_RULES.md).
/// </summary>
public enum LicenseState
{
    Unknown = 0,
    Active = 1,
    OfflineGrace = 2,
    Expired = 3,
    Revoked = 4,
    DeviceLimitExceeded = 5,
}

public static class LicenseStateExtensions
{
    public static bool AllowsCommercialOperations(this LicenseState state) =>
        state is LicenseState.Active or LicenseState.OfflineGrace;
}
