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
            'coordinator' => [
                'projects.participants.view', 'projects.alumni.manage',
                'projects.alumni.view', 'projects.student_cv.view',
                'certificates.view', 'certificates.create', 'certificates.delete', 'certificates.export',
                'alumni_opportunities.view', 'alumni_opportunities.manage',
            ],
            'staff' => [
                'projects.alumni.view', 'projects.student_cv.view',
                'certificates.view', 'alumni_opportunities.view',
            ],
        ];

        DB::transaction(function () use ($rules): void {
            $unit = CoordinationUnit::query()->where('code', 'service_community_culture')->first();
            if (! $unit || ! CoordinationUnitPermissionRule::withTrashed()->where('unit_id', $unit->id)->exists()) {
                return;
            }

            foreach ($rules as $position => $permissions) {
                foreach ($permissions as $permission) {
                    // Preserve rules an administrator has already customized or disabled.
                    if (CoordinationUnitPermissionRule::withTrashed()
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
                        'scope_source' => CoordinationUnitPermissionRule::SCOPE_RESPONSIBILITY_PROJECTS,
                        'service_domain' => 'community_culture',
                        'scope_payload' => null,
                        'status' => CoordinationUnitPermissionRule::STATUS_ACTIVE,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Keep administrator decisions and existing alumni data intact.
    }
};
