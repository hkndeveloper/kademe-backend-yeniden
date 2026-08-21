<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PeriodAuditService
{
    public function run(): array
    {
        $configuredTables = collect(config('period_lifecycle.tables', []));
        $requiredClassifications = config('period_lifecycle.required_period_classifications', []);
        $databaseTables = $this->databaseTableNames();
        $periodTables = $databaseTables
            ->filter(fn (string $table) => Schema::hasColumn($table, 'period_id'))
            ->values();

        $tableAudits = $periodTables
            ->map(fn (string $table) => $this->auditPeriodTable(
                $table,
                $configuredTables->get($table, 'unclassified'),
                $requiredClassifications,
            ))
            ->sortBy('table')
            ->values();

        $multipleActiveProjects = DB::table('periods')
            ->select('project_id', DB::raw('COUNT(*) as active_period_count'))
            ->whereIn('status', ['active', 'closing'])
            ->groupBy('project_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('project_id')
            ->get()
            ->map(fn ($row) => [
                'project_id' => (int) $row->project_id,
                'active_period_count' => (int) $row->active_period_count,
            ])
            ->values()
            ->all();

        $completedWithoutArchive = DB::table('periods as periods')
            ->leftJoin('period_archives as archives', 'archives.period_id', '=', 'periods.id')
            ->where('periods.status', 'completed')
            ->whereNull('archives.id')
            ->orderBy('periods.id')
            ->get(['periods.id', 'periods.project_id', 'periods.name'])
            ->map(fn ($row) => [
                'period_id' => (int) $row->id,
                'project_id' => (int) $row->project_id,
                'name' => (string) $row->name,
            ])
            ->all();

        $duplicateArchiveVersions = DB::table('period_archives')
            ->select('period_id', 'archive_version', DB::raw('COUNT(*) as duplicate_count'))
            ->groupBy('period_id', 'archive_version')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('period_id')
            ->orderBy('archive_version')
            ->get()
            ->map(fn ($row) => [
                'period_id' => (int) $row->period_id,
                'archive_version' => (int) $row->archive_version,
                'duplicate_count' => (int) $row->duplicate_count,
            ])
            ->all();

        $pointerAudit = $this->auditCurrentPeriodPointers();
        $dateOverlaps = $this->dateOverlaps();
        $unexpectedPeriodTables = $periodTables->diff($configuredTables->keys())->values()->all();
        $configuredMissingTables = $configuredTables->keys()->diff($databaseTables)->values()->all();

        $anomalies = [
            'multiple_active_projects' => $multipleActiveProjects,
            'completed_without_archive' => $completedWithoutArchive,
            'duplicate_archive_versions' => $duplicateArchiveVersions,
            'required_period_nulls' => $tableAudits
                ->filter(fn (array $audit) => $audit['period_required'] && $audit['null_period_count'] > 0)
                ->map(fn (array $audit) => [
                    'table' => $audit['table'],
                    'count' => $audit['null_period_count'],
                ])->values()->all(),
            'orphan_period_references' => $tableAudits
                ->filter(fn (array $audit) => $audit['orphan_period_count'] > 0)
                ->map(fn (array $audit) => [
                    'table' => $audit['table'],
                    'count' => $audit['orphan_period_count'],
                    'sample_record_ids' => $audit['orphan_sample_record_ids'],
                ])->values()->all(),
            'project_period_mismatches' => $tableAudits
                ->filter(fn (array $audit) => $audit['project_period_mismatch_count'] > 0)
                ->map(fn (array $audit) => [
                    'table' => $audit['table'],
                    'count' => $audit['project_period_mismatch_count'],
                    'sample_record_ids' => $audit['mismatch_sample_record_ids'],
                ])->values()->all(),
            'unexpected_period_tables' => $unexpectedPeriodTables,
            'current_period_pointer' => $pointerAudit['anomalies'],
        ];

        $warnings = [
            'overlapping_period_dates' => $dateOverlaps,
            'configured_missing_tables' => $configuredMissingTables,
            'current_period_pointer' => $pointerAudit['warnings'],
            'nullable_period_rows' => $tableAudits
                ->filter(fn (array $audit) => ! $audit['period_required'] && $audit['null_period_count'] > 0)
                ->map(fn (array $audit) => [
                    'table' => $audit['table'],
                    'classification' => $audit['classification'],
                    'count' => $audit['null_period_count'],
                ])->values()->all(),
        ];

        $anomalyCount = $this->recursiveItemCount($anomalies);
        $warningCount = $this->recursiveItemCount($warnings);

        return [
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'connection' => DB::connection()->getName(),
                'driver' => DB::connection()->getDriverName(),
                'report_only' => true,
            ],
            'summary' => [
                'projects' => Schema::hasTable('projects') ? DB::table('projects')->count() : 0,
                'periods' => Schema::hasTable('periods') ? DB::table('periods')->count() : 0,
                'period_statuses' => DB::table('periods')
                    ->select('status', DB::raw('COUNT(*) as period_count'))
                    ->groupBy('status')
                    ->orderBy('status')
                    ->pluck('period_count', 'status')
                    ->map(fn ($count) => (int) $count)
                    ->all(),
                'period_archives' => Schema::hasTable('period_archives') ? DB::table('period_archives')->count() : 0,
                'period_scoped_tables' => $periodTables->count(),
                'anomaly_count' => $anomalyCount,
                'warning_count' => $warningCount,
                'healthy' => $anomalyCount === 0,
            ],
            'period_tables' => $tableAudits->all(),
            'anomalies' => $anomalies,
            'warnings' => $warnings,
            'closure_defaults' => config('period_lifecycle.closure_defaults', []),
        ];
    }

    private function databaseTableNames()
    {
        return collect(Schema::getTables())
            ->map(fn (array $table) => $table['name'] ?? null)
            ->filter(fn ($table) => is_string($table) && $table !== '')
            ->values();
    }

    private function auditPeriodTable(string $table, string $classification, array $requiredClassifications): array
    {
        $hasProjectId = Schema::hasColumn($table, 'project_id');
        $recordIdentifier = Schema::hasColumn($table, 'id') ? 'id' : 'period_id';
        $nullPeriodCount = DB::table($table)->whereNull('period_id')->count();

        $orphanQuery = DB::table("{$table} as records")
            ->leftJoin('periods as periods', 'periods.id', '=', 'records.period_id')
            ->whereNotNull('records.period_id')
            ->whereNull('periods.id');

        $mismatchQuery = $hasProjectId
            ? DB::table("{$table} as records")
                ->join('periods as periods', 'periods.id', '=', 'records.period_id')
                ->whereNotNull('records.period_id')
                ->whereColumn('records.project_id', '!=', 'periods.project_id')
            : null;

        return [
            'table' => $table,
            'classification' => $classification,
            'period_required' => in_array($classification, $requiredClassifications, true),
            'has_project_id' => $hasProjectId,
            'null_period_count' => $nullPeriodCount,
            'orphan_period_count' => (clone $orphanQuery)->count(),
            'orphan_sample_record_ids' => $this->sampleRecordIds($orphanQuery, $recordIdentifier),
            'project_period_mismatch_count' => $mismatchQuery ? (clone $mismatchQuery)->count() : 0,
            'mismatch_sample_record_ids' => $mismatchQuery ? $this->sampleRecordIds($mismatchQuery, $recordIdentifier) : [],
        ];
    }

    private function sampleRecordIds(Builder $query, string $recordIdentifier): array
    {
        return (clone $query)
            ->limit(10)
            ->pluck("records.{$recordIdentifier}")
            ->map(fn ($id) => is_numeric($id) ? (int) $id : (string) $id)
            ->values()
            ->all();
    }

    private function auditCurrentPeriodPointers(): array
    {
        if (! Schema::hasColumn('projects', 'current_period_id')) {
            return [
                'supported' => false,
                'anomalies' => [],
                'warnings' => [],
            ];
        }

        $invalidPointers = DB::table('projects as projects')
            ->leftJoin('periods as periods', 'periods.id', '=', 'projects.current_period_id')
            ->whereNotNull('projects.current_period_id')
            ->where(function ($query) {
                $query->whereNull('periods.id')
                    ->orWhereColumn('periods.project_id', '!=', 'projects.id')
                    ->orWhereNotIn('periods.status', ['active', 'closing']);
            })
            ->get(['projects.id as project_id', 'projects.current_period_id'])
            ->map(fn ($row) => [
                'type' => 'invalid_project_pointer',
                'project_id' => (int) $row->project_id,
                'period_id' => (int) $row->current_period_id,
            ]);

        $activeWithoutPointer = DB::table('periods as periods')
            ->leftJoin('projects as projects', function ($join) {
                $join->on('projects.id', '=', 'periods.project_id')
                    ->on('projects.current_period_id', '=', 'periods.id');
            })
            ->whereIn('periods.status', ['active', 'closing'])
            ->whereNull('projects.id')
            ->get(['periods.id as period_id', 'periods.project_id'])
            ->map(fn ($row) => [
                'type' => 'active_period_without_pointer',
                'project_id' => (int) $row->project_id,
                'period_id' => (int) $row->period_id,
            ]);

        $enforcePointer = (bool) config('period_lifecycle.enforce_current_period_pointer', false);

        return [
            'supported' => true,
            'anomalies' => $enforcePointer
                ? $invalidPointers->concat($activeWithoutPointer)->values()->all()
                : $invalidPointers->values()->all(),
            'warnings' => $enforcePointer ? [] : $activeWithoutPointer->values()->all(),
        ];
    }

    private function dateOverlaps(): array
    {
        return DB::table('periods as first_period')
            ->join('periods as second_period', function ($join) {
                $join->on('first_period.project_id', '=', 'second_period.project_id')
                    ->whereColumn('first_period.id', '<', 'second_period.id')
                    ->whereColumn('first_period.start_date', '<=', 'second_period.end_date')
                    ->whereColumn('second_period.start_date', '<=', 'first_period.end_date');
            })
            ->orderBy('first_period.project_id')
            ->orderBy('first_period.id')
            ->get([
                'first_period.project_id',
                'first_period.id as first_period_id',
                'first_period.name as first_period_name',
                'first_period.status as first_period_status',
                'second_period.id as second_period_id',
                'second_period.name as second_period_name',
                'second_period.status as second_period_status',
            ])
            ->map(fn ($row) => [
                'project_id' => (int) $row->project_id,
                'first_period_id' => (int) $row->first_period_id,
                'first_period_name' => (string) $row->first_period_name,
                'first_period_status' => (string) $row->first_period_status,
                'second_period_id' => (int) $row->second_period_id,
                'second_period_name' => (string) $row->second_period_name,
                'second_period_status' => (string) $row->second_period_status,
            ])
            ->all();
    }

    private function recursiveItemCount(array $groups): int
    {
        return collect($groups)->sum(function ($items) {
            if (! is_array($items)) {
                return 0;
            }

            return count($items);
        });
    }
}
