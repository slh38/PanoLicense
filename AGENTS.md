# PanoLicense — Ajan Giriş Dosyası

PanoLicense: Joker Yazılım ürünlerinin (ilk olarak PanoPOS) lisanslarını yöneten **bağımsız**
lisans sunucusu, yönetim paneli ve istemci kütüphanesi.

| Parça | Teknoloji | Klasör |
|---|---|---|
| API | PHP 8.3+ / Laravel 13 / Sanctum, MySQL 8 | `src/PanoLicense.Api` |
| Panel | React 19 + TypeScript + Vite + Tailwind 4 + shadcn/ui | `src/PanoLicense.Panel` |
| Client | .NET 8 kütüphanesi — PanoPOS gibi .NET ürünleri kullanır | `src/PanoLicense.Client` |

Yayın: Linux VPS (Ubuntu 24.04, Nginx + PHP-FPM + MySQL 8), `lisans.jokeryazilim.com.tr`,
API ve panel aynı domain. Aynı sunucuda canlı Joker QR Menü vardır; ona dokunulmaz.
Teknik adlar İngilizce, içerik ve dokümanlar Türkçedir.

## Okuma kuralı
Her görevde: bu dosya + [STATUS.md](STATUS.md) + [docs/WORKFLOW.md](docs/WORKFLOW.md).
Ek olarak yalnız görevin dokunduğu alanları oku:

| Görev dokunuyorsa | Oku |
|---|---|
| API, veritabanı, yetki, Client, offline, güvenlik | [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) |
| Lisans/cihaz/rol/iptal/süre kuralları | [docs/LICENSING_RULES.md](docs/LICENSING_RULES.md) |
| Panel kodu, UI, tema, tasarım | [docs/UI_DESIGN.md](docs/UI_DESIGN.md) |
| VPS yayını, Nginx, domain, sunucu durumu | [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) |
| Test, build, doğrulama | [docs/TESTING.md](docs/TESTING.md) |

Bir kural tek dosyada tutulur; diğer dosyalar link verir. Komutlar: [README.md](README.md).

## Değişmez kurallar
1. **Bağımsızlık:** PanoLicense hiçbir ürünün (PanoPOS dahil) kodunu, veritabanını veya
   reposunu kullanmaz; ürünler yalnız istemcidir. PanoPOS reposuna bu repodan dokunulmaz.
2. **Basitlik ve performans:** tek monolitik Laravel API + ayrı derlenen React panel + .NET Client.
   Redis, Docker, mikroservis, queue worker, ek servis eklenmez (paylaşımlı hostinge de taşınabilir kalmalı).
   Yeni Composer/npm paketi gerekçesiz eklenmez. Lisans kontrolü gereksiz sorgu üretmez.
3. **Stabilite:** lisans kararı sunucu otoritesindedir; istemci sonucu uydurmaz. Lisans, müşteri,
   cihaz ve kullanıcı kayıtları fiziksel silinmez (iptal/pasif). Geçmiş ve denetim izi korunur.
   Kota ve aktivasyon gibi eşzamanlı işlemler transaction + satır kilidiyle yapılır.
4. **Güvenlik:** `.env`, parola, anahtar ve gerçek müşteri verisi repoya girmez. Lisans imzası
   için özel anahtar yalnız sunucuda ve `public` dizini dışında durur; Client yalnız açık anahtarı taşır.
   Yetki kontrolü her uçta sunucuda yapılır (Gate/`can:` middleware); panelde gizlemek yetki değildir.
5. **Onay kapısı:** kullanıcı onayı olmadan commit/push, canlı veritabanı oluşturma/değiştirme,
   canlı hosting/cPanel/DNS ayarı, yayın, yıkıcı Git işlemi, toplu dosya silme, kapsam genişletme yapılmaz.
6. **Dokümantasyon:** her commit/push öncesi ilgili MD'ler (en az STATUS.md) gerçeğe göre
   güncellenir. Doküman ile kod çelişirse raporlanır.
7. **Test risk bazlıdır:** yalnız değişen alanın testleri + build/typecheck. Başarısız test
   veya build ile görev tamamlandı denmez ([TESTING](docs/TESTING.md)).
8. **Kapsam:** STATUS.md'de sonraki faz olarak duran işler açık görev olmadan yapılmaz.

## Ajan rolleri
- **Claude:** geliştirme (kod + test + MD güncellemesi).
- **Codex:** bağımsız denetim — Claude'un değişikliğini kod okumasıyla doğrular, bulgu raporlar;
  aynı görevde kodu kendisi yeniden yazmaz. Ayrıntı: [WORKFLOW §3](docs/WORKFLOW.md#3-claude-geliştirme--codex-denetim).
