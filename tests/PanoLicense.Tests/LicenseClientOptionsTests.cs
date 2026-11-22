using PanoLicense.Client;

namespace PanoLicense.Tests;

public class LicenseClientOptionsTests
{
    [Theory]
    [InlineData("https://lisans.example.com")]
    [InlineData("http://localhost:5080")]
    public void Validate_accepts_https_or_local_http(string url)
    {
        var options = new LicenseClientOptions { ServerUrl = new Uri(url), ProductCode = "PANOPOS" };
        options.Validate();
    }

    [Fact]
    public void Validate_rejects_remote_http()
    {
        var options = new LicenseClientOptions { ServerUrl = new Uri("http://lisans.example.com"), ProductCode = "PANOPOS" };
        Assert.Throws<InvalidOperationException>(options.Validate);
    }

    [Fact]
    public void Validate_rejects_missing_product_code()
    {
        var options = new LicenseClientOptions { ServerUrl = new Uri("https://lisans.example.com") };
        Assert.Throws<InvalidOperationException>(options.Validate);
    }

    [Theory]
    [InlineData(LicenseState.Active, true)]
    [InlineData(LicenseState.OfflineGrace, true)]
    [InlineData(LicenseState.Expired, false)]
    [InlineData(LicenseState.Revoked, false)]
    [InlineData(LicenseState.DeviceLimitExceeded, false)]
    [InlineData(LicenseState.Unknown, false)]
    public void Commercial_operations_stop_unless_license_is_usable(LicenseState state, bool expected)
    {
        Assert.Equal(expected, state.AllowsCommercialOperations());
    }
}
