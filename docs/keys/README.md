# PanoLicense imza açık anahtarları

Yalnız **açık** anahtarlar burada tutulur; özel anahtar yalnız sunucuda (`/etc/panolicense/keys/signing-k1.pem`, 600)
ve kullanıcının şifreli sunucu dışı yedeğindedir. Özel anahtar bu repoya, sohbete veya loglara yazılmaz.

| kid | Dosya | Algoritma | SPKI SHA-256 | Oluşturma |
|---|---|---|---|---|
| `k1` | [panolicense-k1.pub.pem](panolicense-k1.pub.pem) | ECDSA P-256 (ES256) | `4bb026c7bdf8b7184c284ae45859857b22ab3261d7a75023a973cb76f97d04b8` | 2026-11-27, canlı |

Kullanım: PanoPOS Device Service ve WebApi `SigningPublicKeys = { ["k1"] = <PEM> }` olarak gömer
(LICENSING_CONTRACT §6). Gömmeden önce SPKI SHA-256 parmak izi yukarıdaki değerle karşılaştırılır:
`openssl pkey -pubin -in panolicense-k1.pub.pem -outform DER | openssl dgst -sha256`.
Anahtar değişirse yeni `kid` (ör. `k2`) eklenir; eski kid istemcilerden geçiş bitene kadar kaldırılmaz.
