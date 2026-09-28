<?php

namespace App\Services;

use App\Models\Participant;
use App\Models\Period;
use Illuminate\Validation\ValidationException;

class ApplicationProjectPeriodGuard
{
    public function assertNoOverlappingActiveProject(int $userId, int $projectId, Period $period, string $errorKey): void
    {
        $conflict = Participant::query()
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->where('project_id', '!=', $projectId)
            ->where(function ($query) use ($period) {
                // A legacy participation without a period cannot be shown to be non-overlapping.
                $query->whereNull('period_id')
                    ->orWhereHas('period', fn ($otherPeriod) => $otherPeriod
                        ->whereDate('start_date', '<=', $period->end_date)
                        ->whereDate('end_date', '>=', $period->start_date));
            })
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                $errorKey => ['Çakışan bir dönemde başka bir projeye aktif katılımınız bulunduğu için işlem yapılamaz.'],
            ]);
        }
    }
}
