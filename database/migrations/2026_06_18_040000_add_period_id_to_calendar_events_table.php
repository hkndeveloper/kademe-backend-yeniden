<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            if (! Schema::hasColumn('calendar_events', 'period_id')) {
                $table->foreignId('period_id')
                    ->nullable()
                    ->after('project_id')
                    ->constrained('periods')
                    ->nullOnDelete();
                $table->index(['project_id', 'period_id']);
            }
        });

        if (! Schema::hasColumn('calendar_events', 'period_id')) {
            return;
        }

        $programPeriods = DB::table('programs')
            ->whereNotNull('period_id')
            ->pluck('period_id', 'id');

        foreach ($programPeriods as $programId => $periodId) {
            DB::table('calendar_events')
                ->where('program_id', $programId)
                ->update(['period_id' => $periodId]);
        }
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            if (Schema::hasColumn('calendar_events', 'period_id')) {
                $table->dropConstrainedForeignId('period_id');
            }
        });
    }
};
