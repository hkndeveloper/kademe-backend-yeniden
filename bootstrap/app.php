<?php

use App\Http\Middleware\RefreshCorsConfigFromEnv;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Railway / TLS sonlandirma: X-Forwarded-* güvenilir olsun (Host, Scheme, Client IP).
        $middleware->trustProxies(at: '*');

        // Laravel'in yerlesik HandleCors middleware'ini kaldir.
        // config:cache bosken veya Railway ortaminda env yuklenmediginde HandleCors
        // CORS basliklarini eklemez/siler; custom middleware ile catisir.
        $middleware->remove(\Illuminate\Http\Middleware\HandleCors::class);

        // Her istekten önce CORS izin listesi + gerekirse preflight cevabı.
        $middleware->prepend(\App\Http\Middleware\RefreshCorsConfigFromEnv::class);
        $middleware->prepend(\App\Http\Middleware\FinalizeApiCorsHeaders::class);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/auth/login');

        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'blacklist' => \App\Http\Middleware\CheckBlacklist::class,
            'kvkk' => \App\Http\Middleware\CheckKvkkConsent::class,
            'audit.action' => \App\Http\Middleware\AuditAdminActions::class,
            'password.not_pending_setup' => \App\Http\Middleware\DenyIfPasswordSetupPending::class,
            'scoped.permission' => \App\Http\Middleware\EnsureScopedPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return null;
        });

        // Yakalanan hatalar middleware zincirini kirpar; tarayici CORS yok sanir. API/Sanctum
        // yollarinda izinli Origin icin exception cevabina da ACAO eklenir.
        $exceptions->respond(function (SymfonyResponse $response, \Throwable $e, Request $request): SymfonyResponse {
            RefreshCorsConfigFromEnv::applyCorsToResponse($request, $response);

            return $response;
        });
    })->create();
