<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Project;
use Illuminate\Http\Request;

/**
 * Controller sinifinda `private readonly PermissionResolver $permissionResolver` beklenir.
 */
trait AuthorizesGranularPermissions
{
    protected function abortUnlessAllowed(Request $request, string $permission): void
    {
        $request->attributes->set('audit.permission_checked', $permission);

        abort_unless(
            $this->permissionResolver->hasPermission($request->user(), $permission),
            403,
            'Bu islem icin yetkiniz bulunmuyor.'
        );
    }

    protected function abortUnlessProjectAllowed(Request $request, string $permission, ?int $projectId): void
    {
        $request->attributes->set('audit.permission_checked', $permission);
        $request->attributes->set('audit.permission_scope', [
            'scope_type' => 'project',
            'project_id' => $projectId,
        ]);

        $allowed = $projectId === null
            ? $this->permissionResolver->hasGlobalScope($request->user(), $permission)
            : $this->permissionResolver->canAccessProject($request->user(), $permission, $projectId);

        abort_unless($allowed, 403, 'Bu proje icin yetkiniz bulunmuyor.');
    }

    protected function abortUnlessUnitAllowed(Request $request, string $permission, ?string $unit): void
    {
        $request->attributes->set('audit.permission_checked', $permission);
        $request->attributes->set('audit.permission_scope', [
            'scope_type' => 'unit',
            'unit' => $unit,
        ]);

        abort_unless(
            $this->permissionResolver->canAccessUnit($request->user(), $permission, $unit),
            403,
            'Bu birim icin yetkiniz bulunmuyor.'
        );
    }

    /**
     * Proje icerigi: proje yoksa yalnizca genel izin; varsa canAccessProject.
     */
    protected function abortUnlessAllowedForProject(Request $request, string $permission, ?Project $project = null): void
    {
        $request->attributes->set('audit.permission_checked', $permission);
        $request->attributes->set('audit.permission_scope', [
            'scope_type' => 'project',
            'project_id' => $project?->id,
        ]);

        $allowed = $project !== null
            ? $this->permissionResolver->canAccessProject($request->user(), $permission, $project->id)
            : $this->permissionResolver->hasPermission($request->user(), $permission);

        abort_unless($allowed, 403, 'Bu islem icin yetkiniz bulunmuyor.');
    }

    /** Verilen izinlerden en az biri yoksa 403. */
    protected function abortUnlessAnyPermission(Request $request, array $permissions): void
    {
        $request->attributes->set('audit.permission_any_checked', array_values($permissions));

        $user = $request->user();
        foreach ($permissions as $permission) {
            if ($this->permissionResolver->hasPermission($user, $permission)) {
                $request->attributes->set('audit.permission_checked', $permission);
                return;
            }
        }

        abort(403, 'Bu islem icin yetkiniz bulunmuyor.');
    }
}
