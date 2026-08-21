<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CURRENT_STATUSES = ['active', 'passive', 'completed'];

    private const LIFECYCLE_STATUSES = ['planned', 'active', 'closing', 'completed', 'cancelled', 'passive'];

    public function up(): void
    {
        $this->setPeriodStatuses(self::LIFECYCLE_STATUSES, 'active');

        Schema::table('periods', function (Blueprint $table) {
            $table->unsignedInteger('lifecycle_version')->default(0)->after('status');
            $table->timestampTz('activated_at')->nullable()->after('lifecycle_version');
            $table->foreignId('activated_by')->nullable()->after('activated_at')->constrained('users')->nullOnDelete();
            $table->timestampTz('closing_started_at')->nullable()->after('activated_by');
            $table->foreignId('closing_started_by')->nullable()->after('closing_started_at')->constrained('users')->nullOnDelete();
            $table->timestampTz('completed_at')->nullable()->after('closing_started_by');
            $table->foreignId('completed_by')->nullable()->after('completed_at')->constrained('users')->nullOnDelete();
            $table->timestampTz('reopened_at')->nullable()->after('completed_by');
            $table->foreignId('reopened_by')->nullable()->after('reopened_at')->constrained('users')->nullOnDelete();
            $table->timestampTz('cancelled_at')->nullable()->after('reopened_by');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->unique(['id', 'project_id'], 'periods_id_project_unique');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('current_period_id')
                ->nullable()
                ->after('id')
                ->constrained('periods')
                ->nullOnDelete();
            $table->unique('current_period_id', 'projects_current_period_unique');
        });

        Schema::create('period_lifecycle_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('periods')->restrictOnDelete();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('event_type', 50);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['period_id', 'created_at']);
            $table->index(['project_id', 'event_type']);
        });
    }

    public function down(): void
    {
        $newStatusCount = DB::table('periods')
            ->whereNotIn('status', self::CURRENT_STATUSES)
            ->count();

        if ($newStatusCount > 0) {
            throw new RuntimeException('Yeni yasam dongusu durumlari kullaniliyor; veri karari olmadan migration geri alinamaz.');
        }

        Schema::dropIfExists('period_lifecycle_events');

        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique('projects_current_period_unique');
            $table->dropConstrainedForeignId('current_period_id');
        });

        Schema::table('periods', function (Blueprint $table) {
            $table->dropUnique('periods_id_project_unique');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn('cancelled_at');
            $table->dropConstrainedForeignId('reopened_by');
            $table->dropColumn('reopened_at');
            $table->dropConstrainedForeignId('completed_by');
            $table->dropColumn('completed_at');
            $table->dropConstrainedForeignId('closing_started_by');
            $table->dropColumn('closing_started_at');
            $table->dropConstrainedForeignId('activated_by');
            $table->dropColumn(['activated_at', 'lifecycle_version']);
        });

        $this->setPeriodStatuses(self::CURRENT_STATUSES, 'active');
    }

    private function setPeriodStatuses(array $statuses, string $default): void
    {
        $driver = DB::connection()->getDriverName();
        $quotedStatuses = collect($statuses)
            ->map(fn (string $status) => "'".str_replace("'", "''", $status)."'")
            ->implode(', ');

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE periods DROP CONSTRAINT IF EXISTS periods_status_check');
            DB::statement("ALTER TABLE periods ALTER COLUMN status SET DEFAULT '{$default}'");
            DB::statement("ALTER TABLE periods ADD CONSTRAINT periods_status_check CHECK (status IN ({$quotedStatuses}))");

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE periods MODIFY status ENUM({$quotedStatuses}) NOT NULL DEFAULT '{$default}'");

            return;
        }

        Schema::table('periods', function (Blueprint $table) use ($statuses, $default) {
            $table->enum('status', $statuses)->default($default)->change();
        });
    }
};
