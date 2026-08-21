<?php

namespace App\Services;

use App\Models\Period;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PeriodArchiveBuilder
{
    public const SCHEMA_VERSION = 2;

    private const SNAPSHOT_TABLE_COLUMNS = [
        'participants' => ['id', 'user_id', 'project_id', 'period_id', 'status', 'graduation_status', 'graduation_note', 'credit', 'enrolled_at', 'graduated_at', 'updated_at'],
        'applications' => ['id', 'user_id', 'project_id', 'period_id', 'application_window_id', 'application_form_id', 'program_id', 'status', 'auto_rejected', 'auto_rejection_reason', 'rejection_reason', 'evaluation_note', 'interview_at', 'waitlist_order', 'updated_at'],
        'programs' => ['id', 'project_id', 'period_id', 'title', 'start_at', 'end_at', 'status', 'credit_deduction', 'application_quota', 'target_audience', 'updated_at'],
        'assignments' => ['id', 'project_id', 'period_id', 'title', 'due_date', 'created_by', 'updated_at'],
        'certificates' => ['id', 'user_id', 'project_id', 'period_id', 'type', 'verification_code', 'certificate_path', 'issued_at', 'updated_at'],
        'financial_transactions' => ['id', 'project_id', 'period_id', 'type', 'category', 'payee_name', 'amount', 'status', 'invoice_path', 'submitted_by', 'approved_by', 'submitted_at', 'approved_at', 'updated_at'],
        'kpd_appointments' => ['id', 'period_id', 'counselor_id', 'counselee_id', 'room_id', 'start_at', 'end_at', 'status', 'updated_at'],
        'kpd_reports' => ['id', 'period_id', 'user_id', 'file_path', 'created_by', 'updated_at'],
        'digital_bohca' => ['id', 'project_id', 'period_id', 'title', 'type', 'file_path', 'created_by', 'updated_at'],
        'volunteer_opportunities' => ['id', 'project_id', 'period_id', 'title', 'status', 'start_at', 'end_at', 'updated_at'],
        'project_modules' => ['id', 'project_id', 'period_id', 'title', 'is_active', 'application_open', 'updated_at'],
        'support_tickets' => ['id', 'project_id', 'period_id', 'category', 'status', 'assigned_to', 'updated_at'],
        'requests' => ['id', 'project_id', 'period_id', 'type', 'target_unit', 'target_user_id', 'status', 'response_file_path', 'updated_at'],
    ];

    public function __construct(private readonly CanonicalJson $canonicalJson) {}

    public function build(Period $period, array $closurePayload, array $readiness): array
    {
        $period->loadMissing('project');
        $domains = [];
        foreach (self::SNAPSHOT_TABLE_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'period_id')) {
                continue;
            }

            $available = array_values(array_filter($columns, fn (string $column) => Schema::hasColumn($table, $column)));
            $rows = DB::table($table)
                ->where('period_id', $period->id)
                ->orderBy('id')
                ->get($available)
                ->map(fn ($row) => $this->sanitizeRow((array) $row))
                ->all();
            $domains[$table] = $rows;
        }

        $submissions = DB::table('assignment_submissions as submissions')
            ->join('assignments', 'assignments.id', '=', 'submissions.assignment_id')
            ->where('assignments.period_id', $period->id)
            ->orderBy('submissions.id')
            ->get([
                'submissions.id', 'submissions.assignment_id', 'submissions.user_id', 'submissions.status',
                'submissions.reviewed_by', 'submissions.reviewed_at', 'submissions.updated_at',
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
        $domains['assignment_submissions'] = $submissions;

        $domains['attendances'] = $this->linkedPeriodRows('attendances', 'program_id', 'programs', $period);
        $domains['feedbacks'] = $this->linkedPeriodRows('feedbacks', 'program_id', 'programs', $period);
        $domains['volunteer_applications'] = $this->linkedPeriodRows(
            'volunteer_applications',
            'volunteer_opportunity_id',
            'volunteer_opportunities',
            $period,
        );
        $domains['project_module_enrollments'] = $this->linkedPeriodRows(
            'project_module_enrollments',
            'project_module_id',
            'project_modules',
            $period,
        );
        $domains['reward_awards'] = $this->linkedPeriodRows('reward_awards', 'participant_id', 'participants', $period);
        $domains['internships'] = $this->linkedPeriodRows('internships', 'participant_id', 'participants', $period);
        $domains['support_replies'] = $this->linkedPeriodRows('support_replies', 'ticket_id', 'support_tickets', $period);
        $domains['program_photos'] = $this->linkedPeriodRows('program_photos', 'program_id', 'programs', $period);
        $domains['eurodesk_partnerships'] = $this->linkedPeriodRows(
            'eurodesk_partnerships',
            'eurodesk_project_id',
            'eurodesk_projects',
            $period,
        );

        foreach (config('period_lifecycle.tables', []) as $table => $classification) {
            if (isset($domains[$table]) || in_array($table, ['period_archives', 'period_lifecycle_events'], true)) {
                continue;
            }
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'period_id')) {
                continue;
            }

            $columns = array_values(array_filter(
                ['id', 'project_id', 'period_id', 'status', 'created_at', 'updated_at'],
                fn (string $column) => Schema::hasColumn($table, $column),
            ));
            $query = DB::table($table)->where('period_id', $period->id);
            if (in_array('id', $columns, true)) {
                $query->orderBy('id');
            }
            $domains[$table] = $query->get($columns)->map(fn ($row) => (array) $row)->all();
        }

        $snapshot = [
            'schema_version' => self::SCHEMA_VERSION,
            'project' => [
                'id' => (int) $period->project_id,
                'name' => $period->project?->name,
                'slug' => $period->project?->slug,
                'type' => $period->project?->type,
            ],
            'period' => [
                'id' => (int) $period->id,
                'name' => $period->name,
                'start_date' => optional($period->start_date)?->toDateString(),
                'end_date' => optional($period->end_date)?->toDateString(),
                'credit_start_amount' => (int) $period->credit_start_amount,
                'credit_threshold' => (int) $period->credit_threshold,
                'lifecycle_version' => (int) $period->lifecycle_version,
            ],
            'domains' => $domains,
        ];

        $manifest = collect($domains)->map(function (array $rows) {
            return [
                'count' => count($rows),
                'ids' => collect($rows)->pluck('id')->filter()->map(fn ($id) => (int) $id)->values()->all(),
                'digest' => $this->canonicalJson->hash($rows),
            ];
        })->all();

        return [
            'snapshot' => $this->canonicalJson->normalize($snapshot),
            'manifest' => $this->canonicalJson->normalize($manifest),
            'summary' => $closurePayload['summary'],
            'warnings' => $closurePayload['warnings'],
            'counts' => $closurePayload['counts'],
            'readiness' => $readiness,
        ];
    }

    private function sanitizeRow(array $row): array
    {
        foreach ($row as $key => $value) {
            if (str_ends_with((string) $key, '_path') && $value) {
                $row[$key] = [
                    'path' => $value,
                    'path_digest' => hash('sha256', (string) $value),
                ];
            }
        }

        return $row;
    }

    private function linkedPeriodRows(
        string $table,
        string $foreignKey,
        string $periodTable,
        Period $period,
    ): array {
        if (! Schema::hasTable($table)
            || ! Schema::hasTable($periodTable)
            || ! Schema::hasColumn($table, $foreignKey)
            || ! Schema::hasColumn($periodTable, 'period_id')) {
            return [];
        }

        $columns = array_values(array_filter(
            ['id', $foreignKey, 'user_id', 'participant_id', 'status', 'is_valid', 'created_at', 'updated_at'],
            fn (string $column) => Schema::hasColumn($table, $column),
        ));
        $select = array_map(fn (string $column) => "records.{$column}", $columns);
        $query = DB::table("{$table} as records")
            ->join("{$periodTable} as parent", 'parent.id', '=', "records.{$foreignKey}")
            ->where('parent.period_id', $period->id);
        if (in_array('id', $columns, true)) {
            $query->orderBy('records.id');
        }

        return $query->get($select)->map(fn ($row) => (array) $row)->all();
    }
}
