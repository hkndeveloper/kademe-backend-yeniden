<?php

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
