<?php

/**
 * Laravel artisan serve entry point.
 *
 * php artisan serve bu dosyayi kullanir (public/index.php yerine).
 * Statik dosya istekleri disinda tum istekler public/index.php'ye yonlendirilir.
 *
 * CORS basliklarini burada native header() ile gonderiyoruz cunku
 * php -S (built-in server) + Symfony Response::send() bazi ortamlarda
 * HeaderBag basliklarini dusurur.
 */

$publicPath = getcwd();

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

// Statik dosya istekleri: dosya varsa PHP'nin kendisi servis etsin.
if ($uri !== '/' && file_exists($publicPath.$uri)) {
    return false;
}

$formattedDateTime = date('D M j H:i:s Y');
$requestMethod = $_SERVER['REQUEST_METHOD'];
$remoteAddress = $_SERVER['REMOTE_ADDR'].':'.$_SERVER['REMOTE_PORT'];

file_put_contents('php://stdout', "[$formattedDateTime] $remoteAddress [$requestMethod] URI: $uri\n");

// ─── CORS: Native header() ile gonder ─────────────────────────────────
// Symfony HeaderBag'in dusurulmesi ihtimaline karsi belt-and-suspenders.
(function () {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (! is_string($origin) || trim($origin) === '') {
        return;
    }
    $origin = rtrim(trim($origin), '/');
    if ($origin === '') {
        return;
    }

    $allowed = [
        'https://hakankekec.me',
        'https://www.hakankekec.me',
        'http://localhost:3000',
        'http://127.0.0.1:3000',
    ];

    // Env'den de oku.
    $envOrigins = $_ENV['CORS_ALLOWED_ORIGINS'] ?? ($_SERVER['CORS_ALLOWED_ORIGINS'] ?? getenv('CORS_ALLOWED_ORIGINS'));
    if (is_string($envOrigins) && $envOrigins !== '') {
        foreach (explode(',', $envOrigins) as $part) {
            $o = trim($part, " \t\n\r\0\x0B\"'");
            if ($o !== '') {
                $allowed[] = $o;
            }
        }
    }

    $frontendUrl = $_ENV['FRONTEND_URL'] ?? ($_SERVER['FRONTEND_URL'] ?? getenv('FRONTEND_URL'));
    if (is_string($frontendUrl) && $frontendUrl !== '') {
        $parsed = parse_url(trim($frontendUrl, " \t\n\r\0\x0B\"'"));
        if (is_array($parsed) && isset($parsed['scheme'], $parsed['host'])) {
            $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
            $allowed[] = $parsed['scheme'] . '://' . $parsed['host'] . $port;
            if (! str_starts_with(strtolower($parsed['host']), 'www.')) {
                $allowed[] = $parsed['scheme'] . '://www.' . $parsed['host'] . $port;
            }
        }
    }

    $allowed = array_unique($allowed);

    $found = false;
    foreach ($allowed as $candidate) {
        if (strcasecmp(rtrim(trim($candidate), '/'), $origin) === 0) {
            $found = true;
            break;
        }
    }

    if (! $found) {
        return;
    }

    // OPTIONS preflight — hemen cevapla.
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        $reqHeaders = $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? '*';
        header('Access-Control-Allow-Origin: ' . $origin, true);
        header('Access-Control-Allow-Credentials: true', true);
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS', true);
        header('Access-Control-Allow-Headers: ' . $reqHeaders, true);
        header('Access-Control-Max-Age: 86400', true);
        header('Vary: Origin, Access-Control-Request-Method, Access-Control-Request-Headers', true);
        http_response_code(204);
        if (function_exists('header_remove')) {
            header_remove('Content-Type');
        }
        exit;
    }

    // Normal istek — CORS basliklarini SIMDI gonder, Symfony'den once.
    header('Access-Control-Allow-Origin: ' . $origin, true);
    header('Access-Control-Allow-Credentials: true', true);
})();

require_once $publicPath.'/index.php';
