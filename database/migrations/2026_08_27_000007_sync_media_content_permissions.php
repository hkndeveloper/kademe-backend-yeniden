<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private array $permissions = [
        'projects.public_content.view',
        'projects.public_content.update',
        'content.blog.publish',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::findOrCreate('super_admin', 'web')->givePermissionTo($this->permissions);

        // Older dry-runs/deployments may already have copied the broad project-view
        // template to the media unit. Keep the row for audit/rollback, but passivate
        // it so media membership cannot expose participants or application settings.
        if (Schema::hasTable('coordination_units') && Schema::hasTable('coordination_unit_permission_rules')) {
            $mediaUnitIds = DB::table('coordination_units')
                ->where('code', 'service_media')
                ->pluck('id');

            if ($mediaUnitIds->isNotEmpty()) {
                DB::table('coordination_unit_permission_rules')
                    ->whereIn('unit_id', $mediaUnitIds)
                    ->where('permission_name', 'projects.view')
                    ->where('status', 'active')
                    ->update([
                        'status' => 'passive',
                        'updated_at' => now(),
                    ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $role = Role::query()->where('name', 'super_admin')->where('guard_name', 'web')->first();
        $role?->revokePermissionTo($this->permissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
