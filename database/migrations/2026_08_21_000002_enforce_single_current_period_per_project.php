<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'periods_one_current_status_per_project';

    private const MYSQL_GUARD_COLUMN = 'current_status_project_guard';

    public function up(): void
    {
        $duplicates = DB::table('periods')
            ->whereIn('status', ['active', 'closing'])
            ->select('project_id', DB::raw('COUNT(*) as current_period_count'))
            ->groupBy('project_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('project_id')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $projectIds = $duplicates->pluck('project_id')->map(fn ($id) => (int) $id)->implode(', ');

            throw new RuntimeException(
                "Birden fazla active/closing donemi olan projeler temizlenmeden tek guncel donem indeksi eklenemez: {$projectIds}",
            );
        }

        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX '.self::INDEX." ON periods (project_id) WHERE status IN ('active', 'closing')",
            );

            return;
        }

        if ($driver === 'sqlsrv') {
            DB::statement(
                'CREATE UNIQUE INDEX '.self::INDEX." ON periods (project_id) WHERE status IN ('active', 'closing')",
            );

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            Schema::table('periods', function (Blueprint $table) {
                $table->unsignedBigInteger(self::MYSQL_GUARD_COLUMN)
                    ->storedAs("CASE WHEN status IN ('active', 'closing') THEN project_id ELSE NULL END");
                $table->unique(self::MYSQL_GUARD_COLUMN, self::INDEX);
            });

            return;
        }

        throw new RuntimeException("Tek guncel donem indeksi icin desteklenmeyen veritabani surucusu: {$driver}");
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEX);

            return;
        }

        if ($driver === 'sqlsrv') {
            DB::statement('DROP INDEX '.self::INDEX.' ON periods');

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            Schema::table('periods', function (Blueprint $table) {
                $table->dropUnique(self::INDEX);
                $table->dropColumn(self::MYSQL_GUARD_COLUMN);
            });

            return;
        }

        throw new RuntimeException("Tek guncel donem indeksi rollback'i icin desteklenmeyen veritabani surucusu: {$driver}");
    }
};
