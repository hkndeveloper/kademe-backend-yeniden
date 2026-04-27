<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $roles = array_keys(config('permission_catalog.role_labels', []));
        $permissions = collect(config('permission_catalog.legacy_permissions', []))->pluck('name')->all();
        $defaultRolePermissions = config('permission_catalog.default_role_permissions', []);

        foreach ($roles as $roleName) {
            Role::findOrCreate($roleName, 'web');
        }

        foreach ($permissions as $permissionName) {
            Permission::findOrCreate($permissionName, 'web');
        }

        foreach ($defaultRolePermissions as $roleName => $assignedPermissions) {
            $role = Role::findByName($roleName, 'web');

            if ($assignedPermissions === '*') {
                $role->syncPermissions(Permission::query()->pluck('name')->all());
                continue;
            }

            $role->syncPermissions($assignedPermissions);
        }
    }
}
