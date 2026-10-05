<?php

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitPermissionRule;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach (CoordinationUnit::query()->where('kind', CoordinationUnit::KIND_PROJECT)->get() as $unit) {
            $canCreate = CoordinationUnitPermissionRule::query()
                ->where('unit_id', $unit->id)
                ->where('position', 'coordinator')
                ->where('permission_name', 'financial.create')
                ->where('effect', CoordinationUnitPermissionRule::EFFECT_ALLOW)
                ->where('status', CoordinationUnitPermissionRule::STATUS_ACTIVE)
                ->exists();

            // A disabled or customized update rule is an administrator decision.
            $hasUpdateRule = CoordinationUnitPermissionRule::withTrashed()
                ->where('unit_id', $unit->id)
                ->where('position', 'coordinator')
                ->where('permission_name', 'financial.update')
                ->exists();

            if (! $canCreate || $hasUpdateRule) {
                continue;
            }

            CoordinationUnitPermissionRule::query()->create([
                'unit_id' => $unit->id,
                'position' => 'coordinator',
                'permission_name' => 'financial.update',
                'effect' => CoordinationUnitPermissionRule::EFFECT_ALLOW,
                'scope_source' => CoordinationUnitPermissionRule::SCOPE_LINKED_PROJECT,
                'scope_payload' => null,
                'status' => CoordinationUnitPermissionRule::STATUS_ACTIVE,
            ]);
        }
    }

    public function down(): void
    {
        // Preserve later administrator changes to unit permissions.
    }
};
