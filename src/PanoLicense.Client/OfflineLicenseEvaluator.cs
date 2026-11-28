namespace PanoLicense.Client;

/// <summary>Doğrulanmış belgenin bu cihaz ve bu an için değerlendirme sonucu.</summary>
public sealed record LicenseEvaluation(
    LicenseState State,
    string Reason,
    DateTimeOffset? ValidUntil,
    TimeSpan OfflineRemaining,
    bool ClockRollbackDetected)
{
    public bool AllowsCommercialOperations => State.AllowsCommercialOperations();
}

/// <summary>
/// Lisans bazlı 7/365 günlük imzalı offline süreyi uygular. Ağ ve disk erişimi yoktur (saf mantık); istemci son imzalı belgeyi
/// ve "son görülen zamanı" kendisi saklar, burada yalnız karar verilir.
///
/// Kurallar (docs/LICENSING_RULES.md):
///  - Belge bu cihaza ait değilse veya cihaz serbest bırakıldıysa: Unknown.
///  - Saat, belgenin üretim anından ya da son görülen zamandan geri alınmışsa: OfflineExpired (yeniden doğrulama).
///  - İptal: Revoked. Lisans bitişi geçtiyse: Expired.
///  - valid_until (son doğrulama + lisans politikası, lisans bitişini geçmez) geçtiyse: OfflineExpired.
///  - Aksi hâlde demo → Demo; aktif lisans çevrimiçi → Active, çevrimdışı → OfflineGrace.
/// </summary>
public static class OfflineLicenseEvaluator
{
    /// <summary>Saat farkı toleransı (NTP kaymaları için).</summary>
    public static readonly TimeSpan ClockTolerance = TimeSpan.FromMinutes(5);

    /// <param name="document">Doğrulanmış son belge (<see cref="LicenseDocumentVerifier"/>).</param>
    /// <param name="expected">Beklenen ürün, lisans, parmak izi ve yerel cihaz anahtarı özeti.</param>
    /// <param name="nowUtc">Yerel saatle şimdiki an.</param>
    /// <param name="lastSeenUtc">İstemcinin daha önce gördüğü en ileri an (saklanır); yoksa null.</param>
    /// <param name="online">Belge şu anki sunucu çağrısında mı alındı?</param>
    public static LicenseEvaluation Evaluate(
        LicenseDocument document,
        LicenseIdentity expected,
        DateTimeOffset nowUtc,
        DateTimeOffset? lastSeenUtc,
        bool online)
    {
        var remaining = document.ValidUntil - nowUtc;
        LicenseEvaluation Result(LicenseState state, string reason, bool rollback = false) =>
            new(state, reason, document.ValidUntil, remaining > TimeSpan.Zero ? remaining : TimeSpan.Zero, rollback);

        if (!expected.Matches(document))
            return Result(LicenseState.Unknown, "identity_mismatch");
        if (document.Version != 2 || document.LicenseType is not ("term" or "perpetual")
            || (document.LicenseType == "term" ? document.ExpiresAt is null : document.ExpiresAt is not null))
            return Result(LicenseState.Unknown, "invalid_license_term");
        if (document.DeviceStatus != "active")
            return Result(LicenseState.Unknown, "device_released");

        var rolledBack = nowUtc < document.IssuedAt - ClockTolerance
            || (lastSeenUtc is { } seen && nowUtc < seen - ClockTolerance);
        if (rolledBack)
            return Result(LicenseState.OfflineExpired, "clock_rollback", rollback: true);

        if (document.Status == "revoked")
            return Result(LicenseState.Revoked, "revoked");
        if (document.LicenseEndUtc is { } end && nowUtc >= end)
            return Result(LicenseState.Expired, document.Status == "demo" ? "demo_expired" : "license_expired");
        if (nowUtc >= document.ValidUntil)
            return Result(LicenseState.OfflineExpired, "offline_grace_exceeded");

        return document.Status switch
        {
            "demo" => Result(LicenseState.Demo, "demo"),
            "active" => online ? Result(LicenseState.Active, "online") : Result(LicenseState.OfflineGrace, "offline"),
            _ => Result(LicenseState.Unknown, "unknown_status"),
        };
    }

    /// <summary>Saklanacak yeni "son görülen" değer: geri alınmış saatle asla geriye gitmez.</summary>
    public static DateTimeOffset NextLastSeen(DateTimeOffset nowUtc, DateTimeOffset? lastSeenUtc) =>
        lastSeenUtc is { } seen && seen > nowUtc ? seen : nowUtc;
}
