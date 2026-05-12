<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zincirin en dışında cevaba son kez CORS basliklari ekler (HandleCors / path / config-cache
 * kombinasyonunda baslik dusse bile). RefreshCorsConfigFromEnv ile ayni kurallar.
 */
final class FinalizeApiCorsHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        RefreshCorsConfigFromEnv::applyCorsToResponse($request, $response);

        return $response;
    }
}
