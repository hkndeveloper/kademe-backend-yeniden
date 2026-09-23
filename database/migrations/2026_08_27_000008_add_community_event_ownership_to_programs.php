<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private array $permissions = [
        'programs.community_event.view',
        'programs.community_event.create',
        'programs.community_event.update',
        'programs.community_event.attendance.view',
        'programs.community_event.attendance.manage',
        'programs.community_event.attendance.export',
        'programs.logistics.update',
    ];

    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            if (! Schema::hasColumn('programs', 'program_kind')) {
                $table->string('program_kind', 32)->default('core_program')->after('period_id')->index();
            }
            if (! Schema::hasColumn('programs', 'managing_unit_id')) {
                $table->foreignId('managing_unit_id')
                    ->nullable()
                    ->after('program_kind')
                    ->constrained('coordination_units')
                    ->nullOnDelete();
                $table->index(['managing_unit_id', 'project_id'], 'programs_managing_unit_project_index');
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ($this->permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Role::findOrCreate('super_admin', 'web')->givePermissionTo($this->permissions);

        // Topluluk biriminin eski genis programs.view satirini silmeyiz. Yeni
        // kayit-bazli etkinlik gorunumune gecis icin pasif hale getiririz.
        if (Schema::hasTable('coordination_units') && Schema::hasTable('coordination_unit_permission_rules')) {
            $communityUnitIds = DB::table('coordination_units')
                ->where('code', 'service_community_culture')
                ->pluck('id');

            if ($communityUnitIds->isNotEmpty()) {
                DB::table('coordination_unit_permission_rules')
                    ->whereIn('unit_id', $communityUnitIds)
                    ->where('permission_name', 'programs.view')
                    ->where('status', 'active')
                    ->update(['status' => 'passive', 'updated_at' => now()]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $role = Role::query()->where('name', 'super_admin')->where('guard_name', 'web')->first();
        $role?->revokePermissionTo($this->permissions);

        Schema::table('programs', function (Blueprint $table) {
            if (Schema::hasColumn('programs', 'managing_unit_id')) {
                $table->dropForeign(['managing_unit_id']);
                $table->dropIndex('programs_managing_unit_project_index');
                $table->dropColumn('managing_unit_id');
            }
            if (Schema::hasColumn('programs', 'program_kind')) {
                $table->dropColumn('program_kind');
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
