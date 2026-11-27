using System.Runtime.Versioning;
using System.Security.Cryptography;

namespace PanoLicense.Client;

/// <summary>Cihazın P-256 özel anahtarına erişim. Özel anahtar hiçbir zaman dışa verilmez veya loglanmaz.</summary>
public interface IDeviceKeyStore
{
    /// <summary>Anahtarı açar; yoksa oluşturur. Dönen nesne çağıranın sahipliğindedir (Dispose).</summary>
    ECDsa OpenOrCreate();

    /// <summary>Anahtar donanımda (TPM) mı tutuluyor? Bilgi amaçlıdır.</summary>
    bool IsHardwareBacked { get; }
}

/// <summary>
/// Windows CNG anahtar deposu. Önce TPM (Microsoft Platform Crypto Provider), olmazsa yazılımsal KSP kullanılır.
/// Anahtar dışa aktarılamaz (ExportPolicy = None); kopyalanan durum dosyası başka makinede imzalanamaz.
/// Yazılımsal KSP'nin TPM kadar güçlü olmadığı kabul edilir (docs/ARCHITECTURE.md §9).
/// </summary>
[SupportedOSPlatform("windows")]
public sealed class CngDeviceKeyStore : IDeviceKeyStore
{
    private const string PlatformProvider = "Microsoft Platform Crypto Provider";
    private readonly string _keyName;
    private readonly bool _machine;
    private readonly bool _allowTpm;

    public CngDeviceKeyStore(string keyName, DeviceKeyScope scope = DeviceKeyScope.CurrentUser, bool allowTpm = true)
    {
        if (string.IsNullOrWhiteSpace(keyName)) throw new ArgumentException("Anahtar adı boş olamaz.", nameof(keyName));
        _keyName = keyName;
        _machine = scope == DeviceKeyScope.Machine;
        _allowTpm = allowTpm;
    }

    public bool IsHardwareBacked { get; private set; }

    public ECDsa OpenOrCreate()
    {
        var key = TryOpen(new CngProvider(PlatformProvider), hardware: true)
            ?? TryOpen(CngProvider.MicrosoftSoftwareKeyStorageProvider, hardware: false)
            ?? (_allowTpm ? TryCreate(new CngProvider(PlatformProvider), hardware: true) : null)
            ?? TryCreate(CngProvider.MicrosoftSoftwareKeyStorageProvider, hardware: false)
            ?? throw new CryptographicException("Cihaz anahtarı oluşturulamadı.");

        // TPM sağlayıcısı algoritmayı genel "ECDSA" olarak bildirebilir; eğri açık anahtardan doğrulanır.
        var ecdsa = new ECDsaCng(key);
        if (ecdsa.ExportParameters(false).Curve.Oid.Value != "1.2.840.10045.3.1.7")
        {
            ecdsa.Dispose();
            throw new CryptographicException("Mevcut cihaz anahtarı P-256 değil.");
        }
        return ecdsa;
    }

    /// <summary>Yalnız testler ve kontrollü kaldırma için: anahtarı her iki sağlayıcıdan siler.</summary>
    public void Delete()
    {
        foreach (var provider in new[] { new CngProvider(PlatformProvider), CngProvider.MicrosoftSoftwareKeyStorageProvider })
        {
            using var key = TryOpen(provider, hardware: provider.Provider == PlatformProvider);
            key?.Delete();
        }
    }

    private CngKey? TryOpen(CngProvider provider, bool hardware)
    {
        try
        {
            var options = _machine ? CngKeyOpenOptions.MachineKey : CngKeyOpenOptions.None;
            if (!CngKey.Exists(_keyName, provider, options)) return null;
            var key = CngKey.Open(_keyName, provider, options);
            IsHardwareBacked = hardware;
            return key;
        }
        catch (CryptographicException)
        {
            return null; // sağlayıcı yok (ör. TPM'siz makine)
        }
    }

    private CngKey? TryCreate(CngProvider provider, bool hardware)
    {
        try
        {
            var key = CngKey.Create(CngAlgorithm.ECDsaP256, _keyName, new CngKeyCreationParameters
            {
                Provider = provider,
                ExportPolicy = CngExportPolicies.None,
                KeyUsage = CngKeyUsages.Signing,
                KeyCreationOptions = _machine ? CngKeyCreationOptions.MachineKey : CngKeyCreationOptions.None,
            });
            IsHardwareBacked = hardware;
            return key;
        }
        catch (CryptographicException)
        {
            return null;
        }
    }
}
