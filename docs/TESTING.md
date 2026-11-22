# Test ve Doğrulama

Test risk bazlıdır: değişen alanın testleri + build/typecheck. Tam regresyon rutin değildir;
yalnız lisans karar mantığı, imza/offline doğrulama veya şema değişikliğinde genişletilir.

| Değişiklik | Asgari doğrulama |
|---|---|
| API / Client C# | `dotnet build PanoLicense.sln` + ilgili xUnit testleri (`dotnet test --filter`) |
| Lisans kararı, kota, offline, imza | İlgili testler **zorunlu** + kenar durumlar (süre sınırı, saat geri alma, kota yarışı) |
| Panel | `npm run build` (tsc dahil) + ilgili Vitest + tarayıcıda light/dark görsel kontrol |
| Migration | Script üretimi + boş dev DB'ye uygulama; canlıya yalnız onayla |

- Test verisi uydurmadır; gerçek müşteri verisi kullanılmaz.
- Başarısız test/build ile iş tamamlandı denmez; atlanan doğrulama raporda açıkça yazılır.
