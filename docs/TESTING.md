# Test ve Doğrulama

Test risk bazlıdır: değişen alanın testleri + build/typecheck. Tam regresyon rutin değildir;
yalnız lisans karar mantığı, imza/offline doğrulama, yetki veya şema değişikliğinde genişletilir.

| Değişiklik | Asgari doğrulama |
|---|---|
| Laravel kodu | `php artisan test --filter=...` (ilgili test sınıfı) |
| Migration | `php artisan migrate:fresh` yerel dev DB'de + ilgili testler (PHPUnit `panolicense_test` MySQL DB'sini kullanır) |
| Yetki / rol | `AuthorizationTest` + değişen uçların yetkisiz (401/403) testleri **zorunlu** |
| Lisans kararı, kota, offline, imza | İlgili testler **zorunlu** + kenar durumlar (süre sınırı, saat geri alma, eşzamanlı son kota) |
| Panel | `npm run build` (tsc dahil) + ilgili Vitest + tarayıcıda light/dark görsel kontrol |
| Aynı domain yayını | `npm run build:deploy` + `php artisan serve` ile `/`, bir menü yolu ve `/api/v1/health` |
| Client (.NET) | `dotnet build PanoLicense.sln` + ilgili xUnit testleri |

- Testler gerçek MySQL 8'de koşar (SQLite değil) — MySQL'e özgü davranışlar (kilit, üretilmiş sütun) test edilebilsin.
- Test verisi uydurmadır; gerçek müşteri verisi kullanılmaz.
- Başarısız test/build ile iş tamamlandı denmez; atlanan doğrulama raporda açıkça yazılır.
