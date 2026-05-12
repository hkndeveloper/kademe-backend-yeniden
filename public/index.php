<?php

use App\Http\Middleware\RefreshCorsConfigFromEnv;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// OPTIONS preflight: Laravel'dan once (middleware zincirine takilmadan) CORS basliklari.
require __DIR__.'/../bootstrap/cors-preflight.php';
kademe_maybe_exit_options_preflight();

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$request = Request::capture();

/** @var Kernel $kernel */
$kernel = $app->make(Kernel::class);

$response = $kernel->handle($request);

// ──────────────────────────────────────────────────────────────────────
// CORS HEADER ENJEKSIYONU
// php artisan serve + Railway ortaminda Symfony HeaderBag bazen header
// dusurur. Response::send() öncesi PHP native header() ile ekle.
// ──────────────────────────────────────────────────────────────────────
(function () use ($request, $response) {
    $origin = $request->headers->get('Origin');
    if (! is_string($origin) || trim($origin) === '') {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    }
    $origin = is_string($origin) ? rtrim(trim($origin), '/') : '';
    if ($origin === '') {
        return;
    }

    $allowed = kademe_collect_cors_origins_early();
    if (! kademe_cors_origin_allowed($origin, $allowed)) {
        return;
    }

    // Response HeaderBag'e ekle (send() bunu kullanacak).
    $response->headers->set('Access-Control-Allow-Origin', $origin);
    $response->headers->set('Access-Control-Allow-Credentials', 'true');
    $vary = (string) ($response->headers->get('Vary') ?? '');
    if ($vary === '') {
        $response->headers->set('Vary', 'Origin');
    } elseif (! preg_match('/\bOrigin\b/i', $vary)) {
        $response->headers->set('Vary', $vary . ', Origin');
    }

    // PHP native header() ile de gonder — php artisan serve HeaderBag'i
    // dusurse bile bu satir tarayiciya ulasir.
    if (! headers_sent()) {
        header('Access-Control-Allow-Origin: ' . $origin, true);
        header('Access-Control-Allow-Credentials: true', true);
    }
})();

// Debug marker
$response->headers->set('X-Kademe-Cors-Pipeline', 'post-handle-v2');

// JSON govde ama Content-Type text/html ise (php -S / eski cevap) duzelt.
$ct = (string) ($response->headers->get('Content-Type') ?? '');
$content = $response->getContent();
if (
    is_string($content)
    && str_starts_with(ltrim($content), '{')
    && ($ct === '' || str_contains(strtolower($ct), 'text/html'))
) {
    $response->headers->set('Content-Type', 'application/json; charset=UTF-8');
}

$response->send();

$kernel->terminate($request, $response);
