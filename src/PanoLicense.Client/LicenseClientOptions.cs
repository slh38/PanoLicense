namespace PanoLicense.Client;

/// <summary>İstemci yazılımın (ör. PanoPOS) lisans sunucusuna bağlanma ayarları.</summary>
public sealed class LicenseClientOptions
{
    /// <summary>Lisans sunucusu kök adresi. Domain değişebilir; koda gömülmez, ayardan okunur.</summary>
    public Uri? ServerUrl { get; set; }

    /// <summary>Lisanslanan ürünün kodu (ör. "PANOPOS").</summary>
    public string ProductCode { get; set; } = string.Empty;

    /// <summary>Sunucuya ulaşılamadığında son geçerli doğrulamadan sonra izin verilen süre.</summary>
    public TimeSpan OfflineGracePeriod { get; set; } = TimeSpan.FromDays(7);

    /// <summary>Ayarları doğrular; hatalıysa açıklamalı <see cref="InvalidOperationException"/> fırlatır.</summary>
    public void Validate()
    {
        if (ServerUrl is null || !ServerUrl.IsAbsoluteUri)
            throw new InvalidOperationException("ServerUrl mutlak bir adres olmalıdır.");

        var isLocal = ServerUrl.IsLoopback;
        if (ServerUrl.Scheme != Uri.UriSchemeHttps && !isLocal)
            throw new InvalidOperationException("ServerUrl yerel geliştirme dışında HTTPS olmalıdır.");

        if (string.IsNullOrWhiteSpace(ProductCode))
            throw new InvalidOperationException("ProductCode boş olamaz.");

        if (OfflineGracePeriod <= TimeSpan.Zero)
            throw new InvalidOperationException("OfflineGracePeriod pozitif olmalıdır.");
    }
}
