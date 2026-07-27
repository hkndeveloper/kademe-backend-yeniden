# Introduction

KADEME backend API dokumantasyonu. Public, mobil katilimci ve tek panel endpointleri OpenAPI/Swagger uyumlu olarak bu dokumanda toplanir.

<aside>
    <strong>Base URL</strong>: <code>http://localhost:8000</code>
</aside>

KADEME API dokumantasyonu mobil uygulama, public web arayuzu ve tek panel entegrasyonlari icin hazirlanir.

Endpointler uc ana guvenlik seviyesinde ele alinir:

- Public endpointler token gerektirmez.
- Authenticated endpointler `Authorization: Bearer {TOKEN}` header'i gerektirir.
- Panel ve proje bazli endpointlerde tokena ek olarak rol, action+scope yetkisi, proje/birim/kendi kaydi gibi backend kontrolleri uygulanir.

Genel header kullanimi:

```http
Accept: application/json
Content-Type: application/json
Authorization: Bearer {TOKEN}
```

Dosya yukleme endpointleri `multipart/form-data`, indirme/export endpointleri ise binary response veya gecici download URL'i donebilir.

Ortak response ve hata modelleri OpenAPI `components.schemas` bolumunde toplanir. Sik kullanilanlar:

- `SuccessMessage`: Standart basarili islem mesaji.
- `PaginatedResponse`: Laravel pagination icin `data`, `links`, `meta` yapisi.
- `ValidationError`: 422 validasyon hatalari; `message` ve alan bazli `errors`.
- `UnauthorizedError`: 401 token yok, suresi dolmus veya gecersiz.
- `ForbiddenError`: 403 rol, action+scope, proje/birim/self erisimi, KVKK veya sifre kurulumu engeli.
- `NotFoundError`: 404 kayit veya dosya bulunamadi.
- `KvkkRequiredError`, `PasswordSetupPendingError`, `BlacklistError`, `ArchiveLockedError`: KADEME'ye ozel erisim/hata durumlari.

Sik kullanilan `User`, `Project`, `Application`, `Program`, `Attendance`, `Certificate`, `SupportTicket`, `ServiceRequest`, `Announcement`, `Trainer`, `FinancialTransaction` ve `ActivityLog` semalari da OpenAPI components altinda bulunur. Endpoint response ornekleri yine ilgili endpointte kalir; components bolumu mobilci icin ortak sozlesme referansidir.

