<?php

namespace App\Services;

use App\Models\Period;
use App\Services\PeriodClosure\AdvisoryOperationsChecker;
use App\Services\PeriodClosure\CoreOperationsChecker;
use App\Services\PeriodClosure\CreditRiskChecker;
use App\Services\PeriodClosure\ParticipantOutcomeChecker;
use App\Services\PeriodClosure\PeriodClosureChecker;

class PeriodClosureReadinessService
{
    /** @var array<int, PeriodClosureChecker> */
    private array $checkers;

    public function __construct(
        CoreOperationsChecker $coreOperations,
        ParticipantOutcomeChecker $participantOutcomes,
        AdvisoryOperationsChecker $advisoryOperations,
        CreditRiskChecker $creditRisk,
    ) {
        $this->checkers = [$coreOperations, $participantOutcomes, $advisoryOperations, $creditRisk];
    }

    public function evaluate(Period $period): array
    {
        $calculatedAt = now();
        $policy = config('period_lifecycle.closure_defaults', []);
        $results = collect($this->checkers)
            ->flatMap(fn (PeriodClosureChecker $checker) => $checker->check($period))
            ->map(function (array $result) use ($policy) {
                $severity = $policy[$result['code']] ?? 'warning';

                return [
                    ...$result,
                    'severity' => $severity,
                    'resolved' => (int) $result['count'] === 0,
                ];
            })
            ->values();

        $blockers = $results->where('severity', 'blocker')->where('resolved', false)->values();
        $warnings = $results->where('severity', 'warning')->where('resolved', false)->values();
        $resolved = $results->where('resolved', true)->values();

        return [
            'ready' => $blockers->isEmpty(),
            'blockers' => $blockers->all(),
            'warnings' => $warnings->all(),
            'resolved_checks' => $resolved->all(),
            'checks' => $results->all(),
            'calculated_at' => $calculatedAt->toIso8601String(),
            'watermark' => $this->watermark($period, $results->all()),
        ];
    }

    private function watermark(Period $period, array $results): string
    {
        return hash('sha256', json_encode([
            'period_id' => (int) $period->id,
            'lifecycle_version' => (int) $period->lifecycle_version,
            'period_updated_at' => optional($period->updated_at)?->toIso8601String(),
            'counts' => collect($results)->mapWithKeys(fn (array $item) => [$item['code'] => $item['count']])->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
