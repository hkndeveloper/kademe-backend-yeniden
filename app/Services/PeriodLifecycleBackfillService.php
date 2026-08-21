<?php

namespace App\Services;

use App\Models\Period;
use App\Models\PeriodLifecycleEvent;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PeriodLifecycleBackfillService
{
    private const MANUAL_TARGETS = ['planned', 'completed', 'cancelled'];

    public function __construct(
        private readonly PeriodAuditService $auditService,
        private readonly PeriodArchiveService $archiveService,
    ) {}

    public function execute(bool $apply = false, array $mapping = []): array
    {
        if (! $apply) {
            return $this->buildPlan($mapping, false);
        }

        return DB::transaction(function () use ($mapping) {
            // The plan and its writes are based on the same locked data set.
            Project::query()->orderBy('id')->lockForUpdate()->get(['id']);
            Period::query()->orderBy('id')->lockForUpdate()->get(['id']);

            $plan = $this->buildPlan($mapping, true);
            if ($plan['summary']['blocker_count'] > 0) {
                return $plan;
            }

            foreach ($plan['changes']['passive_statuses'] as $change) {
                $period = Period::query()->findOrFail($change['period_id']);
                $attributes = [
                    'status' => $change['target_status'],
                    'lifecycle_version' => ((int) $period->lifecycle_version) + 1,
                ];

                if ($change['target_status'] === 'completed') {
                    $attributes['completed_at'] = now();
                } elseif ($change['target_status'] === 'cancelled') {
                    $attributes['cancelled_at'] = now();
                }

                $period->forceFill($attributes)->save();
                PeriodLifecycleEvent::query()->create([
                    'period_id' => $period->id,
                    'project_id' => $period->project_id,
                    'event_type' => 'legacy_status_backfilled',
                    'from_status' => 'passive',
                    'to_status' => $change['target_status'],
                    'reason' => $change['reason'],
                    'metadata_json' => [
                        'source' => 'periods:backfill-lifecycle',
                        'legacy_backfill' => true,
                    ],
                ]);
            }

            foreach ($plan['changes']['current_period_pointers'] as $change) {
                Project::query()->whereKey($change['project_id'])->update([
                    'current_period_id' => $change['period_id'],
                    'updated_at' => now(),
                ]);
                PeriodLifecycleEvent::query()->create([
                    'period_id' => $change['period_id'],
                    'project_id' => $change['project_id'],
                    'event_type' => 'current_pointer_backfilled',
                    'from_status' => $change['period_status'],
                    'to_status' => $change['period_status'],
                    'reason' => 'Legacy guncel donem baglantisi kanitlanabilir tek adaydan dolduruldu.',
                    'metadata_json' => [
                        'source' => 'periods:backfill-lifecycle',
                        'legacy_backfill' => true,
                    ],
                ]);
            }

            foreach ($plan['changes']['legacy_archives'] as $change) {
                $period = Period::query()->findOrFail($change['period_id']);
                $this->archiveService->createLegacyBackfillVersion($period, $change['reason']);
            }

            $after = $this->buildPlan($mapping, false);
            $plan['meta']['applied'] = true;
            $plan['summary']['applied_change_count'] = $plan['summary']['proposed_change_count'];
            $plan['verification'] = [
                'remaining_change_count' => $after['summary']['proposed_change_count'],
                'remaining_blocker_count' => $after['summary']['blocker_count'],
                'idempotent' => $after['summary']['proposed_change_count'] === 0,
                'healthy' => $after['audit']['healthy'],
            ];

            return $plan;
        }, 3);
    }

    public function buildPlan(array $mapping = [], bool $forApply = false): array
    {
        $mapping = $this->normalizeMapping($mapping);
        $audit = $this->auditService->run();
        $blockers = $this->auditBlockers($audit);
        $passiveChanges = [];

        $passivePeriods = Period::query()
            ->where('status', 'passive')
            ->orderBy('id')
            ->get(['id', 'project_id', 'name', 'end_date', 'lifecycle_version']);

        foreach ($passivePeriods as $period) {
            $manual = $mapping['passive_periods'][(string) $period->id] ?? null;
            $isHistoricallyPast = $period->end_date !== null
                && Carbon::parse($period->end_date)->endOfDay()->isPast();

            if ($manual === null && $isHistoricallyPast) {
                $blockers[] = [
                    'type' => 'historical_passive_requires_mapping',
                    'period_id' => (int) $period->id,
                    'project_id' => (int) $period->project_id,
                    'name' => $period->name,
                    'end_date' => optional($period->end_date)->toDateString(),
                ];

                continue;
            }

            $target = $manual['target_status'] ?? 'planned';
            if (! in_array($target, self::MANUAL_TARGETS, true)) {
                $blockers[] = [
                    'type' => 'invalid_passive_target',
                    'period_id' => (int) $period->id,
                    'target_status' => $target,
                    'allowed' => self::MANUAL_TARGETS,
                ];

                continue;
            }

            $manualReason = trim((string) ($manual['reason'] ?? ''));
            if ($manual !== null && $target !== 'planned' && $manualReason === '') {
                $blockers[] = [
                    'type' => 'manual_mapping_reason_required',
                    'period_id' => (int) $period->id,
                    'target_status' => $target,
                ];

                continue;
            }
            $reason = $manualReason !== ''
                ? $manualReason
                : 'Legacy passive durum planlanan doneme donusturuldu.';

            $passiveChanges[] = [
                'period_id' => (int) $period->id,
                'project_id' => (int) $period->project_id,
                'from_status' => 'passive',
                'target_status' => $target,
                'reason' => $reason,
                'manual' => $manual !== null,
            ];
        }

        foreach (array_keys($mapping['passive_periods']) as $periodId) {
            if (! $passivePeriods->contains('id', (int) $periodId)) {
                $mappedPeriod = Period::query()->find((int) $periodId, ['id', 'status']);
                $expectedStatus = $mapping['passive_periods'][$periodId]['target_status'];
                $alreadyApplied = $mappedPeriod
                    && $mappedPeriod->status === $expectedStatus
                    && PeriodLifecycleEvent::query()
                        ->where('period_id', $mappedPeriod->id)
                        ->where('event_type', 'legacy_status_backfilled')
                        ->where('to_status', $expectedStatus)
                        ->exists();
                if ($alreadyApplied) {
                    continue;
                }

                $blockers[] = [
                    'type' => 'passive_mapping_target_not_found',
                    'period_id' => (int) $periodId,
                ];
            }
        }

        $pointerChanges = $this->pointerChanges($blockers);
        $archiveChanges = $this->archiveChanges($audit, $mapping, $passiveChanges, $blockers);
        $proposedCount = count($passiveChanges) + count($pointerChanges) + count($archiveChanges);

        return [
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'mode' => $forApply ? 'apply' : 'dry-run',
                'applied' => false,
                'transactional' => true,
            ],
            'summary' => [
                'proposed_change_count' => $proposedCount,
                'applied_change_count' => 0,
                'passive_status_change_count' => count($passiveChanges),
                'pointer_change_count' => count($pointerChanges),
                'legacy_archive_change_count' => count($archiveChanges),
                'blocker_count' => count($blockers),
                'can_apply' => count($blockers) === 0,
            ],
            'changes' => [
                'passive_statuses' => $passiveChanges,
                'current_period_pointers' => $pointerChanges,
                'legacy_archives' => $archiveChanges,
            ],
            'blockers' => array_values($blockers),
            'audit' => [
                'healthy' => $audit['summary']['healthy'],
                'anomaly_count' => $audit['summary']['anomaly_count'],
                'warning_count' => $audit['summary']['warning_count'],
            ],
        ];
    }

    private function normalizeMapping(array $mapping): array
    {
        $passiveMappings = [];
        foreach (($mapping['passive_periods'] ?? []) as $periodId => $value) {
            $passiveMappings[(string) ((int) $periodId)] = is_string($value)
                ? ['target_status' => $value, 'reason' => null]
                : [
                    'target_status' => (string) ($value['target_status'] ?? ''),
                    'reason' => $value['reason'] ?? null,
                ];
        }

        return [
            'passive_periods' => $passiveMappings,
            'legacy_archive_period_ids' => collect($mapping['legacy_archive_period_ids'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all(),
            'legacy_archive_reason' => trim((string) ($mapping['legacy_archive_reason'] ?? 'Onayli legacy donem arsiv backfill islemi.')),
        ];
    }

    private function auditBlockers(array $audit): array
    {
        $ignored = ['completed_without_archive'];
        $blockers = [];
        foreach ($audit['anomalies'] as $group => $items) {
            if (in_array($group, $ignored, true)) {
                continue;
            }
            foreach ($items as $item) {
                $blockers[] = ['type' => $group, 'detail' => $item];
            }
        }

        return $blockers;
    }

    private function pointerChanges(array &$blockers): array
    {
        $changes = [];
        $projects = Project::query()->orderBy('id')->get(['id', 'current_period_id']);

        foreach ($projects as $project) {
            $candidates = Period::query()
                ->where('project_id', $project->id)
                ->whereIn('status', ['active', 'closing'])
                ->orderBy('id')
                ->get(['id', 'status']);

            if ($candidates->count() > 1) {
                $blockers[] = [
                    'type' => 'multiple_current_period_candidates',
                    'project_id' => (int) $project->id,
                    'period_ids' => $candidates->pluck('id')->map(fn ($id) => (int) $id)->all(),
                ];

                continue;
            }

            if ($project->current_period_id === null && $candidates->count() === 1) {
                $candidate = $candidates->first();
                $changes[] = [
                    'project_id' => (int) $project->id,
                    'period_id' => (int) $candidate->id,
                    'period_status' => $candidate->status,
                ];
            }
        }

        return $changes;
    }

    private function archiveChanges(array $audit, array $mapping, array $passiveChanges, array &$blockers): array
    {
        $approved = $mapping['legacy_archive_period_ids'];
        $completedIds = collect($audit['anomalies']['completed_without_archive'])->pluck('period_id')->map(fn ($id) => (int) $id);
        $mappedCompletedIds = collect($passiveChanges)
            ->where('target_status', 'completed')
            ->pluck('period_id')
            ->map(fn ($id) => (int) $id);
        $requiresArchive = $completedIds->merge($mappedCompletedIds)->unique()->values();

        foreach ($approved as $periodId) {
            if (! $requiresArchive->contains($periodId)) {
                $alreadyArchived = DB::table('period_archives')
                    ->where('period_id', $periodId)
                    ->where('override_reason', 'legacy_backfill')
                    ->exists();
                if ($alreadyArchived) {
                    continue;
                }

                $blockers[] = [
                    'type' => 'legacy_archive_approval_not_applicable',
                    'period_id' => $periodId,
                ];
            }
        }

        foreach ($requiresArchive as $periodId) {
            if (! in_array($periodId, $approved, true)) {
                $blockers[] = [
                    'type' => 'completed_period_requires_archive_approval',
                    'period_id' => $periodId,
                ];
            }
        }

        return $requiresArchive
            ->filter(fn (int $periodId) => in_array($periodId, $approved, true))
            ->map(fn (int $periodId) => [
                'period_id' => $periodId,
                'reason' => $mapping['legacy_archive_reason'],
            ])
            ->values()
            ->all();
    }
}
