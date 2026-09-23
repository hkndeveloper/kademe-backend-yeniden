<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** @var list<string> */
    private array $permissions = [
        'inbox.view',
        'alumni_opportunities.view',
        'alumni_opportunities.manage',
        'forum.view',
        'forum.moderate',
    ];

    public function up(): void
    {
        foreach ($this->permissions as $permissionName) {
            Permission::findOrCreate($permissionName, 'web');
        }

        Role::findOrCreate('super_admin', 'web')->givePermissionTo($this->permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $role = Role::query()->where('name', 'super_admin')->where('guard_name', 'web')->first();
        if ($role) {
            $role->revokePermissionTo($this->permissions);
        }

        Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $this->permissions)
            ->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
