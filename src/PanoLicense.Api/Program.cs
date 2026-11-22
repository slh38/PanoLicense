using Microsoft.EntityFrameworkCore;
using PanoLicense.Api.Data;

var builder = WebApplication.CreateBuilder(args);

// F0: DbContext yalnız kayıtlıdır; tablo/migration yok. Bağlantı ilk kullanımda açılır,
// bu yüzden SQL Server olmadan da API ayağa kalkar.
builder.Services.AddDbContext<PanoLicenseDbContext>(options =>
    options.UseSqlServer(builder.Configuration.GetConnectionString("PanoLicense")));

var app = builder.Build();

// Panel build çıktısı (wwwroot) API ile aynı domain altında sunulur. Bkz. docs/ARCHITECTURE.md
app.UseDefaultFiles();
app.UseStaticFiles();

var api = app.MapGroup("/api");
api.MapGet("/health", () => Results.Ok(new HealthResponse("ok", ApiInfo.Version)));

// /api dışındaki bilinmeyen yollar React router'a bırakılır.
app.MapFallbackToFile("index.html");

app.Run();

public sealed record HealthResponse(string Status, string Version);

public static class ApiInfo
{
    public const string Version = "0.1.0-f0";
}

public partial class Program;
