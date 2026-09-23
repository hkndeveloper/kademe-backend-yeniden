<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSION = 'programs.logistics.view';

    public function up(): void
    {
        Permission::findOrCreate(self::PERMISSION, 'web');
        Role::findOrCreate('super_admin', 'web')->givePermissionTo(self::PERMISSION);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $role = Role::query()
            ->where('name', 'super_admin')
            ->where('guard_name', 'web')
            ->first();
        $role?->revokePermissionTo(self::PERMISSION);

        Permission::query()
            ->where('name', self::PERMISSION)
            ->where('guard_name', 'web')
            ->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
