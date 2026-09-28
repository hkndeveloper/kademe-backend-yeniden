<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Program;
use App\Support\IstanbulDateTime;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplicationScheduleService
{
    // These applications still expect a place in the program. An unsuccessful
    // interview or a final rejection does not reserve the applicant's time.
    private const RESERVED_STATUSES = ['pending', 'waitlisted', 'interview_planned', 'interview_passed', 'accepted'];

    public function assertNoConflict(int $userId, ?Program $program, ?int $exceptApplicationId = null, string $errorKey = 'program_id'): void
    {
        if (! $program || ! $program->start_at || ! $program->end_at || ! in_array($program->status, ['scheduled', 'active'], true)) {
            return;
        }

        $conflict = DB::table('applications')
            ->join('programs', 'applications.program_id', '=', 'programs.id')
            ->where('applications.user_id', $userId)
            ->whereIn('applications.status', self::RESERVED_STATUSES)
            ->whereIn('programs.status', ['scheduled', 'active'])
            ->whereNull('programs.deleted_at')
            ->where('programs.start_at', '<', $program->end_at)
            ->where('programs.end_at', '>', $program->start_at)
            ->where('programs.id', '!=', $program->id)
            ->when($exceptApplicationId !== null, fn ($query) => $query->where('applications.id', '!=', $exceptApplicationId))
            ->orderBy('programs.start_at')
            ->select('programs.title', 'programs.start_at', 'programs.end_at')
            ->first();

        if ($conflict) {
            throw ValidationException::withMessages([
                $errorKey => [sprintf(
                    'Saat çakışması bulunmaktadır: "%s" (%s - %s) programıyla çakışıyor.',
                    $conflict->title,
                    IstanbulDateTime::format($conflict->start_at),
                    IstanbulDateTime::format($conflict->end_at),
                )],
            ]);
        }
    }

    /** @return Collection<int, Application> */
    public function affectedApplications(Program $program): Collection
    {
        if (! $program->start_at || ! $program->end_at || ! in_array($program->status, ['scheduled', 'active'], true)) {
            return new Collection;
        }

        return Application::query()
            ->with('user:id,name,surname')
            ->where('program_id', $program->id)
            ->whereIn('status', self::RESERVED_STATUSES)
            ->whereExists(function ($query) use ($program) {
                $query->selectRaw('1')
                    ->from('applications as other_applications')
                    ->join('programs as other_programs', 'other_applications.program_id', '=', 'other_programs.id')
                    ->whereColumn('other_applications.user_id', 'applications.user_id')
                    ->where('other_programs.id', '!=', $program->id)
                    ->whereIn('other_applications.status', self::RESERVED_STATUSES)
                    ->whereIn('other_programs.status', ['scheduled', 'active'])
                    ->whereNull('other_programs.deleted_at')
                    ->where('other_programs.start_at', '<', $program->end_at)
                    ->where('other_programs.end_at', '>', $program->start_at);
            })
            ->orderBy('id')
            ->get();
    }
}
