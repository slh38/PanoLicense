using System.Runtime.Versioning;
using System.Security.Cryptography;
using System.Text.Json;
using System.Text.Json.Serialization;
using Microsoft.Win32;

namespace PanoLicense.Client;

/// <summary>Kesin red kaydı (imzasız sunucu kararı). Daha yeni imzalı belge gelene kadar ticari işlemleri kapatır.</summary>
internal sealed record DenialRecord(
    [property: JsonPropertyName("license_key")] string LicenseKey,
    [property: JsonPropertyName("code")] string Code,
    [property: JsonPropertyName("at")] DateTimeOffset At);

/// <summary>Kalıcı istemci durumu. Bütünlüğü cihaz anahtarı imzasıyla korunur.</summary>
internal sealed record PersistedState
{
    [JsonPropertyName("v")] public int Version { get; init; } = 1;
    [JsonPropertyName("product")] public string Product { get; init; } = "";
    [JsonPropertyName("license_key")] public string? LicenseKey { get; init; }

    /// <summary>Son kabul edilmiş imzalı belge zarfı (sunucunun license_document JSON'u).</summary>
    [JsonPropertyName("document")] public string? Document { get; init; }

    /// <summary>Kabul edilmiş en yeni belgenin issued_at değeri; daha eski belge kabul edilmez.</summary>
    [JsonPropertyName("newest_issued_at")] public DateTimeOffset? NewestIssuedAt { get; init; }

    /// <summary>Görülen en ileri güvenilir zaman (saat geri alma koruması). Yalnız sunucu zamanıyla geri çekilir.</summary>
    [JsonPropertyName("watermark")] public DateTimeOffset? Watermark { get; init; }

    [JsonPropertyName("last_online_at")] public DateTimeOffset? LastOnlineAt { get; init; }

    /// <summary>Sunucudan imzalı olarak öğrenilen iptaller. Kalıcıdır, hiçbir işlemle kaldırılmaz.</summary>
    [JsonPropertyName("revoked")] public IReadOnlyList<string> Revoked { get; init; } = [];

    [JsonPropertyName("denial")] public DenialRecord? Denial { get; init; }

    /// <summary>Bozuk durum/belge tespit edildi; yeni geçerli online belge gelmeden offline açılamaz.</summary>
    [JsonPropertyName("requires_online")] public bool RequiresOnline { get; init; }

    [JsonPropertyName("saved_at")] public DateTimeOffset SavedAt { get; init; }

    /// <summary>
    /// İki kopyanın birleşimi: iptaller birleşir, zamanlar en ileri olanı alır, en yeni belge ve en yeni red kalır.
    /// Tek kopyanın eski yedekle değiştirilmesi iptali veya güncellik eşiğini geri almaz.
    /// </summary>
    public static PersistedState? Merge(IEnumerable<PersistedState> states)
    {
        var list = states.ToList();
        if (list.Count == 0) return null;
        var newestDoc = list.OrderByDescending(s => s.NewestIssuedAt ?? DateTimeOffset.MinValue).ThenByDescending(s => s.SavedAt).First();
        return newestDoc with
        {
            RequiresOnline = list.Any(s => s.RequiresOnline),
            Revoked = list.SelectMany(s => s.Revoked).Distinct(StringComparer.Ordinal).ToList(),
            Watermark = Max(list.Select(s => s.Watermark)),
            NewestIssuedAt = Max(list.Select(s => s.NewestIssuedAt)),
            LastOnlineAt = Max(list.Select(s => s.LastOnlineAt)),
            Denial = list.Select(s => s.Denial).Where(d => d is not null).OrderByDescending(d => d!.At).FirstOrDefault(),
        };
    }

    private static DateTimeOffset? Max(IEnumerable<DateTimeOffset?> values) => values.Max();
}

/// <summary>Durumun ham baytlarının saklandığı yer (dosya, kayıt defteri; testlerde bellek).</summary>
public interface IStateSlot
{
    byte[]? Read();
    void Write(byte[] data);
}

/// <summary>Atomik dosya yazımı: geçici dosya + flush + yer değiştirme. Yarım yazılmış dosya kalmaz.</summary>
public sealed class FileStateSlot(string path) : IStateSlot
{
    public byte[]? Read()
    {
        try
        {
            return File.Exists(path) ? File.ReadAllBytes(path) : null;
        }
        catch (IOException)
        {
            return null;
        }
        catch (UnauthorizedAccessException)
        {
            return null;
        }
    }

    public void Write(byte[] data)
    {
        Directory.CreateDirectory(Path.GetDirectoryName(Path.GetFullPath(path))!);
        var temp = path + ".tmp";
        using (var stream = new FileStream(temp, FileMode.Create, FileAccess.Write, FileShare.None))
        {
            stream.Write(data);
            stream.Flush(flushToDisk: true);
        }
        File.Move(temp, path, overwrite: true);
    }
}

/// <summary>İkinci kopya: kullanıcı veya makine kayıt defteri. Dosyanın eski yedeğe döndürülmesine karşı.</summary>
[SupportedOSPlatform("windows")]
public sealed class RegistryStateSlot(string subKey, string valueName, bool machine = false) : IStateSlot
{
    private RegistryKey Root => machine ? Registry.LocalMachine : Registry.CurrentUser;

    public byte[]? Read()
    {
        try
        {
            using var key = Root.OpenSubKey(subKey);
            return key?.GetValue(valueName) as byte[];
        }
        catch (Exception e) when (e is IOException or UnauthorizedAccessException or System.Security.SecurityException)
        {
            return null;
        }
    }

    public void Write(byte[] data)
    {
        using var key = Root.CreateSubKey(subKey, writable: true) ?? throw new IOException("Kayıt defteri anahtarı açılamadı.");
        key.SetValue(valueName, data, RegistryValueKind.Binary);
    }
}

/// <summary>
/// Kalıcı durumu cihaz anahtarıyla imzalayarak birden fazla yuvaya yazar ve okurken birleştirir.
/// İmza; başka makineye kopyalanan veya elle değiştirilen durumu geçersiz kılar (anahtar dışa aktarılamaz).
/// Tüm yuvaların birlikte eski hâline döndürülmesi (tam disk/VM geri dönüşü) yalnız online doğrulamayla sınırlanır.
/// </summary>
internal sealed class ProtectedStateStore(IReadOnlyList<IStateSlot> slots)
{
    private static readonly JsonSerializerOptions Json = new() { DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull };

    public PersistedState? Load(ECDsa deviceKey, string keyHash, string product, out bool invalidState)
    {
        invalidState = false;
        var states = new List<PersistedState>();
        foreach (var slot in slots)
        {
            var bytes = slot.Read();
            var state = Unwrap(bytes, deviceKey, keyHash, product);
            if (state is not null) states.Add(state);
            else if (bytes is not null) invalidState = true;
        }
        return PersistedState.Merge(states);
    }

    /// <summary>Tüm yuvalara yazılabildiyse true. Herhangi biri yazılamazsa false (çağıran güvenli tarafta kalır).</summary>
    public bool Save(PersistedState state, ECDsa deviceKey, string keyHash)
    {
        var payload = JsonSerializer.SerializeToUtf8Bytes(state, Json);
        var signature = deviceKey.SignData(payload, HashAlgorithmName.SHA256, DSASignatureFormat.Rfc3279DerSequence);
        var envelope = JsonSerializer.SerializeToUtf8Bytes(new Envelope(1, keyHash, Convert.ToBase64String(payload), Convert.ToBase64String(signature)));

        var written = 0;
        foreach (var slot in slots)
        {
            try
            {
                slot.Write(envelope);
                written++;
            }
            catch (Exception e) when (e is IOException or UnauthorizedAccessException or System.Security.SecurityException)
            {
                // Diğer yuva denenir; sonuç çağırana bildirilir.
            }
        }
        return written == slots.Count;
    }

    private static PersistedState? Unwrap(byte[]? data, ECDsa deviceKey, string keyHash, string product)
    {
        if (data is null || data.Length == 0) return null;
        try
        {
            var env = JsonSerializer.Deserialize<Envelope>(data);
            if (env is null || env.V != 1 || !string.Equals(env.Key, keyHash, StringComparison.Ordinal)) return null;
            var payload = Convert.FromBase64String(env.Payload);
            var signature = Convert.FromBase64String(env.Sig);
            if (!deviceKey.VerifyData(payload, signature, HashAlgorithmName.SHA256, DSASignatureFormat.Rfc3279DerSequence))
                return null;
            var state = JsonSerializer.Deserialize<PersistedState>(payload, Json);
            return state is { Version: 1 } && state.Product == product ? state : null;
        }
        catch (Exception e) when (e is JsonException or FormatException or CryptographicException)
        {
            return null; // bozuk kopya yok sayılır; offline hak yeniden başlamaz
        }
    }

    private sealed record Envelope(
        [property: JsonPropertyName("v")] int V,
        [property: JsonPropertyName("key")] string Key,
        [property: JsonPropertyName("payload")] string Payload,
        [property: JsonPropertyName("sig")] string Sig);
}
