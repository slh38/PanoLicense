using Microsoft.EntityFrameworkCore;

namespace PanoLicense.Api.Data;

/// <summary>
/// Lisans sunucusunun tek veritabanı bağlamı. F0'da varlık/tablo yoktur;
/// şema F1'de onaylı model ve migration ile eklenir (docs/ARCHITECTURE.md).
/// </summary>
public sealed class PanoLicenseDbContext(DbContextOptions<PanoLicenseDbContext> options)
    : DbContext(options)
{
}
