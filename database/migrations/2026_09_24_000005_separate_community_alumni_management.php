<?php

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitPermissionRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $permission = Permission::findOrCreate('projects.alumni.manage', 'web');
            Role::query()->where('name', 'super_admin')->where('guard_name', 'web')->first()?->givePermissionTo($permission);

            $unit = CoordinationUnit::query()->where('code', 'service_community_culture')->first();
            if (! $unit) {
                return;
            }

            // The earlier rollout used the broad participant management rule.
            // Retire that default so community staff cannot change project credits.
            CoordinationUnitPermissionRule::query()
                ->where('unit_id', $unit->id)
                ->where('position', 'coordinator')
                ->where('permission_name', 'projects.participants.manage')
                ->where('effect', CoordinationUnitPermissionRule::EFFECT_ALLOW)
                ->where('scope_source', CoordinationUnitPermissionRule::SCOPE_RESPONSIBILITY_PROJECTS)
                ->where('service_domain', 'community_culture')
                ->where('status', CoordinationUnitPermissionRule::STATUS_ACTIVE)
                ->update(['status' => CoordinationUnitPermissionRule::STATUS_PASSIVE, 'ends_at' => now()]);

            if (CoordinationUnitPermissionRule::withTrashed()
                ->where('unit_id', $unit->id)
                ->where('position', 'coordinator')
                ->where('permission_name', 'projects.alumni.manage')
                ->exists()) {
                return;
            }

            CoordinationUnitPermissionRule::query()->create([
                'unit_id' => $unit->id,
                'position' => 'coordinator',
                'permission_name' => 'projects.alumni.manage',
                'effect' => CoordinationUnitPermissionRule::EFFECT_ALLOW,
                'scope_source' => CoordinationUnitPermissionRule::SCOPE_RESPONSIBILITY_PROJECTS,
                'service_domain' => 'community_culture',
                'scope_payload' => null,
                'status' => CoordinationUnitPermissionRule::STATUS_ACTIVE,
            ]);
        });
    }

    public function down(): void
    {
        // Do not widen participant management or revoke a configured alumni rule.
    }
};
