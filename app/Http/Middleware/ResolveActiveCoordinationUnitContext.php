<?php

namespace App\Http\Middleware;

use App\Services\ActiveCoordinationUnitContext;
use App\Services\PermissionResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ResolveActiveCoordinationUnitContext
{
    public function __construct(
        private readonly ActiveCoordinationUnitContext $context,
        private readonly PermissionResolver $permissionResolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // PHPUnit, Octane ve queue worker gibi ayni container'i birden fazla istek
        // boyunca yasatabilen ortamlarda onceki istegin snapshot'i tasinmasin.
        $this->permissionResolver->flushRequestCache();

        if ($request->is('api/auth/logout')) {
            return $next($request);
        }

        $user = $request->user();
        if ($user !== null) {
            try {
                $selection = $this->context->resolveRequest($request, $user);
            } catch (HttpExceptionInterface $exception) {
                $status = $exception->getStatusCode();

                return response()->json([
                    'message' => $exception->getMessage(),
                    'error' => $status === 409
                        ? 'coordination_unit_context_required'
                        : 'invalid_coordination_unit_context',
                    'context_header' => ActiveCoordinationUnitContext::HEADER,
                ], $status);
            }
            $request->attributes->set(ActiveCoordinationUnitContext::ATTRIBUTE, $selection['metadata']);
            $request->attributes->set('audit.active_coordination_context', $selection['metadata']);
        }

        $response = $next($request);
        $activeUnitId = $request->attributes->get(ActiveCoordinationUnitContext::ATTRIBUTE)['active_unit_id'] ?? null;
        if ($activeUnitId !== null) {
            $response->headers->set(ActiveCoordinationUnitContext::HEADER, (string) $activeUnitId);
        }

        return $response;
    }
}
