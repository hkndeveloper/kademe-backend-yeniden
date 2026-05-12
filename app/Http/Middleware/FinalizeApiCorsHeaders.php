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

        return $response;
    }
}
