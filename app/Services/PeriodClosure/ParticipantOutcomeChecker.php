<?php

namespace App\Services\PeriodClosure;

use App\Models\Participant;
use App\Models\Period;

class ParticipantOutcomeChecker implements PeriodClosureChecker
{
    public function check(Period $period): array
    {
        $count = Participant::query()
            ->where('period_id', $period->id)
            ->whereNull('graduation_status')
            ->whereIn('status', ['active', 'graduated', 'failed'])
            ->count();

        return [[
            'code' => 'missing_participant_outcomes',
            'count' => $count,
            'message' => 'Donem sonucu belirlenmemis katilimcilar var.',
        ]];
    }
}
