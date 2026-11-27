namespace PanoLicense.Client;

/// <summary>Güvenilir uygulama bağlamı; belge içinden türetilmez. Anahtar özeti yerel cihaz anahtarından gelir.</summary>
public sealed record LicenseIdentity(string Product, string LicenseKey, string Fingerprint, string DeviceKeyHash)
{
    internal bool Matches(LicenseDocument document) =>
        !string.IsNullOrWhiteSpace(Product) && !string.IsNullOrWhiteSpace(LicenseKey) &&
        Fingerprint.Length == 64 && DeviceKeyHash.Length == 64 &&
        string.Equals(document.Product, Product, StringComparison.Ordinal) &&
        string.Equals(document.LicenseKey, LicenseKey, StringComparison.Ordinal) &&
        string.Equals(document.Fingerprint, Fingerprint, StringComparison.OrdinalIgnoreCase) &&
        string.Equals(document.DeviceKeyHash, DeviceKeyHash, StringComparison.OrdinalIgnoreCase);
}