<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditAdminActions
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();
        if (! $user) {
            return $response;
        }

        // Sadece admin API aksiyonlarini denetim kaydina al.
        if (! $request->is('api/admin/*')) {
            return $response;
        }

        try {
            $statusCode = $response->getStatusCode();
            $event = $this->resolveEvent($request->method(), $statusCode);
            $subject = $this->resolveSubjectModel($request);

            $properties = [
                'status_code' => $statusCode,
                'outcome' => $statusCode >= 400 ? 'denied_or_failed' : 'success',
                'http_method' => $request->method(),
                'path' => $request->path(),
                'route_uri' => $request->route()?->uri(),
                'route_name' => $request->route()?->getName(),
                'ip_address' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
                'permission_checked' => $request->attributes->get('audit.permission_checked'),
                'permission_any_checked' => $request->attributes->get('audit.permission_any_checked'),
                'permission_scope' => $request->attributes->get('audit.permission_scope'),
                'route_parameters' => $this->sanitizeRouteParameters($request),
                'query' => $request->query(),
            ];

            // Ham body yerine yalnizca alan adlarini loglayarak hassas veri riskini azalt.
            if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                $properties['payload_keys'] = array_values(array_keys($request->except([
                    'password',
                    'password_confirmation',
                    'token',
                    'current_password',
                ])));
            }

            $logger = activity()
                ->useLog('admin_actions')
                ->causedBy($user)
                ->event($event)
                ->withProperties($properties);

            if ($subject !== null) {
                $logger->performedOn($subject);
            }

            $logger->log($this->buildDescription($request, $statusCode));
        } catch (\Throwable) {
            // Audit log hatasi ana is akisini kesmemeli.
        }

        return $response;
    }

    private function resolveEvent(string $method, int $statusCode): string
    {
        if ($statusCode === 403) {
            return 'forbidden';
        }

        if ($statusCode >= 400) {
            return 'failed';
        }

        return match ($method) {
            'POST' => 'created',
            'PUT', 'PATCH' => 'updated',
            'DELETE' => 'deleted',
            default => 'viewed',
        };
    }

    private function buildDescription(Request $request, int $statusCode): string
    {
        $routeUri = $request->route()?->uri() ?? $request->path();

        return sprintf(
            'admin_action.%s.%s (%d)',
            strtolower($request->method()),
            str_replace('/', '.', $routeUri),
            $statusCode
        );
    }

    private function resolveSubjectModel(Request $request): ?Model
    {
        foreach ($request->route()?->parameters() ?? [] as $value) {
            if ($value instanceof Model) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function sanitizeRouteParameters(Request $request): array
    {
        $parameters = $request->route()?->parameters() ?? [];
        $out = [];

        foreach ($parameters as $key => $value) {
            if ($value instanceof Model) {
                $out[$key] = [
                    'model' => class_basename($value),
                    'id' => $value->getKey(),
                ];
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }
}
