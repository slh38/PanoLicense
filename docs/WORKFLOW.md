# Çalışma Akışı

## 1. Görev akışı
1. AGENTS.md okuma tablosunu uygula, STATUS.md'den fazı doğrula.
2. Kapsamı tek göreve sınırla; belirsiz iş kuralında kullanıcıya sor.
3. Kod + hedefli test + ilgili MD güncellemesi aynı değişiklikte.
4. Kısa rapor: ne yapıldı, nasıl doğrulandı, ne eksik.

## 2. Onay gerektiren işlemler ve Git
Kullanıcı onayı olmadan yapılmaz:
- commit, push, branch silme, rebase/force-push, yıkıcı Git işlemi
- migration oluşturma/uygulama, canlı veya paylaşılan DB değişikliği
- sunucu, IIS, domain, DNS, Cloudflare ayarı; canlı yayın
- gerekçesiz yeni bağımlılık, kapsam genişletme

Her commit/push öncesi: STATUS.md ve değişen alanın MD'si güncel; build ve ilgili testler yeşil.
Commit mesajı Türkçe, `tür: özet` biçiminde (`feat`, `fix`, `docs`, `chore`, `test`).

## 3. Claude geliştirme / Codex denetim
- **Claude** görevi uygular, doğrular ve raporlar.
- **Codex** değişikliği bağımsız denetler: kurallara uyum (LICENSING_RULES), güvenlik, gereksiz
  karmaşıklık, test yeterliliği. Bulguları önem sırasıyla raporlar; kodu kendisi yeniden yazmaz.
- Önemli bulgular Claude'a yeni görev olarak döner; son onay kullanıcınındır.
