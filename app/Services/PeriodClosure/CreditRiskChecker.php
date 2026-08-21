<?php

namespace App\Services\PeriodClosure;

use App\Models\Participant;
use App\Models\Period;

class CreditRiskChecker implements PeriodClosureChecker
{
    public function check(Period $period): array
    {
        $threshold = (int) ($period->credit_threshold ?? 75);
        $count = Participant::query()
            ->where('period_id', $period->id)
            ->where('credit', '<', $threshold)
            ->count();

        return [[
            'code' => 'low_credit',
            'count' => $count,
            'message' => 'Kredi esiginin altinda katilimcilar var.',
            'metadata' => ['threshold' => $threshold],
        ]];
    }
}
