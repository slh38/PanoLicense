using System.Net.NetworkInformation;
using System.Runtime.Versioning;
using System.Security.Cryptography;
using System.Text.Json.Nodes;

namespace PanoLicense.Client;

/// <summary>
/// Ürünlerin kullandığı tek giriş noktası: cihaz anahtarı, imzalı belge, korumalı durum ve arka plan kontrolleri.
///
/// Kurallar (docs/LICENSING_RULES.md §22–25, docs/ARCHITECTURE.md §9):
///  - Açılışta ve saatte bir yerel kontrol (ağ yok). Offline izin veya lisans bitiş anında ek yerel kontrol.
///  - 6 saatte bir online kontrol; hata sonrası 15 dk ve kademeli bekleme + jitter; bağlantı dönüşünde hemen.
///  - Tüm ağ/disk/kriptografi işi arka planda. <see cref="Current"/> ve <see cref="AllowsCommercialOperations"/>
///    yalnız bellekteki kararı okur; satış internet beklemez.
///  - Kesin red bağlantı hatası değildir; imzalı iptal kalıcıdır ve eski belgeye dönülmez.
///
/// Thread-safe. Olaylar arka plan iş parçacığında çağrılır; UI'a aktarmak çağıranın işidir.
/// </summary>
public sealed class LicenseManager : ILicenseClient, IAsyncDisposable
{
    private readonly LicenseClientOptions _options;
    private readonly IDeviceKeyStore _keys;
    private readonly ProtectedStateStore _store;
    private readonly LicenseApiClient _api;
    private readonly LicenseDocumentVerifier _verifier;
    private readonly TrustedClock _clock;
    private readonly CheckScheduler _scheduler;
    private readonly bool _background;

    private readonly object _stateLock = new();               // bellek durumu + kalıcı yazma (kısa, senkron)
    private readonly SemaphoreSlim _network = new(1, 1);      // sunucu çağrıları tek tek
    private readonly SemaphoreSlim _wake = new(0, 1);
    private readonly object _inflightLock = new();
    private Task<LicenseStatus>? _inflightCheck;

    private ECDsa? _deviceKey;
    private DeviceIdentity? _device;
    private PersistedState _state;
    private LicenseDocument? _document;
    private bool _onlineConfirmed;
    private volatile bool _persistPending;
    private volatile bool _requiresOnline;
    private volatile bool _disposed;
    private readonly Func<bool> _commercialDecision;
    private bool _networkNotificationPending;
    private DateTimeOffset? _lastNetworkNotificationAt;
    private string? _lastError;
    private DateTimeOffset _nextLocalAt;

    private volatile LicenseStatus _status = LicenseStatus.NotStarted;
    private Task? _started;
    private Task? _loop;
    private readonly CancellationTokenSource _cts = new();

    /// <summary>Durum değiştiğinde (arka plan iş parçacığında) çağrılır.</summary>
    public event EventHandler<LicenseStatus>? StatusChanged;

    /// <summary>Windows için hazır kurulum: CNG (mümkünse TPM) anahtarı, dosya + kayıt defteri kopyası, MachineGuid parmak izi.</summary>
    [SupportedOSPlatform("windows")]
    public static LicenseManager CreateForWindows(LicenseClientOptions options)
    {
        options.ValidateForManager();
        var name = LicenseClientOptions.SafeName(options.ProductCode);
        var machine = options.KeyScope == DeviceKeyScope.Machine;
        options.FingerprintProvider ??= () => DeviceFingerprint.ForWindows(options.ProductCode);
        IStateSlot[] slots =
        [
            new FileStateSlot(Path.Combine(options.ResolveStateDirectory(), "license.state")),
            new RegistryStateSlot($@"Software\PanoLicense\{name}", "state", machine),
        ];
        return new LicenseManager(options, new CngDeviceKeyStore($"PanoLicense-{name}-device", options.KeyScope), slots);
    }

    /// <summary>Platformdan bağımsız kurulum (özel anahtar deposu/yuvalar). FingerprintProvider zorunludur.</summary>
    public LicenseManager(LicenseClientOptions options, IDeviceKeyStore keys, IReadOnlyList<IStateSlot> slots,
        HttpMessageHandler? httpHandler = null, TimeProvider? timeProvider = null)
        : this(options, keys, slots, httpHandler, timeProvider, Random.Shared.NextDouble, background: true)
    {
    }

    internal LicenseManager(LicenseClientOptions options, IDeviceKeyStore keys, IReadOnlyList<IStateSlot> slots,
        HttpMessageHandler? httpHandler, TimeProvider? timeProvider, Func<double> random, bool background)
    {
        options.ValidateForManager();
        if (options.FingerprintProvider is null) throw new InvalidOperationException("FingerprintProvider gerekli.");
        if (slots.Count == 0) throw new ArgumentException("En az bir durum yuvası gerekir.", nameof(slots));
        _commercialDecision = () => AllowsCommercialOperations;
        _options = options;
        _keys = keys;
        _store = new ProtectedStateStore(slots);
        _api = new LicenseApiClient(options, httpHandler);
        _verifier = new LicenseDocumentVerifier(options.SigningPublicKeys);
        _clock = new TrustedClock(timeProvider ?? TimeProvider.System);
        _scheduler = new CheckScheduler(random);
        _background = background;
        _state = new PersistedState { Product = options.ProductCode };
    }

    internal CheckScheduler Scheduler => _scheduler;

    /// <summary>Güncel karar (anlık, engellemez).</summary>
    public LicenseStatus Current => _status;

    /// <summary>
    /// Satış/tahsilat/belge için tek kontrol. Ağ veya disk kullanmaz; imzalı izin bitişi geçtiyse
    /// saatlik kontrolü beklemeden kapanır.
    /// </summary>
    public bool AllowsCommercialOperations
    {
        get
        {
            var status = _status;
            return !_disposed && !_persistPending && !_requiresOnline &&
                   status.State.AllowsCommercialOperations() &&
                   status.CommercialAllowedUntil is { } until &&
                   _clock.Now() < until && !_clock.Suspicious;
        }
    }

    /// <summary>
    /// Modül kullanılabilir mi? Yalnız bellekteki doğrulanmış imzalı belgeye bakar (ağ/disk yok).
    /// Lisans ticari işleme kapalıysa hiçbir modül açık değildir. Değişiklikler sonraki başarılı online kontrolde gelir.
    /// </summary>
    public bool HasModule(string moduleCode)
    {
        var modules = _status.Modules;
        return modules is { Count: > 0 } && AllowsCommercialOperations &&
               modules.Contains(moduleCode, StringComparer.Ordinal);
    }

    /// <summary>
    /// Ürün sunucusunun (ör. PanoPOS WebApi) challenge'ı için ticari yetki kanıtı üretir (ağ/disk yok).
    /// Belge, açık anahtar ve imza aynı kilit altında, o anki taze yerel karardan alınır; ticari işlem kapalıysa
    /// (iptal, süre/offline bitişi, saat şüphesi, red, kalıcı yazma hatası, online doğrulama gereksinimi) kanıt üretilmez.
    /// Özel anahtar dışarı verilmez. Doğrulama: <see cref="CommercialProofVerifier"/>.
    /// </summary>
    public CommercialProofResult CreateCommercialProof(string challenge)
    {
        if (!CommercialProofFormat.IsValidChallenge(challenge))
            return new CommercialProofResult(false, "invalid_challenge", null, _status);

        lock (_stateLock)
        {
            if (_disposed || _device is null || _deviceKey is null)
                return new CommercialProofResult(false, "not_started", null, _status);

            var now = _clock.Now();
            var (status, _) = Evaluate(now);
            var document = _document;
            var envelope = _state.Document;
            var allowed = !_persistPending && !_requiresOnline && !_clock.Suspicious &&
                          status.State.AllowsCommercialOperations() &&
                          status.CommercialAllowedUntil is { } until && now < until &&
                          document is not null && envelope is not null && document.LicenseKey == _state.LicenseKey;
            if (!allowed)
            {
                var reason = _persistPending ? "state_write_failed"
                    : _requiresOnline ? "online_verification_required"
                    : _clock.Suspicious ? "clock_rollback"
                    : !status.State.AllowsCommercialOperations() ? status.Reason
                    : document is null || envelope is null ? "no_valid_document"
                    : "offline_grace_exceeded";
                return new CommercialProofResult(false, reason, null, status);
            }

            var message = CommercialProofFormat.BuildMessage(challenge, _options.ProductCode, document!.LicenseKey, _device.KeyHash, envelope!);
            string signature;
            try
            {
                signature = Convert.ToBase64String(_deviceKey.SignData(System.Text.Encoding.UTF8.GetBytes(message),
                    HashAlgorithmName.SHA256, DSASignatureFormat.Rfc3279DerSequence));
            }
            catch (CryptographicException)
            {
                return new CommercialProofResult(false, "device_key_unavailable", null, status);
            }

            return new CommercialProofResult(true, null, new CommercialProof(challenge, envelope!, _device.PublicKeyPem,
                signature, document.LicenseKey, document.Modules, status.CommercialAllowedUntil!.Value), status);
        }
    }

    /// <summary>
    /// Sunucudan imzalı olarak öğrenilmiş iptal belgesi (opak zarf metni); ürün sunucusuna kalıcı iptal için iletilir.
    /// İmzalı iptal yoksa null. Ticari yetki vermez.
    /// </summary>
    public string? GetSignedRevocationDocument()
    {
        lock (_stateLock)
            return _document is { Status: "revoked" } && _state.Document is { } envelope && _document.LicenseKey == _state.LicenseKey
                ? envelope
                : null;
    }

    /// <summary>Anlık karar; izin bitişi geçmiş ama saatlik kontrol henüz çalışmamışsa OfflineExpired döner.</summary>
    public Task<LicenseState> GetStateAsync(CancellationToken cancellationToken = default)
    {
        var status = _status;
        return Task.FromResult(status.State.AllowsCommercialOperations() && !AllowsCommercialOperations
            ? (_persistPending || _requiresOnline || _disposed ? LicenseState.Unknown : LicenseState.OfflineExpired)
            : status.State);
    }

    /// <summary>
    /// Anahtarı açar, korumalı durumu yükler, yerel kontrolü yapar ve arka plan döngüsünü başlatır.
    /// Ağ beklemez. Birden fazla çağrı aynı başlatmayı döner.
    /// </summary>
    public Task StartAsync(CancellationToken cancellationToken = default)
    {
        lock (_inflightLock)
        {
            return _started ??= Task.Run(() =>
            {
                Initialize();
                if (!_background) return; // testler kontrolleri elle tetikler
                SubscribeNetwork();
                _loop = Task.Run(() => LoopAsync(_cts.Token));
            }, CancellationToken.None);
        }
    }

    /// <summary>PanoPOS kurulumunda demo kaydı (14 gün, cihaz limiti 1).</summary>
    public async Task<LicenseOperationResult> RegisterDemoAsync(DemoRegistration registration, CancellationToken ct = default)
    {
        var extra = new JsonObject
        {
            ["customer"] = new JsonObject
            {
                ["name"] = registration.CustomerName?.Trim(),
                ["city"] = Blank(registration.City),
                ["phone"] = Blank(registration.Phone),
                ["email"] = Blank(registration.Email),
                ["tax_number"] = Blank(registration.TaxNumber),
            },
            ["installer_username"] = Blank(registration.InstallerUsername),
        };
        return await RunOperationAsync("demo", "", extra, ct).ConfigureAwait(false);
    }

    /// <summary>Lisans anahtarıyla bu cihazı aktive eder. Aynı cihazın tekrarı kota tüketmez.</summary>
    public Task<LicenseOperationResult> ActivateAsync(string licenseKey, CancellationToken ct = default)
    {
        var key = LicenseApiClient.NormalizeKey(licenseKey ?? "");
        if (key.Length == 0 || key.Length > 40)
            return Task.FromResult(new LicenseOperationResult(LicenseCallOutcome.Rejected, "invalid_license_key", "Lisans anahtarı geçersiz.", _status));
        return RunOperationAsync("activate", key, null, ct);
    }

    /// <summary>
    /// Online kontrolü hemen yapar. Devam eden bir kontrol varsa ona katılır (paralel istek oluşmaz).
    /// Satış akışından çağrılmamalıdır.
    /// </summary>
    public Task<LicenseStatus> CheckNowAsync(CancellationToken ct = default)
    {
        lock (_inflightLock)
        {
            if (_inflightCheck is { IsCompleted: false } running) return running;
            return _inflightCheck = CheckCoreAsync(ct);
        }
    }

    /// <summary>Bağlantı geri geldi bilgisi (ürün kendi algılarsa çağırabilir; Windows ağ olayları ayrıca dinlenir).</summary>
    public void NotifyNetworkAvailable()
    {
        bool wake;
        lock (_stateLock)
        {
            if (!HasCheckableLicense()) return;
            var now = _clock.Now();
            if (_networkNotificationPending ||
                (_lastNetworkNotificationAt is { } last && now - last < CheckScheduler.ImmediateJitter)) return;
            _lastNetworkNotificationAt = now;
            _networkNotificationPending = true;
            _onlineConfirmed = false;
            wake = _scheduler.OnNetworkRestored(now);
        }
        if (wake) Wake();
    }

    // ---------------- başlatma ve yerel kontrol ----------------

    private void Initialize()
    {
        try
        {
            _deviceKey = _keys.OpenOrCreate();
            var fingerprint = _options.FingerprintProvider!();
            if (!DeviceFingerprint.IsValid(fingerprint))
                throw new InvalidOperationException("FingerprintProvider 64 karakter küçük hex SHA-256 döndürmeli.");
            _device = new DeviceIdentity(fingerprint, _deviceKey.ExportSubjectPublicKeyInfoPem(),
                DeviceProof.PublicKeyHash(_deviceKey), _options.DeviceName ?? Environment.MachineName, _options.AppVersion);
        }
        catch (CryptographicException)
        {
            Publish(new LicenseStatus(LicenseState.Unknown, "device_key_unavailable", LastError: "device_key_unavailable"));
            return;
        }

        lock (_stateLock)
        {
            _state = _store.Load(_deviceKey, _device.KeyHash, _options.ProductCode, out var invalidState) ?? _state;
            _requiresOnline = invalidState || _state.RequiresOnline;
            _clock.Start(_state.Watermark);
            _document = VerifyStored(_state);
            _scheduler.Start(_clock.Now(), _state.LastOnlineAt);
        }
        RunLocalCheck();
    }

    /// <summary>Diskteki belge yeniden imza + kimlik + güncellik eşiğiyle doğrulanır; diskteki özete tek başına güvenilmez.</summary>
    private LicenseDocument? VerifyStored(PersistedState state)
    {
        if (state.Document is null || state.LicenseKey is null) return null;
        var identity = Identity(state.LicenseKey);
        var doc = _verifier.Verify(state.Document, identity, out _);
        // V1 yalnız geçmiş iptal için okunur; korumalı durum ve güncellik eşiği sıfırlanmaz.
        if (doc is null && _verifier.ReadRevocationDocument(state.Document) is { } revoked && identity.Matches(revoked))
            doc = revoked;
        return doc is not null && (state.NewestIssuedAt is not { } newest || doc.IssuedAt >= newest) ? doc : null;
    }

    internal void RunLocalCheck()
    {
        if (_device is null) return;
        LicenseStatus status;
        bool needsOnline, changed;
        lock (_stateLock)
        {
            var now = _clock.Now();

            // Kalıcı en ileri zaman yalnız ileri yazılır (watermark); başarısız kalıcı yazım bir sonraki kontrolde tekrarlanır.
            // Lisanssız boş durum yazılmaz: geçici okunamayan bir kopyanın üzerine boş kayıt yazılıp iptal kaybolmasın.
            if (_state.LicenseKey is not null &&
                (_persistPending || (_requiresOnline && !_state.RequiresOnline) ||
                 _state.Watermark is not { } w || now - w >= TimeSpan.FromMinutes(1)))
                Persist(_state with { Watermark = _state.Watermark is { } old && old > now ? old : now });

            (status, needsOnline) = Evaluate(now);
            if (needsOnline)
            {
                _scheduler.RequestSoon(now);
                status = status with { NextOnlineCheckAt = _scheduler.NextOnlineAt };
            }
            _nextLocalAt = now + CheckScheduler.LocalInterval;
            changed = Swap(status);
        }
        if (changed) StatusChanged?.Invoke(this, status);
        if (needsOnline) Wake();
    }

    private (LicenseStatus Status, bool NeedsOnline) Evaluate(DateTimeOffset now)
    {
        var key = _state.LicenseKey;
        var doc = _document;
        LicenseStatus Make(LicenseState state, string reason, DateTimeOffset? until = null) => new(
            state, reason, key, doc?.Customer, doc?.Status, doc?.ExpiresAt, doc?.OfflineGraceDays ?? 7, until,
            _state.LastOnlineAt, HasCheckableLicense() ? _scheduler.NextOnlineAt : null, _lastError, _keys.IsHardwareBacked,
            doc?.Package, doc?.Modules ?? [], doc?.LicenseType is { Length: > 0 } type ? type : null);

        if (key is null) return (Make(LicenseState.Unknown, "not_activated"), false);

        // İmzalı iptal kalıcıdır: eski aktif belge, yeniden başlatma veya bağlantı kesilmesi açamaz.
        if (_state.Revoked.Contains(key)) return (Make(LicenseState.Revoked, "revoked"), false);

        if (_state.Denial is { } denial && denial.LicenseKey == key && (doc is null || doc.IssuedAt <= denial.At))
            return (Make(DenialState(denial.Code), denial.Code), false);

        if (_persistPending) return (Make(LicenseState.Unknown, "state_write_failed"), false);
        if (_requiresOnline) return (Make(LicenseState.Unknown, "online_verification_required"), true);
        if (doc is null) return (Make(LicenseState.Unknown, "no_valid_document"), true);
        if (_clock.Suspicious) return (Make(LicenseState.OfflineExpired, "clock_rollback"), true);

        var eval = OfflineLicenseEvaluator.Evaluate(doc, Identity(key), now, null, _onlineConfirmed);
        if (!eval.AllowsCommercialOperations)
            return (Make(eval.State, eval.Reason), eval.State is LicenseState.OfflineExpired or LicenseState.Unknown);

        var until = doc.AllowedUntil;
        return (Make(eval.State, eval.Reason, until), false);
    }

    internal static LicenseState DenialState(string code) => code switch
    {
        "license_revoked" => LicenseState.Revoked,
        "license_expired" or "demo_expired" => LicenseState.Expired,
        "device_limit_exceeded" => LicenseState.DeviceLimitExceeded,
        _ => LicenseState.Unknown,
    };

    // ---------------- online işlemler ----------------

    private async Task<LicenseStatus> CheckCoreAsync(CancellationToken ct)
    {
        await (_started ?? Task.CompletedTask).ConfigureAwait(false);
        await _network.WaitAsync(ct).ConfigureAwait(false);
        try
        {
            string key;
            lock (_stateLock)
            {
                if (_device is null || !HasCheckableLicense()) return _status;
                key = _state.LicenseKey!;
            }
            var result = await _api.CallAsync("check", key, _device, _deviceKey!, null, ct).ConfigureAwait(false);
            Apply(result, "check", key);
            return _status;
        }
        finally
        {
            _network.Release();
        }
    }

    private async Task<LicenseOperationResult> RunOperationAsync(string operation, string key, JsonObject? extra, CancellationToken ct)
    {
        await StartAsync(ct).ConfigureAwait(false);
        if (_device is null)
            return new LicenseOperationResult(LicenseCallOutcome.Rejected, "device_key_unavailable", "Cihaz anahtarı açılamadı.", _status);

        await _network.WaitAsync(ct).ConfigureAwait(false);
        try
        {
            var result = await _api.CallAsync(operation, key, _device, _deviceKey!, extra, ct).ConfigureAwait(false);
            var outcome = Apply(result, operation, key);
            return new LicenseOperationResult(outcome.Outcome, outcome.ErrorCode, outcome.Message, _status);
        }
        finally
        {
            _network.Release();
        }
    }

    /// <summary>Sunucu sonucunu duruma uygular. Kesin red ile bağlantı hatası farklı ele alınır.</summary>
    private ApiResult Apply(ApiResult result, string operation, string requestedKey)
    {
        if (result.Outcome == LicenseCallOutcome.Success)
            result = AcceptDocument(result, operation == "demo" ? result.LicenseKey ?? "" : requestedKey);

        lock (_stateLock)
        {
            var now = _clock.Now();
            _networkNotificationPending = false;
            switch (result.Outcome)
            {
                case LicenseCallOutcome.Success:
                    _lastError = _persistPending ? "state_write_failed" : null;
                    _scheduler.OnSuccess(now);
                    break;

                case LicenseCallOutcome.Denied when operation == "check":
                    // Kesin karar: mevcut belgeye fallback yok; daha yeni imzalı belge gelene kadar kapalı.
                    _onlineConfirmed = false;
                    _lastError = result.ErrorCode;
                    var revoked = result.ErrorCode == "license_revoked" ? _state.Revoked.Append(requestedKey).Distinct().ToList() : _state.Revoked;
                    Persist(_state with { Denial = new DenialRecord(requestedKey, result.ErrorCode!, now), Revoked = revoked });
                    _scheduler.OnDenied(now);
                    break;

                case LicenseCallOutcome.Denied:
                    // Yeni anahtar denemesi (activate/demo) mevcut lisansı bozmaz; yalnız aynı anahtarın iptali kalıcıdır.
                    if (result.ErrorCode == "license_revoked" && requestedKey == _state.LicenseKey)
                        Persist(_state with { Revoked = _state.Revoked.Append(requestedKey).Distinct().ToList() });
                    break;

                default:
                    if (operation == "check")
                    {
                        _onlineConfirmed = false;
                        _lastError = result.ErrorCode;
                        _scheduler.OnFailure(now);
                    }
                    break;
            }
        }
        RunLocalCheck();
        return result;
    }

    /// <summary>İmza, kimlik ve güncellik eşiği geçerse belgeyi kalıcı olarak kabul eder.</summary>
    private ApiResult AcceptDocument(ApiResult result, string licenseKey)
    {
        var doc = licenseKey.Length == 0 ? null : _verifier.Verify(result.DocumentJson!, Identity(licenseKey), out var error);
        if (doc is null)
        {
            _requiresOnline = true;
            return result with { Outcome = LicenseCallOutcome.Unavailable, ErrorCode = "invalid_document" };
        }

        lock (_stateLock)
        {
            if (_state.NewestIssuedAt is { } newest && doc.IssuedAt < newest)
                return result with { Outcome = LicenseCallOutcome.Unavailable, ErrorCode = "stale_document" };

            var revoked = doc.Status == "revoked" ? _state.Revoked.Append(doc.LicenseKey).Distinct().ToList() : _state.Revoked;
            var denial = _state.Denial is { } d && d.LicenseKey == doc.LicenseKey && d.At >= doc.IssuedAt ? d : null;

            // Kontrollü saat iyileşmesi: imzalı sunucu zamanı yeni çapa ve watermark olur.
            _clock.AnchorToServer(doc.IssuedAt);
            _requiresOnline = false;
            _document = doc;
            _onlineConfirmed = true;
            Persist(_state with
            {
                LicenseKey = doc.LicenseKey,
                Document = result.DocumentJson,
                NewestIssuedAt = doc.IssuedAt,
                Watermark = doc.IssuedAt,
                LastOnlineAt = doc.IssuedAt,
                Revoked = revoked,
                Denial = denial,
            });
        }
        return result;
    }

    /// <summary>Bellek durumu her zaman güncellenir; kalıcı yazım başarısızsa sonraki yerel kontrolde tekrar denenir.</summary>
    private void Persist(PersistedState next)
    {
        _state = next with { SavedAt = _clock.Now(), RequiresOnline = _requiresOnline };
        try
        {
            _persistPending = !_store.Save(_state, _deviceKey!, _device!.KeyHash);
        }
        catch (CryptographicException)
        {
            _persistPending = true;
        }
        if (_persistPending) _lastError = "state_write_failed";
    }

    // ---------------- arka plan döngüsü ----------------

    private async Task LoopAsync(CancellationToken ct)
    {
        while (!ct.IsCancellationRequested)
        {
            try
            {
                if (_device is null) return; // anahtar yok: yapılacak kontrol yok

                if (_clock.Now() >= _nextLocalAt) RunLocalCheck();

                bool onlineDue;
                lock (_stateLock) onlineDue = HasCheckableLicense() && _clock.Now() >= _scheduler.NextOnlineAt;
                if (onlineDue) await CheckNowAsync(ct).ConfigureAwait(false);

                // Offline izin/lisans bitişinde saatlik aralık beklenmeden yerel karar güncellenir.
                if (_status.CommercialAllowedUntil is { } until && _clock.Now() >= until) RunLocalCheck();

                await _wake.WaitAsync(NextDelay(), ct).ConfigureAwait(false);
            }
            catch (OperationCanceledException) when (ct.IsCancellationRequested)
            {
                return;
            }
            catch (Exception e) when (e is not OutOfMemoryException)
            {
                lock (_stateLock) _lastError = "background_error";
                try { await Task.Delay(TimeSpan.FromMinutes(1), ct).ConfigureAwait(false); }
                catch (OperationCanceledException) { return; }
            }
        }
    }

    private TimeSpan NextDelay()
    {
        var now = _clock.Now();
        var due = _nextLocalAt;
        lock (_stateLock)
            if (HasCheckableLicense() && _scheduler.NextOnlineAt < due) due = _scheduler.NextOnlineAt;
        if (_status.CommercialAllowedUntil is { } until && until > now && until < due) due = until;
        var delay = due - now;
        return delay < TimeSpan.FromSeconds(1) ? TimeSpan.FromSeconds(1)
            : delay > CheckScheduler.LocalInterval ? CheckScheduler.LocalInterval : delay;
    }

    private void Wake()
    {
        try { _wake.Release(); }
        catch (SemaphoreFullException) { /* zaten uyandırılmış */ }
    }

    private void SubscribeNetwork()
    {
        try
        {
            NetworkChange.NetworkAvailabilityChanged += OnNetworkAvailability;
            NetworkChange.NetworkAddressChanged += OnNetworkAddress;
        }
        catch (Exception e) when (e is PlatformNotSupportedException or NetworkInformationException)
        {
            // Ağ olayı yoksa periyodik/tekrar deneme takvimi yeterlidir.
        }
    }

    private void OnNetworkAvailability(object? sender, NetworkAvailabilityEventArgs e)
    {
        if (e.IsAvailable) NotifyNetworkAvailable();
        else
        {
            lock (_stateLock)
            {
                _onlineConfirmed = false;
                _lastNetworkNotificationAt = null;
            }
        }
    }

    private void OnNetworkAddress(object? sender, EventArgs e) => NotifyNetworkAvailable();

    // ---------------- yardımcılar ----------------

    private bool HasCheckableLicense() => _state.LicenseKey is { } key && !_state.Revoked.Contains(key);

    private LicenseIdentity Identity(string licenseKey) =>
        new(_options.ProductCode, licenseKey, _device!.Fingerprint, _device.KeyHash);

    private bool Swap(LicenseStatus status)
    {
        status = status with { CommercialDecision = _commercialDecision };
        var previous = _status;
        _status = status;
        return previous != status;
    }

    private void Publish(LicenseStatus status)
    {
        bool changed;
        lock (_stateLock) changed = Swap(status);
        if (changed) StatusChanged?.Invoke(this, status);
    }

    private static string? Blank(string? value) => string.IsNullOrWhiteSpace(value) ? null : value.Trim();

    public async ValueTask DisposeAsync()
    {
        if (_background)
        {
            try
            {
                NetworkChange.NetworkAvailabilityChanged -= OnNetworkAvailability;
                NetworkChange.NetworkAddressChanged -= OnNetworkAddress;
            }
            catch (PlatformNotSupportedException) { }
        }
        _disposed = true;
        _cts.Cancel();
        if (_started is { } started) await started.ConfigureAwait(false);
        if (_loop is not null)
        {
            try { await _loop.ConfigureAwait(false); }
            catch (OperationCanceledException) { }
        }
        await _network.WaitAsync().ConfigureAwait(false);
        try
        {
            await Task.Run(() =>
            {
                lock (_stateLock)
                {
                    if (_device is not null && _state.LicenseKey is not null)
                    {
                        var now = _clock.Now();
                        Persist(_state with { Watermark = _state.Watermark is { } old && old > now ? old : now });
                    }
                }
            }).ConfigureAwait(false);
        }
        finally { _network.Release(); }
        _cts.Dispose();
        _api.Dispose();
        _verifier.Dispose();
        _deviceKey?.Dispose();
    }
}
