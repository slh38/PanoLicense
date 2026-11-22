# PanoLicense — Ajan Giriş Dosyası

PanoLicense: Joker Yazılım ürünlerinin (ilk olarak PanoPOS) lisanslarını yöneten **bağımsız**
lisans sunucusu, yönetim paneli ve istemci kütüphanesi. Backend ASP.NET Core 10 + EF Core +
SQL Server; Panel React + TypeScript + Vite + Tailwind + shadcn/ui; Client .NET kütüphanesi
(net8.0 + net10.0). Teknik adlar İngilizce, içerik ve dokümanlar Türkçedir.

## Okuma kuralı
Her görevde: bu dosya + [STATUS.md](STATUS.md) + [docs/WORKFLOW.md](docs/WORKFLOW.md).
Ek olarak yalnız görevin dokunduğu alanları oku:

| Görev dokunuyorsa | Oku |
|---|---|
| API, DB, EF Core, Client, offline, güvenlik | [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) |
| Lisans/cihaz/rol/iptal/süre kuralları | [docs/LICENSING_RULES.md](docs/LICENSING_RULES.md) |
| Panel kodu, UI, tema, tasarım | [docs/UI_DESIGN.md](docs/UI_DESIGN.md) |
| Yayın, IIS, Cloudflare, domain | [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) |
| Test, build, doğrulama | [docs/TESTING.md](docs/TESTING.md) |

Bir kural tek dosyada tutulur; diğer dosyalar link verir. Komutlar: [README.md](README.md).

## Değişmez kurallar
1. **Bağımsızlık:** PanoLicense hiçbir ürünün (PanoPOS dahil) kodunu, veritabanını veya
   reposunu kullanmaz; ürünler yalnız istemcidir. PanoPOS reposuna bu repodan dokunulmaz.
2. **Basitlik ve performans:** tek API + tek Panel + tek Client. Mikroservis, Redis, Docker,
   kuyruk, ek servis, gereksiz abstraction veya paket eklenmez. Her yeni bağımlılık gerekçelidir.
3. **Stabilite:** lisans kararı sunucu otoritesindedir; istemci sonucu uydurmaz. Lisans ve
   müşteri kayıtları fiziksel silinmez (iptal/pasif). Geçmiş değiştirilmez, denetim izi korunur.
4. **Güvenlik:** gizli anahtar, bağlantı dizesi, gerçek müşteri verisi repoya girmez.
   Özel imza anahtarı yalnız sunucuda durur; Client yalnız açık anahtarı taşır.
5. **Onay kapısı:** kullanıcı onayı olmadan commit/push, migration veya canlı DB değişikliği,
   sunucu/domain/DNS/Cloudflare ayarı, canlı yayın, yıkıcı Git işlemi, kapsam genişletme yapılmaz.
6. **Dokümantasyon:** her commit/push öncesi ilgili MD'ler (en az STATUS.md) gerçeğe göre
   güncellenir. Doküman ile kod çelişirse raporlanır.
7. **Test risk bazlıdır:** yalnız değişen alanın testleri + build/typecheck. Başarısız test
   veya build ile görev tamamlandı denmez ([TESTING](docs/TESTING.md)).
8. **Kapsam:** STATUS.md'de sonraki faz olarak duran işler açık görev olmadan yapılmaz.

## Ajan rolleri
- **Claude:** geliştirme (kod + test + MD güncellemesi).
- **Codex:** bağımsız denetim — Claude'un değişikliğini kod okumasıyla doğrular, bulgu raporlar;
  aynı görevde kodu kendisi yeniden yazmaz. Ayrıntı: [WORKFLOW §3](docs/WORKFLOW.md#3-claude-geliştirme--codex-denetim).
