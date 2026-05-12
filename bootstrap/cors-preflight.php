<?php

declare(strict_types=1);

use Illuminate\Http\Request;

/**
 * Laravel bootstrap olmadan OPTIONS preflight cevabi (Railway / php artisan serve).
 * Middleware zincirine hic gelmeden 204 + CORS basliklari — tarayici blokajini kesin kaldir.
 */

function kademe_read_env_string_early(string $key): ?string
{
    if (isset($_ENV[$key]) && is_string($_ENV[$key]) && $_ENV[$key] !== '') {
        return $_ENV[$key];
    }

    foreach ($_SERVER as $serverKey => $value) {
        if (! is_string($value) || $value === '') {
            continue;
        }
        if (strcasecmp((string) $serverKey, $key) === 0) {
            return $value;
        }
    }

    $g = getenv($key);

    return ($g !== false && is_string($g) && $g !== '') ? $g : null;
}

function kademe_collect_cors_origins_early(): array
{
    $trim = static fn (string $v): string => trim($v, " \t\n\r\0\x0B\"'");

    $origins = [];

    $raw = kademe_read_env_string_early('CORS_ALLOWED_ORIGINS');
    if (is_string($raw) && $raw !== '') {
        foreach (explode(',', $raw) as $part) {
            $o = $trim($part);
            if ($o !== '') {
                $origins[] = $o;
            }
        }
    }

    $frontend = kademe_read_env_string_early('FRONTEND_URL');
    if (is_string($frontend) && $frontend !== '') {
        $parsed = parse_url($trim($frontend));
        if (is_array($parsed) && isset($parsed['scheme'], $parsed['host'])) {
            $scheme = $parsed['scheme'];
            $host = $parsed['host'];
            $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';
            $origins[] = $scheme.'://'.$host.$port;
            if (! str_starts_with(strtolower($host), 'www.')) {
                $origins[] = $scheme.'://www.'.$host.$port;
            } else {
                $apex = preg_replace('/^www\./i', '', $host);
                if ($apex !== '' && $apex !== $host) {
                    $origins[] = $scheme.'://'.$apex.$port;
                }
            }
        }
    }

    foreach ([
        'https://hakankekec.me',
        'https://www.hakankekec.me',
        'http://localhost:3000',
        'http://127.0.0.1:3000',
    ] as $fallback) {
        $origins[] = $fallback;
    }

    return array_values(array_unique(array_filter($origins)));
}

/**
 * @param  list<string>  $allowed
 */
function kademe_cors_origin_allowed(string $origin, array $allowed): bool
{
    foreach ($allowed as $candidate) {
        if (! is_string($candidate) || $candidate === '') {
            continue;
        }
        if (strcasecmp(rtrim(trim($candidate), '/'), $origin) === 0) {
            return true;
        }
    }

    return false;
}

/**
 * Cevapta gercek ACAO yoksa: path + Origin icin Request + CGI yedekleri (Railway / php -S REQUEST_URI sapmasi).
 * Basliklar Response uzerinden gider; send() ile edge'e yansir.
 */
function kademe_emit_native_cors_if_missing(Request $request, \Symfony\Component\HttpFoundation\Response $response): void
{
    $existing = $response->headers->has('Access-Control-Allow-Origin')
        ? trim((string) $response->headers->get('Access-Control-Allow-Origin')) : '';
    if ($existing !== '') {
        return;
    }

    $path = $request->getPathInfo();
    if ($path === '' || $path === '/') {
        $raw = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $parsed = parse_url($raw, PHP_URL_PATH);
        $path = is_string($parsed) && $parsed !== '' ? $parsed : '/';
    }

    $looksApi = str_starts_with($path, '/api')
        || str_starts_with($path, '/sanctum')
        || str_contains($path, '/api/')
        || str_contains($path, '/sanctum/');

    if (! $looksApi) {
        return;
    }

    $originRaw = $request->headers->get('Origin');
    if (! is_string($originRaw) || trim($originRaw) === '') {
        $originRaw = isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : '';
    }
    $origin = trim($originRaw);
    $origin = $origin !== '' ? rtrim($origin, '/') : '';
    if ($origin === '') {
        return;
    }

    $allowed = kademe_collect_cors_origins_early();
    if (! kademe_cors_origin_allowed($origin, $allowed)) {
        return;
    }

    $response->headers->set('Access-Control-Allow-Origin', $origin);
    $response->headers->set('Access-Control-Allow-Credentials', 'true');
    $response->headers->set('X-Kademe-Cors-Native', '1');

    $vary = (string) ($response->headers->get('Vary') ?? '');
    if ($vary === '') {
        $response->headers->set('Vary', 'Origin');
    } elseif (! preg_match('/\bOrigin\b/i', $vary)) {
        $response->headers->set('Vary', $vary.', Origin');
    }
}

function kademe_maybe_exit_options_preflight(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'OPTIONS') {
        return;
    }

    $rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $path = is_string($rawPath) ? $rawPath : '/';

    $matchesApi = str_starts_with($path, '/api')
        || str_starts_with($path, '/sanctum');

    if (! $matchesApi) {
        return;
    }

    $origin = isset($_SERVER['HTTP_ORIGIN']) ? trim((string) $_SERVER['HTTP_ORIGIN']) : '';
    $origin = $origin !== '' ? rtrim($origin, '/') : '';
    $allowed = kademe_collect_cors_origins_early();

    if ($origin === '' || ! kademe_cors_origin_allowed($origin, $allowed)) {
        return;
    }

    $reqHeaders = $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? '';
    if ($reqHeaders === '') {
        $reqHeaders = '*';
    }

    header('Access-Control-Allow-Origin: '.$origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: '.$reqHeaders);
    header('Access-Control-Max-Age: 86400');
    header('Vary: Origin, Access-Control-Request-Method, Access-Control-Request-Headers');

    http_response_code(204);
    // Bazı PHP / proxy kombinasyonları 204'e varsayılan Content-Type: text/html ekler (gereksiz).
    if (function_exists('header_remove')) {
        header_remove('Content-Type');
    }
    exit;
}
