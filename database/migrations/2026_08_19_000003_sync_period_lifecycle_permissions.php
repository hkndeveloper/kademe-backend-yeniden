<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private array $coordinatorPermissions = [
        'periods.activate',
        'periods.closing.start',
        'periods.closing.cancel',
        'periods.complete',
        'periods.cancel',
        'periods.archive.verify',
    ];

    private array $restrictedPermissions = [
        'periods.reopen',
        'periods.archive.correct',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $allPermissions = [...$this->coordinatorPermissions, ...$this->restrictedPermissions];
        foreach ($allPermissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::findOrCreate('super_admin', 'web')->givePermissionTo($allPermissions);
        Role::findOrCreate('coordinator', 'web')->givePermissionTo($this->coordinatorPermissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (['super_admin', 'coordinator'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            $role?->revokePermissionTo([...$this->coordinatorPermissions, ...$this->restrictedPermissions]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
