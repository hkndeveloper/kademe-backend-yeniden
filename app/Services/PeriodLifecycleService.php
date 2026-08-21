<?php

namespace App\Services;

use App\Exceptions\PeriodLifecycleException;
use App\Models\ApplicationWindow;
use App\Models\Period;
use App\Models\PeriodLifecycleEvent;
use App\Models\Project;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

class PeriodLifecycleService
{
    public const PLANNED = 'planned';

    public const ACTIVE = 'active';

    public const CLOSING = 'closing';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const LEGACY_PASSIVE = 'passive';

    public function __construct(private readonly PeriodLifecycleMonitor $monitor) {}

    public static function isArchiveStatus(?string $status): bool
    {
        return in_array($status, [self::COMPLETED, self::CANCELLED], true);
    }

    public static function writeCapabilitiesForStatus(?string $status): array
    {
        return [
            'configure_period' => in_array($status, [self::PLANNED, self::LEGACY_PASSIVE, self::ACTIVE], true),
            'create_operations' => $status === self::ACTIVE,
            'resolve_operations' => in_array($status, [self::ACTIVE, self::CLOSING], true),
            'archive_correction_required' => self::isArchiveStatus($status),
        ];
    }

    public function createPlanned(array $attributes, ?User $actor = null): Period
    {
        return DB::transaction(function () use ($attributes, $actor) {
            $project = Project::query()->lockForUpdate()->findOrFail($attributes['project_id']);
            $requestedStatus = $attributes['status'] ?? self::PLANNED;
            unset($attributes['status']);

            $period = Period::query()->create([
                ...$attributes,
                'project_id' => $project->id,
                'status' => self::PLANNED,
                'lifecycle_version' => 0,
            ]);

            $this->recordEvent(
                $period,
                'created',
                null,
                self::PLANNED,
                $actor,
                null,
                ['requested_status' => $requestedStatus],
            );

            return $period->fresh(['project', 'latestArchive']);
        });
    }

    public function activate(int $periodId, ?User $actor = null, ?string $reason = null): Period
    {
        return $this->withLockedPeriod($periodId, 'activate', $actor, function (Project $project, Period $period) use ($actor, $reason) {
            $this->assertStatus($period, [self::PLANNED, self::LEGACY_PASSIVE], 'Yalniz planlanan bir donem aktif edilebilir.');
            $this->assertNoCurrentPeriodConflict($project, $period);

            $fromStatus = $period->status;
            $now = now();

            $period->forceFill([
                'status' => self::ACTIVE,
                'lifecycle_version' => ((int) $period->lifecycle_version) + 1,
                'activated_at' => $now,
                'activated_by' => $actor?->id,
                'closing_started_at' => null,
                'closing_started_by' => null,
                'completed_at' => null,
                'completed_by' => null,
                'cancelled_at' => null,
                'cancelled_by' => null,
            ])->save();

            $project->forceFill(['current_period_id' => $period->id])->save();

            $this->recordEvent($period, 'activated', $fromStatus, self::ACTIVE, $actor, $reason);

            return $period->fresh(['project', 'latestArchive']);
        });
    }

    public function updateDetails(int $periodId, array $attributes, ?User $actor = null): Period
    {
        return $this->withLockedPeriod($periodId, 'update_details', $actor, function (Project $project, Period $period) use ($attributes, $actor) {
            $this->assertStatus(
                $period,
                [self::PLANNED, self::LEGACY_PASSIVE, self::ACTIVE],
                'Kapanis hazirligindaki, tamamlanmis veya iptal edilmis donem normal duzenleme ile degistirilemez.',
            );

            unset($attributes['status'], $attributes['project_id'], $attributes['lifecycle_version']);
            $period->fill($attributes);
            $changedFields = array_keys($period->getDirty());

            if ($period->isDirty()) {
                $period->lifecycle_version = ((int) $period->lifecycle_version) + 1;
                $period->save();
                $this->recordEvent(
                    $period,
                    'updated',
                    $period->status,
                    $period->status,
                    $actor,
                    null,
                    ['changed_fields' => $changedFields],
                );
            }

            return $period->fresh(['project', 'latestArchive']);
        });
    }

    public function startClosing(int $periodId, ?User $actor = null, ?string $reason = null): Period
    {
        return $this->withLockedPeriod($periodId, 'start_closing', $actor, function (Project $project, Period $period) use ($actor, $reason) {
            $this->assertStatus($period, [self::ACTIVE], 'Yalniz aktif donem kapanisa alinabilir.');
            $this->adoptLegacyCurrentPointer($project, $period);

            if ((int) $project->current_period_id !== (int) $period->id) {
                throw PeriodLifecycleException::conflict('Bu donem projenin guncel donemi degil.');
            }

            $period->forceFill([
                'status' => self::CLOSING,
                'lifecycle_version' => ((int) $period->lifecycle_version) + 1,
                'closing_started_at' => now(),
                'closing_started_by' => $actor?->id,
            ])->save();

            $closedWindowCount = $this->closeApplicationIntake($project, $period, $actor);

            $this->recordEvent(
                $period,
                'closing_started',
                self::ACTIVE,
                self::CLOSING,
                $actor,
                $reason,
                ['closed_application_windows' => $closedWindowCount],
            );

            return $period->fresh(['project', 'latestArchive']);
        });
    }

    public function cancelClosing(int $periodId, ?User $actor = null, ?string $reason = null): Period
    {
        return $this->withLockedPeriod($periodId, 'cancel_closing', $actor, function (Project $project, Period $period) use ($actor, $reason) {
            $this->assertStatus($period, [self::CLOSING], 'Yalniz kapanis hazirligindaki donem yeniden aktif edilebilir.');

            if ($project->current_period_id !== null && (int) $project->current_period_id !== (int) $period->id) {
                throw PeriodLifecycleException::conflict('Projenin guncel donem baglantisi baska bir donemi gosteriyor.');
            }

            $project->forceFill(['current_period_id' => $period->id])->save();
            $period->forceFill([
                'status' => self::ACTIVE,
                'lifecycle_version' => ((int) $period->lifecycle_version) + 1,
                'closing_started_at' => null,
                'closing_started_by' => null,
            ])->save();

            $this->recordEvent($period, 'closing_cancelled', self::CLOSING, self::ACTIVE, $actor, $reason);

            return $period->fresh(['project', 'latestArchive']);
        });
    }

    /**
     * The archive factory runs inside the same locked transaction and must
     * return the newly created archive model/value.
     */
    public function complete(
        int $periodId,
        ?User $actor,
        ?string $reason,
        Closure $archiveFactory,
    ): array {
        return $this->withLockedPeriod($periodId, 'complete', $actor, function (Project $project, Period $period) use ($actor, $reason, $archiveFactory) {
            $this->assertStatus(
                $period,
                [self::ACTIVE, self::CLOSING],
                'Yalniz aktif veya kapanis hazirligindaki donem tamamlanabilir.',
            );
            $this->adoptLegacyCurrentPointer($project, $period);

            if ((int) $project->current_period_id !== (int) $period->id) {
                throw PeriodLifecycleException::conflict('Bu donem projenin guncel donemi degil.');
            }

            $fromStatus = $period->status;
            if ($fromStatus === self::ACTIVE) {
                $period->forceFill([
                    'status' => self::CLOSING,
                    'lifecycle_version' => ((int) $period->lifecycle_version) + 1,
                    'closing_started_at' => now(),
                    'closing_started_by' => $actor?->id,
                ])->save();
                $closedWindowCount = $this->closeApplicationIntake($project, $period, $actor);
                $this->recordEvent(
                    $period,
                    'closing_started',
                    self::ACTIVE,
                    self::CLOSING,
                    $actor,
                    'Mevcut panel tamamlama akisi kapanisi otomatik baslatti.',
                    [
                        'compatibility_transition' => true,
                        'closed_application_windows' => $closedWindowCount,
                    ],
                );
            } else {
                // A legacy/manual change may have left the intake flag open.
                // Completion repairs it inside the same locked transaction.
                $this->closeApplicationIntake($project, $period, $actor);
            }

            $archive = $archiveFactory($period->fresh());
            $now = now();

            $period->forceFill([
                'status' => self::COMPLETED,
                'lifecycle_version' => ((int) $period->lifecycle_version) + 1,
                'completed_at' => $now,
                'completed_by' => $actor?->id,
            ])->save();
            $project->forceFill(['current_period_id' => null])->save();

            $this->recordEvent(
                $period,
                'completed',
                self::CLOSING,
                self::COMPLETED,
                $actor,
                $reason,
                ['archive_id' => $archive?->id, 'original_status' => $fromStatus],
            );

            return [
                'period' => $period->fresh(['project', 'latestArchive']),
                'archive' => $archive,
            ];
        });
    }

    public function reopen(
        int $periodId,
        string $targetStatus,
        User $actor,
        string $reason,
    ): Period {
        if (! in_array($targetStatus, [self::PLANNED, self::ACTIVE], true)) {
            throw PeriodLifecycleException::invalidTransition('Donem yalniz planlanan veya aktif duruma yeniden acilabilir.');
        }

        return $this->withLockedPeriod($periodId, 'reopen_'.$targetStatus, $actor, function (Project $project, Period $period) use ($targetStatus, $actor, $reason) {
            $this->assertStatus($period, [self::COMPLETED], 'Yalniz tamamlanmis donem yeniden acilabilir.');

            if ($targetStatus === self::ACTIVE) {
                $this->assertNoCurrentPeriodConflict($project, $period);
                $project->forceFill(['current_period_id' => $period->id])->save();
            }

            $now = now();
            $period->forceFill([
                'status' => $targetStatus,
                'lifecycle_version' => ((int) $period->lifecycle_version) + 1,
                'reopened_at' => $now,
                'reopened_by' => $actor->id,
                'completed_at' => null,
                'completed_by' => null,
                'closing_started_at' => null,
                'closing_started_by' => null,
            ])->save();

            $this->recordEvent($period, 'reopened', self::COMPLETED, $targetStatus, $actor, $reason);

            return $period->fresh(['project', 'latestArchive']);
        });
    }

    public function cancel(int $periodId, User $actor, string $reason): Period
    {
        return $this->withLockedPeriod($periodId, 'cancel', $actor, function (Project $project, Period $period) use ($actor, $reason) {
            $this->assertStatus(
                $period,
                [self::PLANNED, self::LEGACY_PASSIVE],
                'Yalniz planlanan bir donem iptal edilebilir.',
            );
            $fromStatus = $period->status;

            $period->forceFill([
                'status' => self::CANCELLED,
                'lifecycle_version' => ((int) $period->lifecycle_version) + 1,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
            ])->save();

            if ((int) $project->current_period_id === (int) $period->id) {
                $project->forceFill(['current_period_id' => null])->save();
            }

            $this->recordEvent($period, 'cancelled', $fromStatus, self::CANCELLED, $actor, $reason);

            return $period->fresh(['project', 'latestArchive']);
        });
    }

    private function withLockedPeriod(int $periodId, string $operation, ?User $actor, Closure $callback): mixed
    {
        try {
            return DB::transaction(function () use ($periodId, $callback) {
                $projectId = Period::query()->whereKey($periodId)->value('project_id');
                if ($projectId === null) {
                    return Period::query()->findOrFail($periodId);
                }

                $project = Project::query()->lockForUpdate()->findOrFail($projectId);
                $period = Period::query()
                    ->whereKey($periodId)
                    ->where('project_id', $project->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                return $callback($project, $period);
            }, 3);
        } catch (PeriodLifecycleException $exception) {
            $period = Period::query()->find($periodId);
            if ($period) {
                $this->monitor->transitionRejected($period, $operation, $exception, $actor);
            }

            throw $exception;
        }
    }

    private function assertNoCurrentPeriodConflict(Project $project, Period $period): void
    {
        if ($project->current_period_id !== null && (int) $project->current_period_id !== (int) $period->id) {
            throw PeriodLifecycleException::conflict('Bu projenin zaten guncel bir donemi var. Once mevcut donemi kapatin.');
        }

        $conflictingPeriod = Period::query()
            ->where('project_id', $project->id)
            ->where('id', '!=', $period->id)
            ->whereIn('status', [self::ACTIVE, self::CLOSING])
            ->lockForUpdate()
            ->first(['id', 'name', 'status']);

        if ($conflictingPeriod) {
            throw PeriodLifecycleException::conflict(
                "Bu projenin zaten {$conflictingPeriod->name} adli aktif/guncel donemi var. Once mevcut donemi kapatin.",
            );
        }
    }

    private function adoptLegacyCurrentPointer(Project $project, Period $period): void
    {
        if ($project->current_period_id !== null) {
            return;
        }

        $otherCurrentPeriodExists = Period::query()
            ->where('project_id', $project->id)
            ->where('id', '!=', $period->id)
            ->whereIn('status', [self::ACTIVE, self::CLOSING])
            ->lockForUpdate()
            ->exists();

        if ($otherCurrentPeriodExists) {
            throw PeriodLifecycleException::conflict('Projede birden fazla aktif donem bulundu; otomatik guncel donem secilemez.');
        }

        $project->forceFill(['current_period_id' => $period->id])->save();
    }

    private function closeApplicationIntake(Project $project, Period $period, ?User $actor): int
    {
        $now = now();
        $note = 'Donem kapanis hazirligina alindigi icin otomatik kapatildi.';
        $windows = ApplicationWindow::query()
            ->where('project_id', $project->id)
            ->where('period_id', $period->id)
            ->where('is_open', true)
            ->lockForUpdate()
            ->get();

        foreach ($windows as $window) {
            $existingNote = trim((string) $window->change_note);
            $window->forceFill([
                'is_open' => false,
                'closed_by' => $actor?->id,
                'closed_at' => $now,
                'updated_by' => $actor?->id,
                'status_changed_at' => $now,
                'change_note' => $existingNote === '' ? $note : $existingNote."\n".$note,
            ])->save();
        }

        if ($project->application_open) {
            $project->forceFill(['application_open' => false])->save();
        }

        return $windows->count();
    }

    private function assertStatus(Period $period, array $allowedStatuses, string $message): void
    {
        if (! in_array($period->status, $allowedStatuses, true)) {
            throw PeriodLifecycleException::invalidTransition($message);
        }
    }

    private function recordEvent(
        Period $period,
        string $eventType,
        ?string $fromStatus,
        ?string $toStatus,
        ?User $actor,
        ?string $reason = null,
        array $metadata = [],
    ): PeriodLifecycleEvent {
        $event = PeriodLifecycleEvent::query()->create([
            'period_id' => $period->id,
            'project_id' => $period->project_id,
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_id' => $actor?->id,
            'reason' => $reason,
            'metadata_json' => $metadata ?: null,
        ]);

        DB::afterCommit(fn () => $this->monitor->transitionSucceeded($event, (int) $period->lifecycle_version));

        return $event;
    }
}
