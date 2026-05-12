<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Env;
use Symfony\Component\HttpFoundation\Response;

/**
 * Railway'de config:cache bos CORS ile yapildiginda HandleCors izin vermez.
 * Ortamdan izinli origin listesini okur; OPTIONS preflight ve gerekiyorsa
 * asil cevaba Access-Control-* ekler (HandleCors path eslesmezse yedek).
 */
final class RefreshCorsConfigFromEnv
{
    /**
     * Uygulama boot'unda ve istek oncesi: HandleCors / fruitcake icin config listesi.
     */
    public static function syncCorsConfigFromEnv(): void
    {
        $self = new self;
        $origins = $self->collectAllowedOrigins();
        if ($origins !== []) {
            config(['cors.allowed_origins' => $origins]);
        }
    }

    public function handle(Request $request, Closure $next): Response
    {
        self::syncCorsConfigFromEnv();

        $origins = $this->collectAllowedOrigins();
        $origin = $this->normalizeOriginHeader($this->resolveInboundOrigin($request));

        // Preflight — bazı proxylarda HandleCors path/eşleşme yüzünden devreye girmeyebilir.
        if (
            $request->getMethod() === 'OPTIONS'
            && $origin !== null && $origin !== ''
            && $origins !== []
            && $this->isOriginInList($origin, $origins)
            && $this->shouldApplyCorsToPath($request)
        ) {
            return $this->preflightResponse($origin, $request);
        }

        $response = $next($request);
        $this->applyCorsHeaders($request, $response, $origins, $origin);

        return $response;
    }

    /**
     * Exception cevaplari ve FinalizeApiCorsHeaders: tum HTTP cevaplarina CORS uygular.
     */
    public static function applyCorsToResponse(Request $request, Response $response): void
    {
        self::syncCorsConfigFromEnv();
        $self = new self;
        $origins = $self->collectAllowedOrigins();
        $origin = $self->normalizeOriginHeader($self->resolveInboundOrigin($request));
        $self->applyCorsHeaders($request, $response, $origins, $origin);
    }

    /**
     * applyCorsToResponse sonrasi hala ACAO yoksa: path ve Origin sadece $_SERVER uzerinden (Request/proxy edge).
     */
    public static function applyGlobalsFallbackIfMissingCors(Request $request, Response $response): void
    {
        $existing = $response->headers->has('Access-Control-Allow-Origin')
            ? trim((string) $response->headers->get('Access-Control-Allow-Origin')) : '';
        if ($existing !== '') {
            return;
        }

        $self = new self;
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

        $origin = $self->normalizeOriginHeader($self->resolveInboundOrigin($request));
        $origins = $self->collectAllowedOrigins();
        if ($origin === null || $origins === [] || ! $self->isOriginInList($origin, $origins)) {
            return;
        }

        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $vary = (string) ($response->headers->get('Vary') ?? '');
        if ($vary === '') {
            $response->headers->set('Vary', 'Origin');
        } elseif (! preg_match('/\bOrigin\b/i', $vary)) {
            $response->headers->set('Vary', $vary.', Origin');
        }
    }

    /**
     * Bazı edge / proxy zincirlerinde Symfony bag Origin taşımayabilir; CGI değişkenine düş.
     */
    private function resolveInboundOrigin(Request $request): ?string
    {
        $fromBag = $request->headers->get('Origin');
        if (is_string($fromBag) && trim($fromBag) !== '') {
            return $fromBag;
        }

        $raw = $_SERVER['HTTP_ORIGIN'] ?? getenv('HTTP_ORIGIN');
        if (is_string($raw) && trim($raw) !== '') {
            return $raw;
        }

        return null;
    }

    private function normalizeOriginHeader(?string $origin): ?string
    {
        if ($origin === null) {
            return null;
        }
        $origin = trim($origin);
        if ($origin === '') {
            return null;
        }

        return rtrim($origin, '/');
    }

    private function isOriginInList(string $origin, array $origins): bool
    {
        foreach ($origins as $allowed) {
            if (! is_string($allowed) || $allowed === '') {
                continue;
            }
            if (strcasecmp(rtrim(trim($allowed), '/'), $origin) === 0) {
                return true;
            }
        }

        return false;
    }

    private function applyCorsHeaders(Request $request, Response $response, array $origins, ?string $origin): void
    {
        if (
            $origin !== null && $origin !== ''
            && $origins !== []
            && $this->isOriginInList($origin, $origins)
            && $this->shouldApplyCorsToPath($request)
        ) {
            // Laravel CORS middleware (HandleCors) zaten header eklemişse tekrar ekleme.
            $existingOrigin = $response->headers->has('Access-Control-Allow-Origin')
                ? trim((string) $response->headers->get('Access-Control-Allow-Origin')) : '';
            if ($existingOrigin !== '') {
                return;
            }

            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $vary = (string) ($response->headers->get('Vary') ?? '');
            if ($vary === '') {
                $response->headers->set('Vary', 'Origin');
            } elseif (! preg_match('/\bOrigin\b/i', $vary)) {
                $response->headers->set('Vary', $vary.', Origin');
            }

            // php artisan serve (PHP built-in server) HeaderBag'i dusurur.
            // Native header() ile de gonder — belt-and-suspenders.
            if (! headers_sent()) {
                header('Access-Control-Allow-Origin: ' . $origin, true);
                header('Access-Control-Allow-Credentials: true', true);
            }
        }
    }

    private function shouldApplyCorsToPath(Request $request): bool
    {
        // cors.php 'paths' => ['*'] oldugu icin tüm yollara CORS uygulanmali.
        // HandleCors kaldirildi; bu middleware tamamen sorumluluk aliyor.
        $configPaths = config('cors.paths', ['*']);
        if (in_array('*', $configPaths, true)) {
            return true;
        }

        // Config daha kisitliysa: request path kontrolu yap.
        if ($request->is(...$configPaths)) {
            return true;
        }

        // Yedek: /api ve /sanctum yollari her zaman CORS kapsaminda.
        $serverUri = (string) ($request->server->get('REQUEST_URI') ?? ($_SERVER['REQUEST_URI'] ?? ''));
        $pathFromServer = parse_url($serverUri, PHP_URL_PATH);
        if (is_string($pathFromServer) && $pathFromServer !== '') {
            if (
                str_starts_with($pathFromServer, '/api')
                || str_contains($pathFromServer, '/api/')
                || str_starts_with($pathFromServer, '/sanctum')
                || str_contains($pathFromServer, '/sanctum/')
            ) {
                return true;
            }
        }

        return $request->is('api', 'api/*', 'sanctum/csrf-cookie', 'sanctum/*');
    }

    private function preflightResponse(string $allowedOrigin, Request $request): Response
    {
        $reqHeaders = $request->headers->get('Access-Control-Request-Headers');

        return response('', 204)->withHeaders([
            'Access-Control-Allow-Origin' => $allowedOrigin,
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => $reqHeaders !== null && $reqHeaders !== '' ? $reqHeaders : '*',
            'Access-Control-Max-Age' => '86400',
            'Vary' => 'Origin, Access-Control-Request-Method, Access-Control-Request-Headers',
        ]);
    }

    /**
     * @return list<string>
     */
    private function collectAllowedOrigins(): array
    {
        $trim = static fn (string $v): string => trim($v, " \t\n\r\0\x0B\"'");

        $origins = [];

        $rawList = $this->readEnvString('CORS_ALLOWED_ORIGINS');
        if ($rawList !== null) {
            foreach (explode(',', $rawList) as $part) {
                $o = $trim($part);
                if ($o !== '') {
                    $origins[] = $o;
                }
            }
        }

        $frontend = $this->readEnvString('FRONTEND_URL');
        if ($frontend !== null) {
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

        foreach (config('kademe.fallback_cors_origins', []) as $fallback) {
            if (is_string($fallback) && $fallback !== '') {
                $origins[] = $fallback;
            }
        }

        // config() henuz yuklenmediyse bile (cok erken pipe) bos kalmasin — index.php ile ayni liste.
        foreach ([
            'https://hakankekec.me',
            'https://www.hakankekec.me',
            'http://localhost:3000',
            'http://127.0.0.1:3000',
        ] as $hard) {
            $origins[] = $hard;
        }

        return array_values(array_unique(array_filter($origins)));
    }

    private function readEnvString(string $key): ?string
    {
        try {
            $fromEnv = Env::get($key);
            if (is_string($fromEnv) && $fromEnv !== '') {
                return $fromEnv;
            }
        } catch (\Throwable) {
            // Repository henüz hazır değilse veya anahtar yoksa devam et.
        }

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

        $fromGetenv = getenv($key);

        return ($fromGetenv !== false && $fromGetenv !== '') ? $fromGetenv : null;
    }
}
