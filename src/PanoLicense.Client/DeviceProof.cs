using System.Security.Cryptography;
using System.Text;

namespace PanoLicense.Client;

/// <summary>HTTP ve saklama içermez. Üretimde anahtar CNG deposundan açılır (<see cref="CngDeviceKeyStore"/>).</summary>
public static class DeviceProof
{
    public static string PublicKeyHash(ECDsa deviceKey)
    {
        EnsureP256(deviceKey);
        return Convert.ToHexString(SHA256.HashData(deviceKey.ExportSubjectPublicKeyInfo())).ToLowerInvariant();
    }

    /// <summary>Sunucunun message alanının aynen UTF-8 baytlarını imzalar; base64 DER döner.</summary>
    public static string Sign(ECDsa deviceKey, string message)
    {
        EnsureP256(deviceKey);
        return Convert.ToBase64String(deviceKey.SignData(Encoding.UTF8.GetBytes(message),
            HashAlgorithmName.SHA256, DSASignatureFormat.Rfc3279DerSequence));
    }

    private static void EnsureP256(ECDsa key)
    {
        if (key.ExportParameters(false).Curve.Oid.Value != "1.2.840.10045.3.1.7")
            throw new ArgumentException("Cihaz anahtarı ECDSA P-256 olmalıdır.", nameof(key));
    }
}