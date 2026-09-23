<?php

namespace App\Services;

use App\Models\User;

class CoordinationAuthorizationPreviewService
{
    public function __construct(
        private readonly PermissionResolver $permissionResolver,
        private readonly CoordinationUnitAuthorizationResolver $coordinationUnitAuthorizationResolver
    ) {}

    public function preview(User $user): array
    {
        $legacy = $this->permissionResolver->resolveLegacySnapshot($user);
        $unit = $this->coordinationUnitAuthorizationResolver->resolve(
            $user,
            collect($legacy['direct_overrides'] ?? [])
        );

        return [
            'user' => [
                'id' => (int) $user->id,
                'name' => trim($user->name.' '.$user->surname),
                'email' => $user->email,
                'role' => $user->role,
            ],
            'configured_mode' => config('coordination_authorization.mode', 'legacy'),
            'coordination_preview_mode' => 'all_memberships_audit_only',
            'legacy' => $this->shape($legacy),
            'coordination_units' => $this->shape($unit),
            'diff' => $this->diff($legacy, $unit),
        ];
    }

    private function shape(array $authorization): array
    {
        return [
            'effective_permissions' => collect($authorization['effective_permissions'] ?? [])->values()->all(),
            'scopes' => $authorization['scopes'] ?? [],
            'contexts' => $authorization['contexts'] ?? [],
        ];
    }

    private function diff(array $legacy, array $unit): array
    {
        $legacyPermissions = collect($legacy['effective_permissions'] ?? [])->map(fn ($item) => (string) $item);
        $unitPermissions = collect($unit['effective_permissions'] ?? [])->map(fn ($item) => (string) $item);
        $common = $legacyPermissions->intersect($unitPermissions)->unique();

        return [
            'legacy_only_permissions' => $legacyPermissions->diff($unitPermissions)->sort()->values()->all(),
            'unit_only_permissions' => $unitPermissions->diff($legacyPermissions)->sort()->values()->all(),
            'scope_differences' => $common
                ->filter(fn (string $permission) => ($legacy['scopes'][$permission] ?? null)
                    !== ($unit['scopes'][$permission] ?? null))
                ->sort()
                ->values()
                ->all(),
        ];
    }
}
