<?php

namespace App\Services;

use App\Enums\PeriodWriteAction;
use App\Models\Period;
use App\Models\PeriodLifecycleEvent;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class PeriodLifecycleMonitor
{
    public function transitionSucceeded(PeriodLifecycleEvent $event, int $lifecycleVersion): void
    {
        if (! $this->enabled()) {
            return;
        }

        Log::info('period_lifecycle.transition_succeeded', [
            'event_id' => (int) $event->id,
            'event_type' => $event->event_type,
            'period_id' => (int) $event->period_id,
            'project_id' => (int) $event->project_id,
            'from_status' => $event->from_status,
            'to_status' => $event->to_status,
            'actor_id' => $event->actor_id ? (int) $event->actor_id : null,
            'lifecycle_version' => $lifecycleVersion,
        ]);
    }

    public function transitionRejected(
        Period $period,
        string $operation,
        HttpExceptionInterface $exception,
        ?User $actor = null,
    ): void
    {
        if (! $this->enabled()) {
            return;
        }

        Log::warning('period_lifecycle.transition_rejected', [
            'operation' => $operation,
            'period_id' => (int) $period->id,
            'project_id' => (int) $period->project_id,
            'period_status' => $period->status,
            'actor_id' => $actor?->id ? (int) $actor->id : null,
            'status_code' => $exception->getStatusCode(),
        ]);
    }

    public function writeRejected(?User $actor, Period $period, PeriodWriteAction $action): void
    {
        if (! $this->enabled()) {
            return;
        }

        Log::warning('period_lifecycle.write_rejected', [
            'period_id' => (int) $period->id,
            'project_id' => (int) $period->project_id,
            'period_status' => $period->status,
            'write_action' => $action->value,
            'actor_id' => $actor?->id ? (int) $actor->id : null,
            'status_code' => 423,
        ]);
    }

    public function closureBlocked(Period $period, ?User $actor, array $readiness): void
    {
        if (! $this->enabled()) {
            return;
        }

        Log::warning('period_lifecycle.closure_blocked', [
            'period_id' => (int) $period->id,
            'project_id' => (int) $period->project_id,
            'actor_id' => $actor?->id ? (int) $actor->id : null,
            'blocker_count' => count($readiness['blockers'] ?? []),
            'blocked_record_count' => collect($readiness['blockers'] ?? [])->sum(
                fn (array $blocker) => (int) ($blocker['count'] ?? 0)
            ),
            'blockers' => collect($readiness['blockers'] ?? [])
                ->map(fn (array $blocker) => [
                    'code' => $blocker['code'] ?? null,
                    'count' => (int) ($blocker['count'] ?? 0),
                ])
                ->values()
                ->all(),
            'watermark' => $readiness['watermark'] ?? null,
        ]);
    }

    public function archiveVerificationFailed(array $result, ?User $actor = null): void
    {
        if (! $this->enabled()) {
            return;
        }

        Log::error('period_lifecycle.archive_verification_failed', [
            'archive_id' => (int) ($result['archive_id'] ?? 0),
            'period_id' => (int) ($result['period_id'] ?? 0),
            'archive_version' => (int) ($result['archive_version'] ?? 0),
            'hash_valid' => (bool) ($result['hash_valid'] ?? false),
            'chain_valid' => (bool) ($result['chain_valid'] ?? false),
            'actor_id' => $actor?->id ? (int) $actor->id : null,
        ]);
    }

    public function archiveVerificationRunCompleted(int $checked, int $invalid): void
    {
        if (! $this->enabled()) {
            return;
        }

        $context = [
            'checked_count' => $checked,
            'invalid_count' => $invalid,
        ];

        if ($invalid > 0) {
            Log::error('period_lifecycle.archive_verification_run_failed', $context);

            return;
        }

        Log::info('period_lifecycle.archive_verification_run_succeeded', $context);
    }

    private function enabled(): bool
    {
        return (bool) config('period_lifecycle.monitoring.enabled', true);
    }
}
