<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Participant;
use App\Models\TrainingEnrollment;
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

        if ($application->user_id) {
            $this->projectPeriodGuard->assertNoOverlappingProjectParticipation($application->user_id, $application->project_id, $application->period, $errorKey);
        }

        if (! $this->capacityService->hasAvailableSeat($application, forApplicant: true)) {
            throw ValidationException::withMessages([
                $errorKey => ['Kontenjan dolu. Basvuruyu kabul etmeden once kontenjan acin veya yedek listede birakin.'],
            ]);
        }

        if ($application->training_id) {
            $training = $application->training;
            if (! $training || ! $training->is_active || (int) $training->project_id !== (int) $application->project_id
                || (int) $training->period_id !== (int) $application->period_id) {
                throw ValidationException::withMessages([$errorKey => ['Eğitim bu proje/dönem için kabule uygun değil.']]);
            }
        }
        $user = app(AcceptedApplicantAccountService::class)->resolve($application);
        if ($user->status === 'blacklisted' && (! $user->blacklisted_until || $user->blacklisted_until->isFuture())) {
            throw ValidationException::withMessages([$errorKey => ['Adayın başvuru kısıtlaması devam ediyor.']]);
        }
        // Recheck against the resolved account; another candidate acceptance may have linked it.
        $this->projectPeriodGuard->assertNoOverlappingProjectParticipation($user->id, $application->project_id, $application->period, $errorKey);

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

        if ($application->training_id) {
            TrainingEnrollment::firstOrCreate(['training_id' => $application->training_id, 'user_id' => $user->id],
                ['application_id' => $application->id, 'status' => 'active']);
        }

        // Legacy visitor accounts become students on acceptance, but an account
        // carrying any other role must keep that assignment and its account state.
        if ($user?->role === 'visitor' && ! $user->roles()->where('name', '!=', 'visitor')->exists()) {
            $user->update(['role' => 'student', 'status' => 'active']);
            $user->syncRoles(['student']);
        }

        return $participant;
    }
}
