<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CORS için sabit yedek origin listesi
    |--------------------------------------------------------------------------
    |
    | Bazı Railway / Docker ortamlarında FRONTEND_URL veya CORS_ALLOWED_ORIGINS
    | PHP sürecinde görünmeyebilir (getenv boş). Bu liste config dosyasından
    | okunur; config:cache ile birlikte dağıtılabilir.
    |
    | Kendi domainlerinizi buraya ekleyin veya Railway'de CORS_ALLOWED_ORIGINS
    | kullanmaya devam edin — ikisi birleştirilir.
    |
    */

    'fallback_cors_origins' => [
        'https://hakankekec.me',
        'https://www.hakankekec.me',
        'http://localhost:3000',
        'http://127.0.0.1:3000',
    ],

];
