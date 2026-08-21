<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CRITICAL_TABLES = [
        'application_windows',
        'applications',
        'assignments',
        'credit_logs',
        'participants',
        'period_archives',
        'period_lifecycle_events',
        'programs',
        'waitlist_invitations',
    ];

    private const LEGACY_NULLABLE_TABLES = [
        'applications',
        'programs',
    ];

    public function up(): void
    {
        $this->assertCleanData();

        foreach (self::LEGACY_NULLABLE_TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['period_id']);
            });
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('period_id')->nullable(false)->change();
                $table->foreign('period_id')->references('id')->on('periods')->cascadeOnDelete();
            });
        }

        foreach (self::CRITICAL_TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $foreign = $table->foreign(
                    ['period_id', 'project_id'],
                    "{$tableName}_period_project_foreign",
                )->references(['id', 'project_id'])->on('periods');

                if (in_array($tableName, ['period_archives', 'period_lifecycle_events'], true)) {
                    $foreign->restrictOnDelete();
                } else {
                    $foreign->cascadeOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::CRITICAL_TABLES) as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropForeign("{$tableName}_period_project_foreign");
            });
        }

        foreach (self::LEGACY_NULLABLE_TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['period_id']);
            });
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('period_id')->nullable()->change();
                $table->foreign('period_id')->references('id')->on('periods')->nullOnDelete();
            });
        }
    }

    private function assertCleanData(): void
    {
        foreach (self::CRITICAL_TABLES as $tableName) {
            if (! Schema::hasTable($tableName)
                || ! Schema::hasColumns($tableName, ['project_id', 'period_id'])) {
                throw new RuntimeException("{$tableName} tablosu project_id/period_id butunluk migration'i icin hazir degil.");
            }

            $nullCount = DB::table($tableName)->whereNull('period_id')->count();
            if ($nullCount > 0) {
                throw new RuntimeException("{$tableName} tablosunda {$nullCount} zorunlu period_id null kaydi var; migration durduruldu.");
            }

            $orphanCount = DB::table("{$tableName} as records")
                ->leftJoin('periods', 'periods.id', '=', 'records.period_id')
                ->whereNull('periods.id')
                ->count();
            if ($orphanCount > 0) {
                throw new RuntimeException("{$tableName} tablosunda {$orphanCount} yetim period_id kaydi var; migration durduruldu.");
            }

            $mismatchCount = DB::table("{$tableName} as records")
                ->join('periods', 'periods.id', '=', 'records.period_id')
                ->whereColumn('records.project_id', '!=', 'periods.project_id')
                ->count();
            if ($mismatchCount > 0) {
                throw new RuntimeException("{$tableName} tablosunda {$mismatchCount} proje/donem uyusmazligi var; migration durduruldu.");
            }
        }
    }
};
