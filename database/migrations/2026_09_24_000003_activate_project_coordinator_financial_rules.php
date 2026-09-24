<?php

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitPermissionRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (CoordinationUnit::query()->where('kind', CoordinationUnit::KIND_PROJECT)->get() as $unit) {
                foreach (['financial.view', 'financial.create'] as $permission) {
                    $rule = CoordinationUnitPermissionRule::withTrashed()
                        ->where('unit_id', $unit->id)
                        ->where('position', 'coordinator')
                        ->where('permission_name', $permission)
                        ->latest('id')
                        ->first();

                    if ($rule) {
                        $rule->restore();
                        $rule->update([
                            'effect' => CoordinationUnitPermissionRule::EFFECT_ALLOW,
                            'scope_source' => CoordinationUnitPermissionRule::SCOPE_LINKED_PROJECT,
                            'service_domain' => null,
                            'scope_payload' => null,
                            'status' => CoordinationUnitPermissionRule::STATUS_ACTIVE,
                            'starts_at' => null,
                            'ends_at' => null,
                        ]);

                        continue;
                    }

                    CoordinationUnitPermissionRule::query()->create([
                        'unit_id' => $unit->id,
                        'position' => 'coordinator',
                        'permission_name' => $permission,
                        'effect' => CoordinationUnitPermissionRule::EFFECT_ALLOW,
                        'scope_source' => CoordinationUnitPermissionRule::SCOPE_LINKED_PROJECT,
                        'scope_payload' => null,
                        'status' => CoordinationUnitPermissionRule::STATUS_ACTIVE,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Do not revoke access that administrators may have adjusted after this migration.
    }
};
