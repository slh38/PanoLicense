namespace PanoLicense.Client;

/// <summary>
/// Saat geri alma korumalı zaman. Değerlendirme zamanı = max(duvar saati, güvenilir çapa + monoton geçen süre).
/// Çapa açılışta kalıcı en ileri zamandır (watermark); başarılı online kontrolden sonra imzalı sunucu zamanıdır.
/// Böylece geri alınan veya sabitlenen saat süre kazandırmaz; 5 dakikalık tolerans tekrar tekrar kullanılamaz
/// (karar her zaman çapadan ilerleyen zamanla verilir, tolerans yalnız şüphe tespitindedir).
/// </summary>
internal sealed class TrustedClock(TimeProvider time)
{
    public static readonly TimeSpan Tolerance = OfflineLicenseEvaluator.ClockTolerance;

    private readonly object _lock = new();
    private DateTimeOffset _anchor;
    private long _anchorTimestamp;
    private bool _serverAnchored;
    private bool _suspicious;

    /// <summary>Açılış: kalıcı en ileri zamanla başlar. Saat bundan toleranstan fazla gerideyse şüphelidir.</summary>
    public void Start(DateTimeOffset? watermark)
    {
        lock (_lock)
        {
            var wall = time.GetUtcNow();
            _anchor = watermark is { } w && w > wall ? w : wall;
            _anchorTimestamp = time.GetTimestamp();
            _serverAnchored = false;
            _suspicious = watermark is { } seen && wall < seen - Tolerance;
        }
    }

    /// <summary>İmzalı sunucu zamanıyla kontrollü iyileşme: şüphe kalkar, çapa sunucu zamanına geçer.</summary>
    public void AnchorToServer(DateTimeOffset serverTime)
    {
        lock (_lock)
        {
            _anchor = serverTime;
            _anchorTimestamp = time.GetTimestamp();
            _serverAnchored = true;
            _suspicious = false;
        }
    }

    /// <summary>Saat geri alındı mı? Online doğrulama gerekir.</summary>
    public bool Suspicious
    {
        get
        {
            _ = Now();
            lock (_lock) return _suspicious;
        }
    }

    public DateTimeOffset Now()
    {
        lock (_lock)
        {
            var monotonic = _anchor + time.GetElapsedTime(_anchorTimestamp);
            var wall = time.GetUtcNow();
            // Sunucuya çapalanmamış oturumda saat geri çekilirse şüphe; sunucu çapasında monoton zaman zaten esastır.
            if (!_serverAnchored && wall < monotonic - Tolerance) _suspicious = true;
            return wall > monotonic ? wall : monotonic;
        }
    }
}

/// <summary>
/// Online kontrol takvimi (saf mantık, test edilebilir). LICENSING_RULES §25:
/// 6 saatte bir online kontrol; bağlantı hatasında 15 dk, sonra 30 dk, 1 sa, 2 sa, 4 sa, en fazla 6 sa bekleme;
/// küçük jitter; bağlantı dönüşü ve acil ihtiyaç beklemeyi atlar. Yerel kontrol bu takvimden bağımsızdır.
/// </summary>
internal sealed class CheckScheduler(Func<double> random)
{
    public static readonly TimeSpan LocalInterval = TimeSpan.FromHours(1);
    public static readonly TimeSpan OnlineInterval = TimeSpan.FromHours(6);
    public static readonly TimeSpan FirstRetry = TimeSpan.FromMinutes(15);
    public static readonly TimeSpan MaxRetry = TimeSpan.FromHours(6);

    /// <summary>Jitter: beklemenin en fazla %10'u, üst sınır 5 dakika.</summary>
    public static readonly TimeSpan MaxJitter = TimeSpan.FromMinutes(5);

    /// <summary>Hemen yapılacak kontrollerde cihazları dağıtmak için en fazla 30 saniye.</summary>
    public static readonly TimeSpan ImmediateJitter = TimeSpan.FromSeconds(30);

    public int ConsecutiveFailures { get; private set; }
    public DateTimeOffset NextOnlineAt { get; private set; } = DateTimeOffset.MaxValue;

    public void Start(DateTimeOffset now, DateTimeOffset? lastOnlineAt) =>
        NextOnlineAt = lastOnlineAt is { } last && last + OnlineInterval > now
            ? last + OnlineInterval + Jitter(OnlineInterval)
            : now + Random(ImmediateJitter);

    public void OnSuccess(DateTimeOffset now)
    {
        ConsecutiveFailures = 0;
        NextOnlineAt = now + OnlineInterval + Jitter(OnlineInterval);
    }

    /// <summary>Kesin red bağlantı hatası değildir: kademeli tekrar yok, normal takvime döner.</summary>
    public void OnDenied(DateTimeOffset now) => OnSuccess(now);

    public void OnFailure(DateTimeOffset now)
    {
        var delay = RetryDelay(ConsecutiveFailures);
        ConsecutiveFailures++;
        NextOnlineAt = now + delay + Jitter(delay);
    }

    /// <summary>Bağlantı geri geldi: önceki sonuçtan bağımsız beklemeyi atlar; yinelenen tetikleri yönetici birleştirir.</summary>
    public bool OnNetworkRestored(DateTimeOffset now)
    {
        NextOnlineAt = Min(NextOnlineAt, now + Random(ImmediateJitter));
        return true;
    }

    /// <summary>Offline izin bitti, belge yok veya saat şüpheli: ilk fırsatta online doğrulama.</summary>
    public void RequestSoon(DateTimeOffset now)
    {
        if (ConsecutiveFailures == 0) NextOnlineAt = Min(NextOnlineAt, now + Random(ImmediateJitter));
    }

    public static TimeSpan RetryDelay(int failuresSoFar)
    {
        var delay = FirstRetry * Math.Pow(2, Math.Min(failuresSoFar, 10));
        return delay < MaxRetry ? delay : MaxRetry;
    }

    private TimeSpan Jitter(TimeSpan delay)
    {
        var max = delay / 10 < MaxJitter ? delay / 10 : MaxJitter;
        return Random(max);
    }

    private TimeSpan Random(TimeSpan max) => TimeSpan.FromTicks((long)(max.Ticks * Math.Clamp(random(), 0, 1)));

    private static DateTimeOffset Min(DateTimeOffset a, DateTimeOffset b) => a < b ? a : b;
}
