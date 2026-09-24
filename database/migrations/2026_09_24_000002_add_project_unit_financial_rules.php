<?php

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitPermissionRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rules = [
            'coordinator' => ['financial.view', 'financial.create', 'financial.export', 'financial.invoice.download'],
            'staff' => ['financial.view', 'financial.create', 'financial.invoice.download'],
        ];

        DB::transaction(function () use ($rules): void {
            foreach (CoordinationUnit::query()->where('kind', CoordinationUnit::KIND_PROJECT)->get() as $unit) {
                // Untemplated units must be bootstrapped as a whole by the normal rule sync.
                if (! CoordinationUnitPermissionRule::withTrashed()->where('unit_id', $unit->id)->exists()) {
                    continue;
                }

                foreach ($rules as $position => $permissions) {
                    foreach ($permissions as $permission) {
                        // An existing inactive rule is an explicit administrator choice.
                        if (CoordinationUnitPermissionRule::query()
                            ->where('unit_id', $unit->id)
                            ->where('position', $position)
                            ->where('permission_name', $permission)
                            ->exists()) {
                            continue;
                        }

                        CoordinationUnitPermissionRule::query()->create([
                            'unit_id' => $unit->id,
                            'position' => $position,
                            'permission_name' => $permission,
                            'effect' => CoordinationUnitPermissionRule::EFFECT_ALLOW,
                            'scope_source' => CoordinationUnitPermissionRule::SCOPE_LINKED_PROJECT,
                            'scope_payload' => null,
                            'status' => CoordinationUnitPermissionRule::STATUS_ACTIVE,
                        ]);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        // Keep administrator changes and transaction access history intact.
    }
};
