namespace PanoLicense.Client;

/// <summary>Cihaz anahtarının CNG'deki kapsamı.</summary>
public enum DeviceKeyScope
{
    /// <summary>Uygulamayı çalıştıran Windows kullanıcısı (varsayılan; yönetici izni gerekmez).</summary>
    CurrentUser = 0,

    /// <summary>Makine geneli (servis hesabıyla çalışan ürünler için). Oluşturma yönetici izni gerektirebilir.</summary>
    Machine = 1,
}

/// <summary>
/// İstemci yazılımın (ör. PanoPOS) lisans sunucusuna bağlanma ayarları. Ürüne özel kod içermez;
/// her .NET 8 ürünü kendi ProductCode, açık anahtar ve dizin değerleriyle kullanır.
/// Offline süre ayarı yoktur: otorite sunucunun imzaladığı lisans politikasıdır (7/365 gün).
/// </summary>
public sealed class LicenseClientOptions
{
    /// <summary>Lisans sunucusu kök adresi (ör. https://lisans.jokeryazilim.com.tr). Koda gömülmez, ayardan okunur.</summary>
    public Uri? ServerUrl { get; set; }

    /// <summary>Lisanslanan ürünün kodu (ör. "PANOPOS").</summary>
    public string ProductCode { get; set; } = string.Empty;

    /// <summary>kid → sunucu imza açık anahtarı (PEM). Ürüne gömülür; özel anahtar istemcide bulunmaz.</summary>
    public IReadOnlyDictionary<string, string> SigningPublicKeys { get; set; } = new Dictionary<string, string>();

    /// <summary>Korumalı durum dosyasının dizini. Boşsa %LOCALAPPDATA%\PanoLicense\{ProductCode}.</summary>
    public string? StateDirectory { get; set; }

    /// <summary>Cihaz anahtarı kapsamı (CNG).</summary>
    public DeviceKeyScope KeyScope { get; set; } = DeviceKeyScope.CurrentUser;

    /// <summary>Sunucuda görünen cihaz adı (isteğe bağlı, en fazla 100 karakter). Boşsa bilgisayar adı.</summary>
    public string? DeviceName { get; set; }

    /// <summary>Uygulama sürümü (isteğe bağlı, en fazla 30 karakter).</summary>
    public string? AppVersion { get; set; }

    /// <summary>Tek HTTP isteğinin zaman aşımı.</summary>
    public TimeSpan RequestTimeout { get; set; } = TimeSpan.FromSeconds(20);

    /// <summary>
    /// Cihaz parmak izi (64 küçük hex SHA-256). Boşsa Windows MachineGuid + ürün kodundan türetilir.
    /// Yardımcı sinyaldir; cihaz kimliğini CNG anahtarı kanıtlar.
    /// </summary>
    public Func<string>? FingerprintProvider { get; set; }

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

        if (RequestTimeout <= TimeSpan.Zero)
            throw new InvalidOperationException("RequestTimeout pozitif olmalıdır.");
    }

    internal void ValidateForManager()
    {
        Validate();
        if (SigningPublicKeys.Count == 0)
            throw new InvalidOperationException("En az bir SigningPublicKeys (kid → PEM) gerekir.");
    }

    internal string ResolveStateDirectory() =>
        string.IsNullOrWhiteSpace(StateDirectory)
            ? Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "PanoLicense", SafeName(ProductCode))
            : StateDirectory;

    internal static string SafeName(string value) =>
        new(value.Where(c => char.IsAsciiLetterOrDigit(c) || c is '-' or '_').ToArray());
}
