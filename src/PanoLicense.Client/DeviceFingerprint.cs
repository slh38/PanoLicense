using System.Runtime.Versioning;
using System.Security.Cryptography;
using System.Text;
using Microsoft.Win32;

namespace PanoLicense.Client;

/// <summary>Varsayılan cihaz parmak izi: SHA-256(MachineGuid + ürün kodu). Yardımcı sinyaldir, kimlik değildir.</summary>
public static class DeviceFingerprint
{
    [SupportedOSPlatform("windows")]
    public static string ForWindows(string productCode)
    {
        using var key = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, RegistryView.Registry64)
            .OpenSubKey(@"SOFTWARE\Microsoft\Cryptography");
        var machineGuid = key?.GetValue("MachineGuid") as string;
        if (string.IsNullOrWhiteSpace(machineGuid))
            throw new InvalidOperationException("Windows MachineGuid okunamadı.");
        return Hash($"panolicense-fp-v1|{machineGuid.Trim().ToLowerInvariant()}|{productCode}");
    }

    public static string Hash(string value) =>
        Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(value))).ToLowerInvariant();

    internal static bool IsValid(string value) =>
        value.Length == 64 && value.All(c => c is >= '0' and <= '9' or >= 'a' and <= 'f');
}
