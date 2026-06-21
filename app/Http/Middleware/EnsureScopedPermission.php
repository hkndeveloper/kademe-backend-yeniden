<?php

namespace App\Http\Middleware;

use App\Services\PermissionResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureScopedPermission
{
    public function __construct(private readonly PermissionResolver $permissionResolver)
    {
    }

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        $permissions = collect($permissions)
            ->flatMap(fn (string $permission) => preg_split('/[|,]/', $permission) ?: [])
            ->map(fn (string $permission) => trim($permission))
            ->filter()
            ->values();

        abort_unless($user !== null && $permissions->isNotEmpty(), 403, 'Bu islem icin yetkiniz bulunmuyor.');

        foreach ($permissions as $permission) {
            if ($this->isParticipantPermission($permission) && ! in_array($user->role, ['student', 'alumni'], true)) {
                continue;
            }

            if (! $this->permissionResolver->hasPermission($user, $permission)) {
                continue;
            }

            $scope = $this->permissionResolver->scopeFor($user, $permission);
            if (($scope['scope_type'] ?? 'none') === 'none') {
                continue;
            }

            $request->attributes->set('audit.permission_checked', $permission);
            $request->attributes->set('audit.permission_scope', $scope);

            return $next($request);
        }

        abort(403, 'Bu islem icin yetkiniz bulunmuyor.');
    }

    private function isParticipantPermission(string $permission): bool
    {
        return str_starts_with($permission, 'participant.') || str_starts_with($permission, 'alumni.');
    }
}
