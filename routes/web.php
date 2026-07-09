<?php

use App\Http\Controllers\GoogleCalendarController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/google/calendar/callback', [GoogleCalendarController::class, 'callback']);

Route::get('/docs', function () {
    return response()->make(<<<'HTML'
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>KADEME API Docs</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; line-height: 1.6; color: #172033; }
        code { background: #f3f4f6; padding: 2px 6px; border-radius: 4px; }
        a { color: #c2410c; }
    </style>
</head>
<body>
    <h1>KADEME API Docs</h1>
    <p>Mobil API base URL: <code>https://kademe-backend-yeniden-production.up.railway.app/api</code></p>
    <ul>
        <li><a href="/docs.openapi">OpenAPI YAML</a></li>
        <li><a href="/docs.postman">Postman Collection</a></li>
        <li><a href="/docs/MOBILCI_TESLIM_NOTU.md">Mobilci Teslim Notu</a></li>
    </ul>
</body>
</html>
HTML, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
});

Route::get('/docs.openapi', function () {
    return response()->file(public_path('docs/openapi.yaml'), [
        'Content-Type' => 'application/yaml; charset=UTF-8',
    ]);
});

Route::get('/docs.postman', function () {
    return response()->file(public_path('docs/collection.json'), [
        'Content-Type' => 'application/json; charset=UTF-8',
    ]);
});
