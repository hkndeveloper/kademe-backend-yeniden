<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckKvkkConsent
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Kullanıcı giriş yapmış ve KVKK onayı YOKSA
        if ($user && is_null($user->kvkk_consent_at)) {
            
            // Eğer istek zaten KVKK onaylama isteğiyse sonsuz döngüye girmemesi için izin ver
            if ($request->is('api/user/consent-kvkk') || $request->is('api/auth/logout')) {
                return $next($request);
            }

            return response()->json([
                'error' => 'kvkk_required',
                'message' => 'Sistemi kullanmaya devam edebilmek için KVKK aydınlatma metnini onaylamanız gerekmektedir.'
            ], 403);
        }

        return $next($request);
    }
}
