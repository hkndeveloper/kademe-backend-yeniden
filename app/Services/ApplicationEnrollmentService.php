<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Participant;
use Illuminate\Validation\ValidationException;

class ApplicationEnrollmentService
{
    public function __construct(
        private readonly ApplicationCapacityService $capacityService,
        private readonly ApplicationAudienceService $audienceService,
        private readonly ApplicationProjectPeriodGuard $projectPeriodGuard,
    ) {}

    /** Called inside ApplicationDecisionService's lock, after workflow checks. */
    public function enroll(Application $application, string $errorKey = 'status'): Participant
    {
        $this->audienceService->assertCanAccept($application, $errorKey);
        $application->loadMissing(['user', 'period']);
        $user = $application->user;

        if ($application->usesInterview() && $application->interview_passed_at === null) {
            throw ValidationException::withMessages([
                $errorKey => ['Mülakat olumlu tamamlanmadan kabul işlemi yapılamaz.'],
            ]);
        }

        if ($user?->status === 'blacklisted'
            && ($user->blacklisted_until === null || $user->blacklisted_until->isFuture())) {
            throw ValidationException::withMessages([
                'application' => ['Adayin basvuru kisitlamasi devam ettigi icin kabul islemi yapilamaz.'],
            ]);
        }

        $this->projectPeriodGuard->assertNoOverlappingActiveProject(
            $application->user_id,
            $application->project_id,
            $application->period,
            $errorKey
        );

        if (! $this->capacityService->hasAvailableSeat($application, forApplicant: true)) {
            throw ValidationException::withMessages([
                $errorKey => ['Kontenjan dolu. Basvuruyu kabul etmeden once kontenjan acin veya yedek listede birakin.'],
            ]);
        }

        // Another program acceptance must not reset an existing period membership.
        $participant = Participant::firstOrCreate([
            'user_id' => $application->user_id,
            'project_id' => $application->project_id,
            'period_id' => $application->period_id,
        ], [
            'status' => 'active',
            'credit' => $application->period?->credit_start_amount ?? 100,
            'enrolled_at' => now(),
        ]);

        // Participation is separate from the account's administrative roles/status.
        $user?->profile()->firstOrCreate(['user_id' => $user->id], []);

        // Legacy visitor accounts become students on acceptance, but an account
        // carrying any other role must keep that assignment and its account state.
        if ($user?->role === 'visitor' && ! $user->roles()->where('name', '!=', 'visitor')->exists()) {
            $user->update(['role' => 'student', 'status' => 'active']);
            $user->syncRoles(['student']);
        }

        return $participant;
    }
}
