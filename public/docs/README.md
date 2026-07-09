# KADEME OpenAPI / Swagger Teslim Paketi

Bu klasor mobil ekip veya isveren entegrasyonu icin hazirlanan kapali teslim paketidir. Public web klasorune konmadi; admin/panel endpointleri hassas oldugu icin dosya olarak veya guvenli staging/production linkiyle paylasilmasi onerilir.

## Dosyalar

- `openapi.yaml`: Swagger/OpenAPI 3.0.3 spec dosyasi.
- `collection.json`: Postman collection dosyasi.
- `MOBILCI_TESLIM_NOTU.md`: Mobil ekibe direkt gonderilecek ozet not.
- `manifest.json`: Dosya boyutu ve SHA256 hash bilgileri.

## Production Bilgileri

- Web/frontend domain: `https://hakankekec.me`
- Backend/API domain: `https://kademe-backend-yeniden-production.up.railway.app`
- Mobil API base URL: `https://kademe-backend-yeniden-production.up.railway.app/api`
- Storage/public dosya kok URL: `https://kademe-backend-yeniden-production.up.railway.app`

## Mobil Ekibe Verilecek Linkler

- Swagger/Scribe HTML: `https://kademe-backend-yeniden-production.up.railway.app/docs`
- OpenAPI YAML: `https://kademe-backend-yeniden-production.up.railway.app/docs.openapi`
- Postman Collection: `https://kademe-backend-yeniden-production.up.railway.app/docs.postman`

Bu linkler production ortamda kapaliysa veya erisilemiyorsa bu klasordeki `openapi.yaml` ve `collection.json` dosyalari verilmelidir.

## Auth Notu

Korumali endpointlerde su headerlar kullanilir:

```http
Accept: application/json
Content-Type: application/json
Authorization: Bearer {TOKEN}
```

Token almak icin `/api/auth/login` kullanilir. Token almak tum endpointlere erisim anlamina gelmez. Backend tarafinda rol, action+scope, proje/birim/self erisimi, KVKK, sifre kurulumu, blacklist ve arsiv kilidi kurallari calismaya devam eder.

## Base URL Notu

Bu paket production backend adresine gore duzenlendi:

- OpenAPI `servers.url`: `https://kademe-backend-yeniden-production.up.railway.app`
- Postman `baseUrl`: `https://kademe-backend-yeniden-production.up.railway.app`

Mobilci API isteklerinde endpointleri `/api/...` olarak kullanmalidir. Ornek: `POST https://kademe-backend-yeniden-production.up.railway.app/api/auth/login`.

## Yenileme Komutu

Backend klasorunde:

```powershell
php artisan scribe:generate --force --no-upgrade-check --scribe-dir=.scribe-refresh
.\scripts\export-openapi-delivery.ps1
```

Export sonrasi `openapi.yaml` ve `collection.json` icindeki base URL production adrese gore kontrol edilmelidir.

## Guvenlik Onerisi

Dinamik `/docs` sayfasi production internete acilacaksa basic auth, IP kisiti, VPN veya sadece staging erisimi dusunulmelidir. Mobilciye Railway variables, APP_KEY, DB bilgileri, R2 secret, Resend key veya Google client secret verilmemelidir.
