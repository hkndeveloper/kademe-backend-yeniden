# Authenticating requests

To authenticate requests, include an **`Authorization`** header with the value **`"Bearer {YOUR_ACCESS_TOKEN}"`**.

All authenticated endpoints are marked with a `requires authentication` badge in the documentation below.

Token almak icin /api/auth/login endpointini kullanin. Donen access_token degeri sonraki isteklerde Authorization: Bearer {TOKEN} seklinde gonderilir. Token tek basina tum endpointlere erisim vermez; rol, action+scope, KVKK, sifre kurulumu ve proje/birim/kayit erisimi backend tarafinda ayrica kontrol edilir.
