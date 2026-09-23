<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coordination_units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('name', 180);
            $table->string('kind', 30);
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 30)->default('active');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('project_id', 'coordination_units_project_unique');
            $table->index(['kind', 'status']);
        });

        Schema::create('coordination_unit_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained('coordination_units')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('position', 30);
            $table->boolean('is_primary')->default(false);
            $table->string('status', 30)->default('active');
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['unit_id', 'position', 'status']);
        });

        Schema::create('coordination_unit_project_responsibilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained('coordination_units')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('service_domain', 80);
            $table->boolean('is_primary')->default(true);
            $table->string('status', 30)->default('active');
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['unit_id', 'service_domain', 'status'], 'coord_unit_responsibility_domain_idx');
            $table->index(['project_id', 'service_domain', 'status'], 'coord_project_responsibility_domain_idx');
        });

        Schema::create('coordination_unit_permission_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained('coordination_units')->cascadeOnDelete();
            $table->string('position', 30);
            $table->string('permission_name', 150);
            $table->string('effect', 10)->default('allow');
            $table->string('scope_source', 50);
            $table->string('service_domain', 80)->nullable();
            $table->json('scope_payload')->nullable();
            $table->string('status', 30)->default('active');
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['unit_id', 'position', 'status'], 'coord_unit_permission_position_idx');
            $table->index(['permission_name', 'status'], 'coord_unit_permission_name_idx');
        });

        $this->createActiveUniquenessIndexes();
    }

    public function down(): void
    {
        Schema::dropIfExists('coordination_unit_permission_rules');
        Schema::dropIfExists('coordination_unit_project_responsibilities');
        Schema::dropIfExists('coordination_unit_memberships');
        Schema::dropIfExists('coordination_units');
    }

    private function createActiveUniquenessIndexes(): void
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX coordination_unit_memberships_active_unique
            ON coordination_unit_memberships (unit_id, user_id)
            WHERE status = 'active' AND deleted_at IS NULL
        SQL);

        $primaryPredicate = $driver === 'pgsql' ? 'is_primary = true' : 'is_primary = 1';
        DB::statement(<<<SQL
            CREATE UNIQUE INDEX coordination_unit_memberships_primary_unique
            ON coordination_unit_memberships (user_id)
            WHERE {$primaryPredicate} AND status = 'active' AND deleted_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX coordination_unit_responsibilities_active_unique
            ON coordination_unit_project_responsibilities (unit_id, project_id, service_domain)
            WHERE status = 'active' AND deleted_at IS NULL
        SQL);

        DB::statement(<<<SQL
            CREATE UNIQUE INDEX coordination_unit_responsibilities_primary_unique
            ON coordination_unit_project_responsibilities (project_id, service_domain)
            WHERE {$primaryPredicate} AND status = 'active' AND deleted_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX coordination_unit_permission_rules_active_unique
            ON coordination_unit_permission_rules (unit_id, position, permission_name)
            WHERE status = 'active' AND deleted_at IS NULL
        SQL);
    }
};
