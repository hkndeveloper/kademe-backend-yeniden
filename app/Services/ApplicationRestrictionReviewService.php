<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\CreditLog;
use App\Models\Participant;
use App\Models\User;

class ApplicationRestrictionReviewService
{
    public function __construct(private readonly CreditService $creditService) {}

    public function forUser(User $user): array
    {
        $participations = Participant::query()->where('user_id', $user->id)->get();
        $confirmedCount = $participations->sum(fn (Participant $participant) => $this->creditService->confirmedAbsenceCount($participant));
        $legacyLogs = CreditLog::query()
            ->with('program:id,title,status')
            ->where('user_id', $user->id)
            ->where('type', 'deduction')
            ->whereNotNull('program_id')
            ->where('absence_confirmed', false)
            ->orderByDesc('id')
            ->get();
        $programIds = $legacyLogs->pluck('program_id')->unique()->values();
        $validAttendanceIds = Attendance::query()
            ->where('user_id', $user->id)
            ->where('is_valid', true)
            ->whereIn('program_id', $programIds)
            ->pluck('program_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $restoredIds = CreditLog::query()
            ->where('user_id', $user->id)
            ->where('type', 'restore')
            ->whereIn('program_id', $programIds)
            ->pluck('program_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return [
            'confirmed_absence_count' => (int) $confirmedCount,
            'unclassified_deduction_count' => $legacyLogs->count(),
            'unclassified_deductions' => $legacyLogs->map(fn (CreditLog $log) => [
                'id' => $log->id,
                'program_id' => $log->program_id,
                'program_title' => $log->program?->title,
                'program_status' => $log->program?->status,
                'amount' => (int) $log->amount,
                'excused' => (bool) $log->excused,
                'valid_attendance' => in_array((int) $log->program_id, $validAttendanceIds, true),
                'credit_restored' => in_array((int) $log->program_id, $restoredIds, true),
                'created_at' => optional($log->created_at)?->toIso8601String(),
            ])->values(),
        ];
    }
}
