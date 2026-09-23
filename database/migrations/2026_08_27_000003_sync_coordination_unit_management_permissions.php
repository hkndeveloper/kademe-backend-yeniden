<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private array $permissions = [
        'coordination_units.view',
        'coordination_units.manage',
        'coordination_units.memberships.manage',
        'coordination_units.responsibilities.manage',
        'coordination_units.permissions.view',
        'coordination_units.permissions.manage',
        'coordination_units.authorization.preview',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::findOrCreate('super_admin', 'web')->givePermissionTo($this->permissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $role = Role::query()->where('name', 'super_admin')->where('guard_name', 'web')->first();
        $role?->revokePermissionTo($this->permissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
