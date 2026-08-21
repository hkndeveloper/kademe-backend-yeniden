<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private array $permissions = [
        'applications.intake.view',
        'applications.intake.manage',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (['super_admin', 'coordinator'] as $roleName) {
            Role::findOrCreate($roleName, 'web')->givePermissionTo($this->permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (['super_admin', 'coordinator'] as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            $role?->revokePermissionTo($this->permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
