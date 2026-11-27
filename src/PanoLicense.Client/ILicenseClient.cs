namespace PanoLicense.Client;

/// <summary>
/// İstemci yazılımların kullanacağı lisans doğrulama sözleşmesi.
/// Uygulaması: <see cref="LicenseManager"/>.
/// </summary>
public interface ILicenseClient
{
    /// <summary>Bellekteki güncel yerel kararı döner; ağ beklemez.</summary>
    Task<LicenseState> GetStateAsync(CancellationToken cancellationToken = default);
}
