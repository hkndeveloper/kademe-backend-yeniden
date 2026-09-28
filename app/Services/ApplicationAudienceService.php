<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Program;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class ApplicationAudienceService
{
    private const REVIEW_STATUSES = ['pending', 'waitlisted', 'interview_planned', 'interview_passed', 'accepted'];

    // The participant program list treats alumni separately and everyone else
    // as a student audience. Preserve that rule for dual-role staff applicants.
    public function isEligible(Program $program, User $user): bool
    {
        return $program->isTargetedTo($user->role === 'alumni' ? 'alumni' : 'student');
    }

    public function assertCanSubmit(Program $program, User $user, bool $publicSubmission): void
    {
        if ($publicSubmission && ! $program->effectivePublicVisibility()) {
            throw ValidationException::withMessages([
                'program_id' => ['Bu program halka açık başvuruya uygun değil.'],
            ]);
        }

        if (! $this->isEligible($program, $user)) {
            throw ValidationException::withMessages([
                'program_id' => ['Bu program sizin katılımcı grubunuza açık değil.'],
            ]);
        }
    }

    public function assertCanAccept(Application $application, string $errorKey): void
    {
        if ($application->program_id === null) {
            return;
        }

        $application->loadMissing(['program', 'user']);
        $program = $application->program;
        if (! $program
            || (int) $program->project_id !== (int) $application->project_id
            || (int) $program->period_id !== (int) $application->period_id
            || ! in_array($program->status, ['scheduled', 'active'], true)) {
            throw ValidationException::withMessages([
                $errorKey => ['Başvurulan program artık bu proje ve dönem için kabule uygun değil.'],
            ]);
        }

        if (! $application->user || ! $this->isEligible($program, $application->user)) {
            throw ValidationException::withMessages([
                $errorKey => ['Aday bu programın güncel katılımcı grubuna uygun değil.'],
            ]);
        }
    }

    /** @return Collection<int, Application> */
    public function audienceMismatches(Program $program): Collection
    {
        if (! in_array($program->status, ['scheduled', 'active'], true)) {
            return new Collection;
        }

        return Application::query()
            ->with('user:id,name,surname,role')
            ->where('program_id', $program->id)
            ->whereIn('status', self::REVIEW_STATUSES)
            ->orderBy('id')
            ->get()
            ->filter(fn (Application $application) => ! $application->user || ! $this->isEligible($program, $application->user));
    }
}
