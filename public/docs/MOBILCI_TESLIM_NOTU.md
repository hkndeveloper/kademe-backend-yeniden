# KADEME Mobil API Teslim Notu

## Domainler

- Web/frontend domain: `https://hakankekec.me`
- Backend/API domain: `https://kademe-backend-yeniden-production.up.railway.app`
- Mobil API base URL: `https://kademe-backend-yeniden-production.up.railway.app/api`
- Dosya/storage public URL kullanimi gereken yerlerde: `https://kademe-backend-yeniden-production.up.railway.app`

## Dokumantasyon Linkleri

- Swagger/Scribe HTML: `https://kademe-backend-yeniden-production.up.railway.app/docs`
- OpenAPI YAML: `https://kademe-backend-yeniden-production.up.railway.app/docs.openapi`
- Postman Collection: `https://kademe-backend-yeniden-production.up.railway.app/docs.postman`

Bu linkler production ortamda kapaliysa veya erisilemiyorsa ekteki dosyalari kullanin.

## Ekteki Dosyalar

- `openapi.yaml`: Swagger/OpenAPI 3.0.3 API sozlesmesi.
- `collection.json`: Postman koleksiyonu.
- `README.md`: Paket aciklamasi ve yenileme notlari.
- `manifest.json`: Dosya boyutu ve SHA256 dogrulama bilgileri.

## Auth

Login endpointi:

```http
POST https://kademe-backend-yeniden-production.up.railway.app/api/auth/login
```

Korumali endpointlerde header:

```http
Accept: application/json
Content-Type: application/json
Authorization: Bearer {TOKEN}
```

Token almak tum endpointlere erisim anlamina gelmez. Backend tarafinda rol, action+scope yetkisi, proje/birim/kendi kaydi erisimi, KVKK onayi, sifre kurulumu, blacklist ve arsiv kilidi kontrolleri devam eder.

## Mobil Uygulama Icin Oncelikli Endpoint Gruplari

- Auth: `/api/auth/login`, `/api/auth/logout`, `/api/auth/me`, sifre sifirlama endpointleri.
- Kullanici: `/api/user/profile`, `/api/user/consent-kvkk`, `/api/user/notifications`.
- Projeler ve basvurular: `/api/projects`, `/api/applications`, `/api/applications/public`.
- Dashboard: `/api/dashboard/summary`, `/api/dashboard/projects`, `/api/dashboard/digital-cv`.
- Programlar ve yoklama: `/api/programs`, `/api/attendances/qr`.
- Duyuru ve mesajlar: `/api/announcements`, `/api/inbox/messages`.
- Forum: `/api/forum/posts`.
- Belgeler: `/api/digital-bohca`, `/api/certificates`.
- Geri bildirim ve destek: `/api/feedbacks`, `/api/requests`, `/api/tickets`.
- KPD: `/api/kpd/appointments`.
- Gonulluluk: `/api/volunteer/opportunities`.
- Mezun firsatlari: `/api/alumni-opportunities`.

Admin/panel endpointleri Swagger icinde vardir, ancak mobil uygulamada yonetici paneli olmayacaksa kullanilmasi gerekmez.

## Ornek

```http
GET https://kademe-backend-yeniden-production.up.railway.app/api/projects
Accept: application/json
```

```http
GET https://kademe-backend-yeniden-production.up.railway.app/api/user/profile
Accept: application/json
Authorization: Bearer {TOKEN}
```
