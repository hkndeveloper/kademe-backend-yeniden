<?php

namespace App\Support;

use Spatie\Permission\Models\Role;

final class AuthorizationManagementCatalog
{
    /**
     * @return list<string>
     */
    public static function unitBusinessGroups(): array
    {
        return collect(config('permission_catalog.coordination_unit_business_groups', []))
            ->filter(fn ($group) => is_string($group) && $group !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public static function unitBusinessPermissions(): array
    {
        $catalog = config('permission_catalog.granular_permissions', []);
        $granularPermissions = collect(self::unitBusinessGroups())
            ->flatMap(fn (string $group) => $catalog[$group] ?? [])
            ->filter(fn ($permission) => is_string($permission) && $permission !== '')
            ->unique()
            ->values();
        $legacyAliases = collect(config('permission_catalog.legacy_map', []))
            ->filter(fn ($expanded) => collect(is_array($expanded) ? $expanded : [])
                ->intersect($granularPermissions)
                ->isNotEmpty())
            ->keys();

        return $granularPermissions
            ->merge($legacyAliases)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public static function protectedAuthorityRoles(): array
    {
        return collect(config('permission_catalog.coordination_unit_business_roles', ['coordinator', 'staff']))
            ->filter(fn ($role) => is_string($role) && $role !== '')
            ->unique()
            ->values()
            ->all();
    }

    public static function isUnitBusinessPermission(string $permissionName): bool
    {
        return in_array($permissionName, self::unitBusinessPermissions(), true);
    }

    public static function roleBusinessPermissionsAreReadOnly(string $roleName): bool
    {
        return self::mode() === 'enforce'
            && in_array($roleName, self::protectedAuthorityRoles(), true);
    }

    /**
     * Global rol matrisinde fiziksel olarak bulunan ve enforce modunda korunmasi
     * gereken granular/legacy izin adlarini dondurur.
     *
     * @return list<string>
     */
    public static function protectedStoredPermissionNames(Role $role): array
    {
        if (! self::roleBusinessPermissionsAreReadOnly($role->name)) {
            return [];
        }

        $role->loadMissing('permissions:id,name');

        return $role->permissions
            ->pluck('name')
            ->filter(fn (string $permission) => self::isUnitBusinessPermission($permission))
            ->unique()
            ->values()
            ->all();
    }

    public static function mode(): string
    {
        $mode = (string) config('coordination_authorization.mode', 'legacy');

        return in_array($mode, ['legacy', 'shadow', 'pilot', 'enforce'], true)
            ? $mode
            : 'legacy';
    }

    public static function metadata(): array
    {
        $mode = self::mode();
        $readOnly = $mode === 'enforce';
        $source = match ($mode) {
            'enforce' => 'coordination_units',
            'pilot' => 'mixed',
            default => 'role_matrix',
        };

        return [
            'mode' => $mode,
            'coordinator_staff_business_source' => $source,
            'role_matrix_business_read_only' => $readOnly,
            'unit_business_roles' => self::protectedAuthorityRoles(),
            'protected_roles' => $readOnly ? self::protectedAuthorityRoles() : [],
            'unit_business_groups' => self::unitBusinessGroups(),
            'unit_business_permissions' => self::unitBusinessPermissions(),
            'coordination_units_path' => '/panel/coordination-units',
            'user_override_scope' => $readOnly
                ? 'membership_for_business_permissions'
                : 'global_across_unit_memberships',
            'business_allow_requires_acknowledgement' => false,
            'legacy_global_business_allow_effective' => ! $readOnly,
        ];
    }
}
