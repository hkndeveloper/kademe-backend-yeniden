<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckBlacklist
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Kullanıcı giriş yapmış mı ve kara listede mi kontrol et
        if ($user && $user->status === 'blacklisted') {
            
            // Eğer belirli bir tarihe kadar kara listedeyse
            if ($user->blacklisted_until && now()->isBefore($user->blacklisted_until)) {
                return response()->json([
                    'error' => 'blacklisted',
                    'message' => 'Hesabınız ' . $user->blacklisted_until->format('d.m.Y H:i') . ' tarihine kadar kısıtlanmıştır.'
                ], 403);
            }

            // Süresiz kara listedeyse
            if (!$user->blacklisted_until) {
                return response()->json([
                    'error' => 'blacklisted',
                    'message' => 'Hesabınız sistem kurallarına uymadığınız için süresiz olarak kısıtlanmıştır. Lütfen iletişime geçiniz.'
                ], 403);
            }
            
            // Tarih dolmuşsa statüsünü aktife çek ve devam et
            if ($user->blacklisted_until && now()->isAfter($user->blacklisted_until)) {
                $user->update([
                    'status' => 'active',
                    'blacklisted_until' => null
                ]);
            }
        }

        return $next($request);
    }
}
