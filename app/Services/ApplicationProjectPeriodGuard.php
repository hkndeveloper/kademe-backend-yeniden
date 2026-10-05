<?php

namespace App\Services;

use App\Models\Participant;
use App\Models\Period;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ApplicationProjectPeriodGuard
{
    public function assertNoOverlappingProjectParticipation(int $userId, int $projectId, Period $period, string $errorKey): void
    {
        if ($this->hasOverlappingParticipation($userId, $projectId, $period)) {
            throw ValidationException::withMessages([
                $errorKey => ['Tarihleri çakışan bir dönemde başka bir projeye katılımınız bulunduğu için işlem yapılamaz.'],
            ]);
        }
    }

    /** Called while the period is locked, before changed dates are saved. */
    public function assertPeriodDateChangeDoesNotOverlap(Period $period): void
    {
        $userIds = Participant::query()
            ->where('period_id', $period->id)
            ->where('status', '!=', 'waitlist')
            ->distinct()
            ->orderBy('user_id')
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            // Match acceptance's project -> period -> user lock order.
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();

            if ($this->hasOverlappingParticipation((int) $userId, (int) $period->project_id, $period)) {
                throw ValidationException::withMessages([
                    'start_date' => ['Bu tarih aralığı, kayıtlı bir öğrencinin başka projedeki dönemiyle çakışıyor.'],
                ]);
            }
        }
    }

    private function hasOverlappingParticipation(int $userId, int $projectId, Period $period): bool
    {
        return Participant::query()
            ->where('user_id', $userId)
            // Completed, graduated and failed students were still enrolled in that period.
            ->where('status', '!=', 'waitlist')
            ->where('project_id', '!=', $projectId)
            ->where(function ($query) use ($period) {
                // A legacy participation without a period cannot be shown to be non-overlapping.
                $query->whereNull('period_id')
                    ->orWhereHas('period', fn ($otherPeriod) => $otherPeriod
                        ->whereDate('start_date', '<=', $period->end_date)
                        ->whereDate('end_date', '>=', $period->start_date));
            })
            ->exists();
    }
}
