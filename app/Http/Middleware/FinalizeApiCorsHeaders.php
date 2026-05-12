<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zincirin en dışında cevaba son kez CORS basliklari ekler.
 * HeaderBag yaninda PHP native header() da kullanilir —
 * php artisan serve ortaminda HeaderBag duserse bile tarayiciya ulasir.
 */
final class FinalizeApiCorsHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Middleware uzerinden CORS header'lari ekle.
        RefreshCorsConfigFromEnv::applyCorsToResponse($request, $response);

        // PHP native header() ile de gonder — belt-and-suspenders.
        $acao = $response->headers->get('Access-Control-Allow-Origin');
        if (is_string($acao) && $acao !== '' && ! headers_sent()) {
            header('Access-Control-Allow-Origin: ' . $acao, true);
            header('Access-Control-Allow-Credentials: true', true);
        }

        return $response;
    }
}
