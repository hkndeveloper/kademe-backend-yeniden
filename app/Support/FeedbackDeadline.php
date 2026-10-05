<?php

namespace App\Support;

use App\Models\Program;
use Illuminate\Support\Carbon;

class FeedbackDeadline
{
    public static function forProgram(Program $program): ?Carbon
    {
        if (! $program->start_at) {
            return null;
        }

        $nextProgram = Program::query()
            ->where('project_id', $program->project_id)
            ->when(
                $program->period_id,
                fn ($query) => $query->where('period_id', $program->period_id),
                fn ($query) => $query->whereNull('period_id')
            )
            ->where('id', '!=', $program->id)
            ->where('start_at', '>', $program->start_at)
            ->orderBy('start_at')
            ->first(['start_at', 'end_at']);

        return $nextProgram?->end_at ?? $nextProgram?->start_at;
    }

    public static function isOpen(Program $program): bool
    {
        $deadline = self::forProgram($program);

        return $deadline === null || now()->lt($deadline);
    }
}
