namespace PanoLicense.Client;

/// <summary>
/// İstemcinin gördüğü lisans durumu. Ticari işlemler yalnız <see cref="Active"/>, <see cref="OfflineGrace"/>
/// ve <see cref="Demo"/> durumlarında açıktır (docs/LICENSING_RULES.md).
/// Sayısal değerler saklanabileceği için değiştirilmez; yeni durumlar sona eklenir.
/// </summary>
public enum LicenseState
{
    /// <summary>Belge yok, geçersiz, başka cihaza ait veya cihaz serbest bırakılmış.</summary>
    Unknown = 0,

    /// <summary>Sunucuyla doğrulandı, süre içinde.</summary>
    Active = 1,

    /// <summary>Sunucuya ulaşılamıyor; son imzalı belgenin offline süresi (lisans politikasına göre 7/365 gün) dolmadı.</summary>
    OfflineGrace = 2,

    /// <summary>Lisans (veya demo) süresi doldu.</summary>
    Expired = 3,

    /// <summary>Lisans iptal edildi.</summary>
    Revoked = 4,

    /// <summary>Sunucu yeni cihazı limit nedeniyle reddetti.</summary>
    DeviceLimitExceeded = 5,

    /// <summary>14 günlük demo, onay bekliyor.</summary>
    Demo = 6,

    /// <summary>Offline süre doldu veya saat geri alındı; çalışmak için sunucuyla yeniden doğrulama gerekir.</summary>
    OfflineExpired = 7,
}

public static class LicenseStateExtensions
{
    public static bool AllowsCommercialOperations(this LicenseState state) =>
        state is LicenseState.Active or LicenseState.OfflineGrace or LicenseState.Demo;
}
