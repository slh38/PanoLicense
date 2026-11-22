namespace PanoLicense.Client;

/// <summary>
/// İstemci yazılımların kullanacağı lisans doğrulama sözleşmesi.
/// F0: yalnız sözleşme; uygulama (aktivasyon, imzalı offline lisans) sonraki fazlardadır.
/// </summary>
public interface ILicenseClient
{
    /// <summary>Yerel önbellek + gerekirse sunucu kontrolüyle güncel lisans durumunu döner.</summary>
    Task<LicenseState> GetStateAsync(CancellationToken cancellationToken = default);
}
