using System.Security.Cryptography;
using System.Text;
using PanoLicense.Client;

namespace PanoLicense.Tests;

/// <summary>1.0.1: ürün sunucusu (PanoPOS WebApi) challenge'ına bağlı ticari yetki kanıtı ve doğrulaması.</summary>
public class CommercialProofTests
{
    private const string Key = "PAN-PRUF-0000-0001";
    private const string Challenge = "webapi-challenge-0123456789abcdef";

    private static async Task<(TestDevice D, LicenseManager M)> Activated(string status = "active", params string[] modules)
    {
        var d = new TestDevice();
        d.Server.Licenses[Key] = new FakeLicenseServer.License { Status = status == "revoked" ? "active" : status, Modules = modules.ToList() };
        var m = await d.StartAsync();
        Assert.True((await m.ActivateAsync(Key)).Succeeded);
        return (d, m);
    }

    private static CommercialProofVerifier Verifier(TestDevice d) =>
        new(new Dictionary<string, string> { [FakeLicenseServer.Kid] = d.Server.PublicKeyPem }, FakeLicenseServer.Product);

    private static CommercialProofCheck Check(TestDevice d, CommercialProof p, string? challenge = null, string? envelope = null,
        string? publicKey = null, string? signature = null, DateTimeOffset? now = null)
    {
        using var v = Verifier(d);
        return v.Verify(challenge ?? p.Challenge, envelope ?? p.DocumentEnvelope, publicKey ?? p.DevicePublicKeyPem,
            signature ?? p.Signature, now ?? d.Time.GetUtcNow());
    }

    [Fact]
    public async Task Active_license_produces_proof_that_the_product_server_verifies()
    {
        var (d, m) = await Activated("active", "variant", "quick_sale");
        await using var _ = m;

        var result = m.CreateCommercialProof(Challenge);

        Assert.True(result.Succeeded, result.Reason);
        var p = result.Proof!;
        Assert.Equal(Key, p.LicenseKey);
        Assert.Equal(["quick_sale", "variant"], p.Modules);
        Assert.Equal(m.Current.CommercialAllowedUntil, p.AllowedUntil);
        Assert.DoesNotContain("PRIVATE", p.DevicePublicKeyPem);

        var check = Check(d, p);
        Assert.True(check.Valid, check.Error);
        Assert.Equal(Key, check.Document!.LicenseKey);
        Assert.Equal(p.AllowedUntil, check.AllowedUntil);
    }

    [Fact]
    public async Task Proof_is_bound_to_challenge_document_and_device_key()
    {
        var (d, m) = await Activated();
        await using var _ = m;
        var p = m.CreateCommercialProof(Challenge).Proof!;

        Assert.Equal("bad_proof", Check(d, p, challenge: "another-challenge-0000000000").Error);   // başka challenge
        Assert.Equal("bad_proof", Check(d, p, envelope: p.DocumentEnvelope + " ").Error);          // belge değişmiş (opak metin)

        // Başka cihazın açık anahtarı: belgeyle bağ kopar.
        using var other = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        Assert.Equal("identity_mismatch", Check(d, p, publicKey: other.ExportSubjectPublicKeyInfoPem()).Error);

        // Aynı belge + başka anahtarın imzası.
        var forged = Convert.ToBase64String(other.SignData(Encoding.UTF8.GetBytes("x"), HashAlgorithmName.SHA256, DSASignatureFormat.Rfc3279DerSequence));
        Assert.Equal("bad_proof", Check(d, p, signature: forged).Error);

        // Başka sunucunun anahtarıyla imzalı belge.
        var foreign = new FakeLicenseServer(d.Time);
        using var v = new CommercialProofVerifier(new Dictionary<string, string> { [FakeLicenseServer.Kid] = foreign.PublicKeyPem }, FakeLicenseServer.Product);
        Assert.Equal("bad_signature", v.Verify(p.Challenge, p.DocumentEnvelope, p.DevicePublicKeyPem, p.Signature, d.Time.GetUtcNow()).Error);

        // Başka ürün kodu bekleyen doğrulayıcı.
        using var otherProduct = new CommercialProofVerifier(new Dictionary<string, string> { [FakeLicenseServer.Kid] = d.Server.PublicKeyPem }, "MEZBAHA");
        Assert.Equal("identity_mismatch", otherProduct.Verify(p.Challenge, p.DocumentEnvelope, p.DevicePublicKeyPem, p.Signature, d.Time.GetUtcNow()).Error);
    }

    [Fact]
    public async Task Verifier_rejects_expired_offline_window_and_future_document()
    {
        var (d, m) = await Activated();
        await using var _ = m;
        var p = m.CreateCommercialProof(Challenge).Proof!;

        Assert.Equal("offline_grace_exceeded", Check(d, p, now: p.AllowedUntil).Error);
        Assert.Equal("document_from_future", Check(d, p, now: d.Time.GetUtcNow().AddHours(-1)).Error);
        Assert.Equal("invalid_challenge", Check(d, p, challenge: "kisa").Error);
    }

    [Fact]
    public async Task No_proof_without_commercial_right()
    {
        // Lisans yok
        var empty = new TestDevice();
        await using (var m = await empty.StartAsync())
            Assert.Equal("not_activated", m.CreateCommercialProof(Challenge).Reason);

        // Geçersiz challenge biçimi (satır sonu ile mesaj enjeksiyonu)
        var (d, active) = await Activated();
        await using (active)
            Assert.Equal("invalid_challenge", active.CreateCommercialProof("abc\npanolicense-commercial-proof-v1").Reason);

        // Offline izin bitti (saatlik kontrolü beklemeden)
        d.Server.Override = _ => throw new HttpRequestException("ağ yok");
        await using (var m = await d.StartAsync())
        {
            d.Time.Advance(TimeSpan.FromDays(8));
            var r = m.CreateCommercialProof(Challenge);
            Assert.False(r.Succeeded);
            Assert.Null(r.Proof);
        }
    }

    [Fact]
    public async Task Revoked_license_gives_no_proof_but_forwards_signed_revocation()
    {
        var (d, m) = await Activated();
        await using var _ = m;
        Assert.Null(m.GetSignedRevocationDocument());
        var before = m.CreateCommercialProof(Challenge).Proof!;

        d.Server.Licenses[Key].Status = "revoked";
        await m.CheckNowAsync();

        var r = m.CreateCommercialProof(Challenge);
        Assert.False(r.Succeeded);
        Assert.Equal("revoked", r.Reason);

        var envelope = m.GetSignedRevocationDocument();
        Assert.NotNull(envelope);
        using var v = Verifier(d);
        var revocation = v.ReadRevocation(envelope!);
        Assert.Equal(Key, revocation!.LicenseKey);
        // İptal belgesi, aynı cihazın anahtarıyla bile ticari kanıt olarak kabul edilmez.
        Assert.Equal("revoked", v.Verify(Challenge, envelope!, before.DevicePublicKeyPem, before.Signature, d.Time.GetUtcNow()).Error);
        // Aktif belge iptal okuması vermez.
        Assert.Null(v.ReadRevocation(before.DocumentEnvelope));
    }

    [Fact]
    public async Task No_proof_when_state_cannot_be_persisted_or_clock_is_rolled_back()
    {
        var (d, m) = await Activated();
        await using (m)
        {
            d.File.Fail = d.Registry.Fail = true;
            d.Server.Licenses[Key].Modules.Add("bank");
            await m.CheckNowAsync(); // yeni belge kalıcı yazılamaz
            Assert.False(m.CreateCommercialProof(Challenge).Succeeded);
        }

        d.File.Fail = d.Registry.Fail = false;
        var (d2, m2) = await Activated();
        await m2.DisposeAsync();
        d2.Server.Override = _ => throw new HttpRequestException("ağ yok");
        d2.Time.Restart(TestDevice.T0.AddDays(2));
        await (await d2.StartAsync()).DisposeAsync();
        d2.Time.Restart(TestDevice.T0.AddDays(1)); // saat geri alındı
        await using var m3 = await d2.StartAsync();
        Assert.Equal("clock_rollback", m3.CreateCommercialProof(Challenge).Reason);
    }

    [Fact]
    public void Message_format_is_stable_and_distinct_from_server_device_proof()
    {
        var msg = CommercialProofFormat.BuildMessage(Challenge, "PANOPOS", Key, new string('A', 64), "{\"v\":1}");
        var lines = msg.Split('\n');
        Assert.Equal(6, lines.Length);
        Assert.Equal("panolicense-commercial-proof-v1", lines[0]);
        Assert.Equal(new string('a', 64), lines[3]);
        Assert.Equal(CommercialProofFormat.DocumentHash("{\"v\":1}"), lines[4]);
        Assert.NotEqual(LicenseApiClient.ProofPurpose, lines[0]);
        Assert.False(CommercialProofFormat.IsValidChallenge("bosluk iceren challenge degeri"));
    }
}
