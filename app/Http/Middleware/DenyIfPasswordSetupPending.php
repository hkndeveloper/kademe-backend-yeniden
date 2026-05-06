<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DenyIfPasswordSetupPending
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password) {
            return response()->json([
                'message' => 'Sifrenizi henuz belirlemediniz. E-postaniza gonderilen baglanti ile sifre olusturun.',
                'must_change_password' => true,
                'error' => 'password_setup_required',
            ], 403);
        }

        return $next($request);
    }
}
