<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private array $rolePermissions = [
        'coordinator' => 'dashboard.coordinator.view',
        'staff' => 'dashboard.staff.view',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->rolePermissions as $roleName => $permissionName) {
            $permission = Permission::findOrCreate($permissionName, 'web');
            Role::findOrCreate($roleName, 'web')->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach ($this->rolePermissions as $roleName => $permissionName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            $role?->revokePermissionTo($permissionName);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
