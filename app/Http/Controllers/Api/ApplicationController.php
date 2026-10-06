<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesProjectPeriodContext;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationCandidate;
use App\Models\ApplicationForm;
use App\Models\ApplicationWindow;
use App\Models\Participant;
use App\Models\Period;
use App\Models\Program;
use App\Models\Project;
use App\Models\ProjectTraining;
use App\Models\TrainingEnrollment;
use App\Models\User;
use App\Services\ApplicationAudienceService;
use App\Services\ApplicationConsentService;
use App\Services\ApplicationDecisionService;
use App\Services\ApplicationEmailVerificationService;
use App\Services\ApplicationEnrollmentService;
use App\Services\ApplicationFormResolver;
use App\Services\ApplicationIntakeService;
use App\Services\ApplicationMessageService;
use App\Services\ApplicationNotificationRecipientService;
use App\Services\ApplicationProjectPeriodGuard;
use App\Services\ApplicationScheduleService;
use App\Services\ApplicationScreeningService;
use App\Services\ApplicationSubmissionService;
use App\Services\ApplicationTrackingService;
use App\Services\NotificationService;
use App\Services\WaitlistService;
use App\Support\ApplicationFileStorage;
use App\Support\ApplicationMailLinks;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @group Applications
 */
class ApplicationController extends Controller
{
    use ResolvesProjectPeriodContext;

    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly WaitlistService $waitlistService,
        private readonly ApplicationIntakeService $intakeService,
        private readonly ApplicationEnrollmentService $applicationEnrollmentService,
        private readonly ApplicationDecisionService $applicationDecisionService,
        private readonly ApplicationSubmissionService $applicationSubmissionService,
        private readonly ApplicationEmailVerificationService $emailVerificationService,
        private readonly ApplicationFormResolver $applicationFormResolver,
        private readonly ApplicationConsentService $applicationConsentService,
        private readonly ApplicationScreeningService $applicationScreeningService,
        private readonly ApplicationScheduleService $applicationScheduleService,
        private readonly ApplicationAudienceService $applicationAudienceService,
        private readonly ApplicationNotificationRecipientService $notificationRecipients,
        private readonly ApplicationProjectPeriodGuard $projectPeriodGuard,
    ) {}

    private function resolveApplicantUser(array $applicant): ApplicationCandidate
    {
        $email = Str::lower(trim($applicant['email']));

        return ApplicationCandidate::firstOrCreate(['email' => $email], [
            'name' => trim($applicant['name']), 'surname' => trim($applicant['surname']),
            'phone' => $applicant['phone'] ?? null, 'email_verified_at' => now(),
            'user_id' => User::whereRaw('LOWER(email) = ?', [$email])->value('id'),
        ]);
    }

    private function ensureUserCanApply(User $user): void
    {
        if ($user->status === 'blacklisted' && (! $user->blacklisted_until || now()->isBefore($user->blacklisted_until))) {
            throw ValidationException::withMessages([
                'user' => ['Kara listede oldugunuz icin su anda basvuru yapamazsiniz.'],
            ]);
        }
    }

    private function ensureProjectAcceptsApplications(Project $project, bool $publicSubmission = false): void
    {
        if ($publicSubmission && ! $project->is_public) {
            throw ValidationException::withMessages([
                'project_id' => ['Bu proje halka açık başvuruya uygun değil.'],
            ]);
        }

        $period = $project->currentPeriodOrLegacy();

        if (! $period || $period->status !== 'active') {
            throw ValidationException::withMessages([
                'project_id' => ['Bu proje icin aktif bir donem bulunamadi.'],
            ]);
        }

        if (! $this->intakeService->isOpen($project, $period)) {
            throw ValidationException::withMessages([
                'project_id' => ['Bu proje ve aktif donem icin basvurular su an kapali.'],
            ]);
        }
    }

    private function projectPeriodHasAvailableSeat(Project $project, Period $period, ?Program $program = null, ?ApplicationWindow $window = null): bool
    {
        $quota = $program?->application_quota ?? $this->intakeService->quota($project, $window);
        if ($quota === null || (int) $quota <= 0) {
            return true;
        }

        if ($program?->application_quota !== null) {
            $acceptedCount = Application::query()
                ->where('project_id', $project->id)
                ->where('period_id', $period->id)
                ->where('program_id', $program->id)
                ->where('status', 'accepted')
                ->count();
        } else {
            $acceptedCount = Participant::query()
                ->where('project_id', $project->id)
                ->where('period_id', $period->id)
                ->where('status', 'active')
                ->count();
        }

        return $acceptedCount < (int) $quota;
    }

    private function fileMetadata(string $path, UploadedFile $file): array
    {
        return [
            'path' => $path,
            'storage' => 'application_private',
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ];
    }

    private function applicationStatusLabel(string $status): string
    {
        return match ($status) {
            'accepted' => 'Kabul edildi',
            'rejected' => 'Reddedildi',
            'waitlisted' => 'Yedek liste',
            'interview_planned' => 'Mulakat planlandi',
            'interview_passed' => 'Mulakat olumlu',
            'interview_failed' => 'Mulakat olumsuz',
            default => 'Degerlendirme bekliyor',
        };
    }

    private function sendApplicationEmail(array $emails, string $subject, array $data, ?int $projectId = null, ?int $senderId = null, ?int $applicationId = null): int
    {
        try {
            if ($applicationId) {
                $application = Application::find($applicationId);
                if ($application && count($emails) === 1 && in_array($application->applicant()?->email, $emails, true)) {
                    return app(ApplicationMessageService::class)->send($application, in_array($application->status, ['accepted', 'rejected'], true) ? $application->status : 'received', $subject, $data, $senderId);
                }
            }

            return $this->notificationService->sendTemplatedEmail(
                $emails,
                $subject,
                'emails.application-status',
                $data,
                $projectId,
                $senderId
            );
        } catch (\Throwable $exception) {
            Log::warning('application.notification_failed', [
                'application_id' => $applicationId,
                'project_id' => $projectId,
                'error' => $exception->getMessage(),
            ]);

            return 0;
        }
    }

    /** @return list<string>|null */
    private function applicationCoordinatorEmails(Project $project, int $applicationId): ?array
    {
        try {
            return $this->notificationRecipients->coordinatorEmailsFor($project);
        } catch (\Throwable $exception) {
            Log::warning('application.coordinator_recipient_resolution_failed', [
                'application_id' => $applicationId,
                'project_id' => $project->id,
                'error_type' => $exception::class,
            ]);

            return null;
        }
    }

    /** @return array{Application, array{applicant_email_sent: bool, coordinators_email_sent: ?bool}} */
    private function createApplicationForUser(User|\Closure $user, Project $project, array $formData, array $formFiles = [], bool $consentAccepted = false, ?int $programId = null, ?string $verificationCode = null, ?int $expectedFormId = null, ?string $expectedConsentText = null, bool $publicSubmission = false, ?int $trainingId = null): array
    {
        $uploadedPaths = [];
        try {
            $submission = function (User|ApplicationCandidate $currentUser, Project $currentProject, ?Period $period) use ($formData, $formFiles, $consentAccepted, $programId, $verificationCode, $expectedFormId, $expectedConsentText, $publicSubmission, $trainingId, &$uploadedPaths) {
                return $this->recordApplicationForUser(
                    $currentUser, $currentProject, $period, $formData, $formFiles, $consentAccepted, $programId, $uploadedPaths, $verificationCode, $expectedFormId, $expectedConsentText, $publicSubmission, $trainingId
                );
            };
            $application = $user instanceof User
                ? $this->applicationSubmissionService->runLocked($user, $project, $submission)
                : $this->applicationSubmissionService->runLockedForGuest($project, $user, $submission);
        } catch (\Throwable $exception) {
            // Only remove files created by this attempt, never client-supplied paths.
            foreach ($uploadedPaths as $path) {
                ApplicationFileStorage::delete($path);
            }
            throw $exception;
        }

        // Notification failures never turn a committed application into an apparent failed submission.
        try {
            $followUp = $this->notifyApplicationReceived($application);
        } catch (\Throwable $exception) {
            Log::warning('application.receipt_notification_failed', [
                'application_id' => $application->id,
                'error' => $exception->getMessage(),
            ]);
            $followUp = ['applicant_email_sent' => false, 'coordinators_email_sent' => false];
        }

        return [$application, $followUp];
    }

    private function recordApplicationForUser(User|ApplicationCandidate $user, Project $project, ?Period $currentPeriod, array $formData, array $formFiles, bool $consentAccepted, ?int $programId, array &$uploadedPaths, ?string $verificationCode, ?int $expectedFormId, ?string $expectedConsentText, bool $publicSubmission, ?int $trainingId): Application
    {
        $account = $user instanceof User ? $user : $user->user;
        if ($account) {
            $this->ensureUserCanApply($account);
        }

        if (! $currentPeriod || $currentPeriod->status !== 'active') {
            throw ValidationException::withMessages([
                'project_id' => ['Bu proje icin aktif bir donem bulunamadi.'],
            ]);
        }

        if ($account) {
            $this->projectPeriodGuard->assertNoOverlappingProjectParticipation($account->id, $project->id, $currentPeriod, 'project_id');
        }

        $training = null;
        if ($project->application_scope === 'training') {
            $training = ProjectTraining::where('project_id', $project->id)->where('period_id', $currentPeriod->id)->find($trainingId);
            if (! $training || ! $training->isOpen()) {
                throw ValidationException::withMessages(['training_id' => ['Başvurusu açık bir eğitim seçin.']]);
            }
        } elseif ($trainingId !== null) {
            throw ValidationException::withMessages(['training_id' => ['Bu proje yalnız proje başvurusu alır.']]);
        }
        if ($programId !== null) {
            throw ValidationException::withMessages(['program_id' => ['Ders veya oturuma değil, projeye ya da eğitime başvurun.']]);
        }

        $applicationWindow = $this->intakeService->windowFor($project, $currentPeriod);
        if (! $this->intakeService->isOpen($project, $currentPeriod, $applicationWindow)) {
            throw ValidationException::withMessages([
                'project_id' => ['Bu proje ve aktif donem icin basvurular su an kapali.'],
            ]);
        }

        $program = null;

        $submissionKey = hash('sha256', Str::lower($user->email).'|'.$project->id.'|'.$currentPeriod->id.'|'.($training?->id ?? 'project'));
        $existingApp = Application::where(function ($query) use ($user, $account, $submissionKey) {
            $query->where('submission_key', $submissionKey);
            if ($user instanceof ApplicationCandidate) {
                $query->orWhere('candidate_id', $user->id);
            }
            if ($account) {
                $query->orWhere('user_id', $account->id);
            }
        })
            ->where('project_id', $project->id)
            ->where('period_id', $currentPeriod->id)
            ->when($training, fn ($query) => $query->where('training_id', $training->id))
            ->first();

        if ($existingApp) {
            throw ValidationException::withMessages([
                'project_id' => ['Bu projeye ve doneme ait zaten bir basvurunuz bulunuyor.'],
            ]);
        }

        $form = $this->applicationFormResolver->forApplication($project, $currentPeriod, $program, $training);

        if ($expectedFormId !== null && $expectedFormId !== (int) ($form?->id ?? 0)) {
            throw ValidationException::withMessages([
                'application_form_id' => ['Basvuru formu guncellendi. Formu yeniden acip guncel sorulari kontrol edin.'],
            ]);
        }

        $consentText = $this->applicationConsentService->textFor($form);
        if ($expectedConsentText !== null && $expectedConsentText !== $consentText) {
            throw ValidationException::withMessages([
                'expected_consent_text' => ['Basvuru kosullari guncellendi. Metni yeniden okuyup onaylayin.'],
            ]);
        }

        if (! $consentAccepted) {
            throw ValidationException::withMessages([
                'consent_accepted' => ['Basvuru kosullarini kabul etmeniz gerekiyor.'],
            ]);
        }

        $normalizedFormData = $this->validateDynamicFields($form, Arr::wrap($formData), $formFiles, $uploadedPaths);
        $screeningMatch = $this->applicationScreeningService->firstMatch(
            $form?->auto_reject_rules ?? [],
            $normalizedFormData,
            $user->email,
            $user->phone,
        );
        $autoRejectReason = ($screeningMatch['mode'] ?? null) === 'reject' ? $screeningMatch['reason'] : null;
        $reviewReason = ($screeningMatch['mode'] ?? null) === 'review' ? $screeningMatch['reason'] : null;
        $trainingHasSeat = ! $training || $training->quota === null || TrainingEnrollment::where('training_id', $training->id)->where('status', 'active')->count() < $training->quota;
        $initialStatus = $autoRejectReason
            ? 'rejected'
            : ($reviewReason || ($training ? $trainingHasSeat : $this->projectPeriodHasAvailableSeat($project, $currentPeriod, $program, $applicationWindow)) ? 'pending' : 'waitlisted');
        $waitlistOrder = $initialStatus === 'waitlisted'
            ? $this->nextWaitlistOrder($project, $currentPeriod, $program)
            : null;

        if ($verificationCode !== null) {
            $this->emailVerificationService->consume($project->id, $user->email, $verificationCode);
        }

        return Application::create([
            'user_id' => $account?->id,
            'candidate_id' => $user instanceof ApplicationCandidate ? $user->id : null,
            'training_id' => $training?->id,
            'submission_key' => $submissionKey,
            'has_interview_snapshot' => $training ? false : (bool) ($applicationWindow?->has_interview ?? $project->has_interview),
            'form_fields_snapshot' => $form?->fields ?? [],
            'project_id' => $project->id,
            'period_id' => $currentPeriod->id,
            'application_window_id' => $applicationWindow?->id,
            'program_id' => $program?->id,
            'application_form_id' => $form?->id,
            'form_data' => $normalizedFormData,
            'consent_text_snapshot' => $consentText,
            'consent_accepted_at' => now(),
            'status' => $initialStatus,
            'waitlist_order' => $waitlistOrder,
            'auto_rejected' => (bool) $autoRejectReason,
            'auto_rejection_reason' => $autoRejectReason,
            'screening_review_reason' => $reviewReason,
            'rejection_reason' => $autoRejectReason,
        ]);
    }

    /** @return array{applicant_email_sent: bool, coordinators_email_sent: ?bool} */
    private function notifyApplicationReceived(Application $application): array
    {
        $user = $application->applicant();
        $project = $application->project()->firstOrFail();
        $period = $application->period()->first();
        $program = $application->program()->first();
        $autoRejectReason = $application->auto_rejection_reason;
        $initialStatus = $application->status;
        $followUpUrl = app(ApplicationTrackingService::class)->issue($application);

        $applicantEmailSent = $this->sendApplicationEmail(
            array_filter([$user->email]),
            $autoRejectReason ? 'Başvurunuz değerlendirildi' : 'Başvurunuz alındı',
            [
                'title' => $autoRejectReason ? 'Başvurunuz Değerlendirildi' : 'Başvurunuz Alındı',
                'preheader' => "{$project->name} başvurunuz sisteme kaydedildi.",
                'intro' => $autoRejectReason
                    ? 'Başvurunuz otomatik değerlendirme kuralıyla sonuçlandı.'
                    : 'Başvurunuz başarıyla alındı. Değerlendirme süreci tamamlandığında bilgilendirileceksiniz.',
                'lines' => array_values(array_filter([
                    ['label' => 'Proje', 'value' => $project->name],
                    $period ? ['label' => 'Dönem', 'value' => $period->name] : null,
                    $program ? ['label' => 'Program', 'value' => $program->title] : null,
                    ['label' => 'Durum', 'value' => $this->applicationStatusLabel($initialStatus)],
                    $initialStatus === 'waitlisted' ? ['label' => 'Not', 'value' => 'Kontenjan dolu olduğu için başvurunuz yedek listeye alındı.'] : null,
                    $autoRejectReason ? ['label' => 'Gerekçe', 'value' => $autoRejectReason] : null,
                ])),
                'action_url' => $followUpUrl,
                'action_text' => $followUpUrl ? 'Başvurularımı görüntüle' : null,
                'plain_text' => "Proje: {$project->name}".($period ? "\nDönem: {$period->name}" : '').($program ? "\nProgram: {$program->title}" : '')."\nDurum: ".$this->applicationStatusLabel($initialStatus).($autoRejectReason ? "\nGerekçe: {$autoRejectReason}" : '').($followUpUrl ? "\nBaşvurularım: {$followUpUrl}" : ''),
            ],
            $project->id,
            $application->user_id,
            $application->id
        ) > 0;

        $coordinatorEmails = $this->applicationCoordinatorEmails($project, $application->id);

        $coordinatorsEmailSent = $coordinatorEmails === null ? false : null;
        if ($coordinatorEmails !== null && $coordinatorEmails !== []) {
            $coordinatorsEmailSent = $this->sendApplicationEmail(
                $coordinatorEmails,
                'Yeni başvuru alındı',
                [
                    'title' => 'Yeni Başvuru Alındı',
                    'preheader' => "{$project->name} için yeni başvuru var.",
                    'intro' => 'Yeni bir başvuru sisteme düştü.',
                    'lines' => array_values(array_filter([
                        ['label' => 'Başvuru numarası', 'value' => (string) $application->id],
                        ['label' => 'Proje', 'value' => $project->name],
                        $period ? ['label' => 'Dönem', 'value' => $period->name] : null,
                        $program ? ['label' => 'Program', 'value' => $program->title] : null,
                        ['label' => 'Aday', 'value' => trim($user->name.' '.$user->surname)],
                        ['label' => 'Durum', 'value' => $this->applicationStatusLabel($initialStatus)],
                    ])),
                    'action_url' => ApplicationMailLinks::absolute('/panel/applications'),
                    'action_text' => 'Başvuruları görüntüle',
                    'plain_text' => "Proje: {$project->name}".($period ? "\nDönem: {$period->name}" : '').($program ? "\nProgram: {$program->title}" : '')."\nDurum: ".$this->applicationStatusLabel($initialStatus)."\nYeni başvuru: #{$application->id}".(ApplicationMailLinks::absolute('/panel/applications') ? "\nBaşvuru yönetimi: ".ApplicationMailLinks::absolute('/panel/applications') : ''),
                ],
                $project->id,
                $application->user_id,
                $application->id
            ) > 0;
        }

        return ['applicant_email_sent' => $applicantEmailSent, 'coordinators_email_sent' => $coordinatorsEmailSent];
    }

    private function formEntriesForStudent(Application $application): array
    {
        $fields = collect($application->form_fields_snapshot ?? $application->form?->fields ?? [])
            ->mapWithKeys(function (array $field) {
                $id = $field['id'] ?? $field['key'] ?? null;

                return $id ? [$id => $field] : [];
            });

        return collect($application->form_data ?? [])
            ->map(function (mixed $value, string $key) use ($fields, $application) {
                $field = $fields->get($key, []);
                $isFile = is_array($value) && isset($value['path']);

                return [
                    'id' => $key,
                    'label' => $field['label'] ?? $key,
                    'type' => $field['type'] ?? ($isFile ? 'file' : 'text'),
                    'value' => $isFile ? null : $value,
                    'file' => $isFile ? [
                        'original_name' => $value['original_name'] ?? basename((string) $value['path']),
                        'mime_type' => $value['mime_type'] ?? null,
                        'size' => $value['size'] ?? null,
                        'download_url' => "/applications/{$application->id}/form-files/".rawurlencode($key),
                    ] : null,
                ];
            })
            ->values()
            ->all();
    }

    private function formatStudentApplication(Application $application): array
    {
        return [
            'id' => $application->id,
            'project' => $application->project,
            'period' => $application->period,
            'program' => $application->program,
            'training' => $application->training?->only(['id', 'title']),
            'status' => $application->status,
            'waitlist_order' => $application->waitlist_order,
            'waitlist_invited_at' => optional($application->waitlist_invited_at)?->toISOString(),
            'waitlist_invitation_expires_at' => optional($application->waitlist_invitation_expires_at)?->toISOString(),
            'waitlist_invitation_delivery_status' => $application->waitlist_invitation_delivery_status,
            'waitlist_invitation_active' => $application->status === 'waitlisted'
                && $application->waitlist_invited_at !== null
                && ! in_array($application->waitlist_invitation_delivery_status, ['pending', 'failed', 'unknown'], true)
                && ($application->waitlist_invitation_expires_at === null || $application->waitlist_invitation_expires_at->isFuture()),
            'created_at' => optional($application->created_at)?->toISOString(),
            'interview_at' => optional($application->interview_at)?->toISOString(),
            'rejection_reason' => $application->rejection_reason,
            'auto_rejected' => $application->status === 'rejected' && (bool) $application->auto_rejected,
            'auto_rejection_reason' => $application->status === 'rejected' ? $application->auto_rejection_reason : null,
            'form_entries' => $this->formEntriesForStudent($application),
            'consent_text_snapshot' => $application->consent_text_snapshot,
            'consent_accepted_at' => optional($application->consent_accepted_at)?->toISOString(),
        ];
    }

    private function applicationForResponse(Application $application): array
    {
        $data = $application->toArray();
        unset(
            $data['screening_review_reason'],
            $data['auto_rejection_corrected_at'],
            $data['auto_rejection_corrected_by'],
            $data['auto_rejection_corrected_by_name'],
            $data['auto_rejection_correction_reason'],
        );
        $data['form_data'] = collect($application->form_data ?? [])
            ->map(fn (mixed $value) => is_array($value) && isset($value['path'])
                ? array_diff_key($value, array_flip(['path', 'url', 'storage']))
                : $value)
            ->all();

        return $data;
    }

    private function assertWaitlistInvitationOpen(Application $application): void
    {
        if ($application->status !== 'waitlisted'
            || ! $application->waitlist_invited_at
            || in_array($application->waitlist_invitation_delivery_status, ['pending', 'failed', 'unknown', 'expired'], true)) {
            throw ValidationException::withMessages([
                'application' => ['Bu basvuru icin aktif bir yedek liste daveti bulunmuyor.'],
            ]);
        }

        if ($application->waitlist_invitation_expires_at && now()->greaterThanOrEqualTo($application->waitlist_invitation_expires_at)) {
            $application->update([
                'waitlist_invitation_delivery_status' => 'expired',
            ]);

            throw ValidationException::withMessages([
                'application' => ['Yedek liste davet suresi doldu.'],
            ]);
        }
    }

    /**
     * List the authenticated user applications.
     *
     * Requires KVKK consent. Returns the current user applications with project, period, program, waitlist, interview, rejection, and dynamic form entry summaries.
     *
     * @group Applications
     *
     * @authenticated
     *
     * @response 200 {"applications":[{"id":1,"status":"pending","waitlist_invitation_active":false,"project":{"id":1,"name":"KADEME"},"form_entries":[{"id":"motivation","label":"Motivasyon","type":"text","value":"Katiliyorum","file":null}]}]}
     * @response 401 {"message":"Unauthenticated."}
     * @response 403 {"message":"KVKK onayi gereklidir."}
     */
    public function myApplications(Request $request)
    {
        $applications = Application::where('user_id', $request->user()->id)
            ->with(['project', 'period', 'training:id,title', 'program:id,title,start_at', 'form:id,fields'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (Application $application) => $this->formatStudentApplication($application));

        return response()->json([
            'applications' => $applications,
        ]);
    }

    private function validateDynamicFields(?ApplicationForm $form, array $formData, array $formFiles = [], array &$uploadedPaths = []): array
    {
        foreach ($formData as $fieldId => $value) {
            if (is_array($value) && array_key_exists('path', $value)) {
                throw ValidationException::withMessages([
                    $fieldId => ['Başvuru dosyası için yeni bir dosya yükleyin.'],
                ]);
            }
        }

        if (! $form) {
            return $formData;
        }

        $errors = [];
        $normalized = [];

        foreach (($form->fields ?? []) as $field) {
            $fieldId = $field['id'] ?? $field['key'] ?? null;
            if (! $fieldId) {
                continue;
            }

            $label = $field['label'] ?? $fieldId;
            $type = $field['type'] ?? 'text';
            $required = (bool) ($field['required'] ?? false);
            $uploadedFile = $formFiles[$fieldId] ?? null;
            $value = $uploadedFile ?: ($formData[$fieldId] ?? null);

            if ($required) {
                $isEmpty =
                    $value === null ||
                    $value === '' ||
                    (is_array($value) && count(array_filter($value, fn ($item) => $item !== null && $item !== '')) === 0);

                if ($isEmpty) {
                    $errors[$fieldId] = [$label.' alani zorunludur.'];

                    continue;
                }
            }

            if ($value === null || $value === '') {
                $normalized[$fieldId] = $type === 'checkbox' ? [] : $value;

                continue;
            }

            if ($type === 'file') {
                if ($uploadedFile instanceof UploadedFile) {
                    $path = ApplicationFileStorage::putFile($uploadedFile);
                    $uploadedPaths[] = $path;
                    $normalized[$fieldId] = $this->fileMetadata($path, $uploadedFile);

                    continue;
                }

                $errors[$fieldId] = [$label.' icin yeni bir dosya yukleyin.'];

                continue;
            }

            if (in_array($type, ['checkbox'], true)) {
                if (! is_array($value)) {
                    $errors[$fieldId] = [$label.' icin birden fazla secim dizisi bekleniyor.'];

                    continue;
                }

                $choices = array_values(array_filter($value, fn ($item) => $item !== null && $item !== ''));
                if (array_filter($choices, fn ($item) => ! is_string($item) || ! in_array($item, $field['options'] ?? [], true))) {
                    $errors[$fieldId] = [$label.' icin gecerli secenekleri secin.'];

                    continue;
                }
                $normalized[$fieldId] = array_values(array_unique($choices));

                continue;
            }

            if (! is_string($value)) {
                $errors[$fieldId] = [$label.' icin gecerli bir cevap girin.'];

                continue;
            }

            if (in_array($type, ['select', 'radio'], true) && isset($field['options']) && is_array($field['options'])) {
                if (! in_array($value, $field['options'], true)) {
                    $errors[$fieldId] = [$label.' icin gecerli bir secim yapin.'];

                    continue;
                }
            }

            $normalized[$fieldId] = is_string($value) ? trim($value) : $value;
        }

        foreach (($form->auto_reject_rules ?? []) as $rule) {
            if (! in_array($rule['operator'] ?? null, ['gt', 'lt', 'gte', 'lte'], true)) {
                continue;
            }
            $fieldId = $rule['field'] ?? $rule['field_id'] ?? null;
            $answer = $normalized[$fieldId] ?? null;
            if ($answer !== null && $answer !== '' && ! is_numeric($answer)) {
                $errors[$fieldId] = ['Bu soru için sayısal bir cevap girin.'];
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    private function nextWaitlistOrder(Project $project, Period $period, ?Program $program = null): int
    {
        $max = Application::query()
            ->where('project_id', $project->id)
            ->where('period_id', $period->id)
            ->when($program, fn ($query) => $query->where('program_id', $program->id), fn ($query) => $query->whereNull('program_id'))
            ->where('status', 'waitlisted')
            ->max('waitlist_order');

        return ((int) $max) + 1;
    }

    /**
     * Create an authenticated project application.
     *
     * Requires KVKK consent. Dynamic application form fields are accepted in `form_data`; file fields must be uploaded as `multipart/form-data` under `form_files[field_id]`.
     *
     * @group Applications
     *
     * @authenticated
     *
     * @bodyParam project_id integer required Project id. Example: 1
     * @bodyParam program_id integer Optional program id. Example: 5
     * @bodyParam form_data object Optional dynamic form answers keyed by field id. Example: {"motivation":"Projeye katilmak istiyorum"}
     * @bodyParam form_files object Optional dynamic form files keyed by field id.
     * @bodyParam consent_accepted boolean Required acceptance of the displayed application terms. Example: true
     * @bodyParam expected_consent_text string Optional exact terms shown by the current public form. A changed text requires reopening the form.
     *
     * @response 201 {"message":"Basvurunuz basariyla alindi.","application":{"id":1,"project_id":1,"status":"pending"}}
     * @response 422 {"message":"Bu proje icin basvurular su an kapali.","errors":{"project_id":["Bu proje icin basvurular su an kapali."]}}
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'program_id' => 'nullable|exists:programs,id',
            'training_id' => 'nullable|integer|exists:project_trainings,id',
            'application_form_id' => 'sometimes|integer|min:0',
            'expected_consent_text' => 'sometimes|string|max:10000',
            'form_data' => 'nullable|array',
            'form_files' => 'nullable|array',
            'form_files.*' => 'file|max:20480',
            'consent_accepted' => 'nullable|boolean',
        ]);

        $project = Project::findOrFail($validated['project_id']);
        $this->ensureProjectAcceptsApplications($project);

        [$application, $followUp] = $this->createApplicationForUser(
            $request->user(),
            $project,
            $validated['form_data'] ?? [],
            $request->file('form_files', []),
            (bool) ($validated['consent_accepted'] ?? false),
            isset($validated['program_id']) ? (int) $validated['program_id'] : null,
            null,
            isset($validated['application_form_id']) ? (int) $validated['application_form_id'] : null,
            $validated['expected_consent_text'] ?? null,
            false,
            isset($validated['training_id']) ? (int) $validated['training_id'] : null
        );

        return response()->json([
            'message' => 'Basvurunuz basariyla alindi.',
            'application' => $this->applicationForResponse($application),
            'follow_up' => $followUp,
        ], 201);
    }

    /**
     * Create a public project application as a guest applicant.
     *
     * @group Applications
     *
     * @unauthenticated
     *
     * This endpoint accepts verified guest candidates without creating a login account. Accounts are created only on acceptance; existing accounts and roles are preserved. File fields use `multipart/form-data` under `form_files[field_id]`.
     *
     * @bodyParam project_id integer required Project id. Example: 1
     * @bodyParam program_id integer Optional program id. Example: 5
     * @bodyParam form_data object Optional dynamic form answers keyed by field id. Example: {"motivation":"Projeye katilmak istiyorum"}
     * @bodyParam form_files object Optional dynamic form files keyed by field id.
     * @bodyParam consent_accepted boolean Required acceptance of the displayed application terms. Example: true
     * @bodyParam expected_consent_text string Optional exact terms shown by the current public form. A changed text requires reopening the form.
     * @bodyParam applicant.name string required Applicant first name. Example: Hakan
     * @bodyParam applicant.surname string required Applicant last name. Example: Kekec
     * @bodyParam applicant.email string required Applicant email. Example: hakan@example.com
     * @bodyParam applicant.phone string Optional applicant phone. Example: 05551234567
     *
     * @response 201 {"message":"Basvurunuz basariyla alindi.","application":{"id":1,"project_id":1,"status":"pending","form_data":{"motivation":"Projeye katilmak istiyorum"}}}
     * @response 422 {"message":"Bu proje icin basvurular su an kapali.","errors":{"project_id":["Bu proje icin basvurular su an kapali."]}}
     * @response 422 {"message":"The applicant.email field must be a valid email address.","errors":{"applicant.email":["The applicant.email field must be a valid email address."]}}
     */
    public function storePublic(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'program_id' => 'nullable|exists:programs,id',
            'training_id' => 'nullable|integer|exists:project_trainings,id',
            'application_form_id' => 'sometimes|integer|min:0',
            'expected_consent_text' => 'sometimes|string|max:10000',
            'form_data' => 'nullable|array',
            'form_files' => 'nullable|array',
            'form_files.*' => 'file|max:20480',
            'consent_accepted' => 'nullable|boolean',
            'applicant.name' => 'required|string|max:255',
            'applicant.surname' => 'required|string|max:255',
            'applicant.email' => 'required|email|max:255',
            'applicant.phone' => 'nullable|string|max:30',
            'verification_code' => ['required', 'regex:/^[0-9]{8}$/'],
        ]);

        $project = Project::findOrFail($validated['project_id']);
        $this->ensureProjectAcceptsApplications($project, true);
        $email = Str::lower(trim($validated['applicant']['email']));
        $this->emailVerificationService->assertCode($project->id, $email, $validated['verification_code']);
        [$application, $followUp] = $this->createApplicationForUser(
            fn (): ApplicationCandidate => $this->resolveApplicantUser($validated['applicant']),
            $project,
            $validated['form_data'] ?? [],
            $request->file('form_files', []),
            (bool) ($validated['consent_accepted'] ?? false),
            isset($validated['program_id']) ? (int) $validated['program_id'] : null,
            $validated['verification_code'],
            isset($validated['application_form_id']) ? (int) $validated['application_form_id'] : null,
            $validated['expected_consent_text'] ?? null,
            true,
            isset($validated['training_id']) ? (int) $validated['training_id'] : null
        );

        return response()->json([
            'message' => 'Basvurunuz basariyla alindi.',
            'tracking_url' => app(ApplicationTrackingService::class)->issue($application),
            'application' => $this->applicationForResponse($application),
            'follow_up' => $followUp,
        ], 201);
    }

    public function requestPublicVerification(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'email' => 'required|email|max:255',
        ]);

        $project = Project::findOrFail($validated['project_id']);
        $this->ensureProjectAcceptsApplications($project, true);
        $this->emailVerificationService->sendCode($project, Str::lower(trim($validated['email'])));

        return response()->json(['message' => 'Doğrulama kodu e-posta adresinize gönderildiyse gelen kutunuzu kontrol edin.']);
    }

    /**
     * Get an authenticated user application detail.
     *
     * Returns only applications owned by the current user. Requires KVKK consent.
     *
     * @group Applications
     *
     * @authenticated
     *
     * @urlParam id integer required Application id. Example: 1
     *
     * @response 200 {"application":{"id":1,"status":"pending","project":{"id":1,"name":"KADEME"},"form_entries":[]}}
     * @response 404 {"message":"No query results for model [App\\Models\\Application]."}
     */
    public function show($id, Request $request)
    {
        $application = Application::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->with(['project', 'period', 'program:id,title,start_at', 'form:id,fields'])
            ->firstOrFail();

        return response()->json([
            'application' => $this->formatStudentApplication($application),
        ]);
    }

    public function downloadFormFile(Request $request, int $id, string $field): StreamedResponse
    {
        $application = Application::query()
            ->whereKey($id)
            ->where('user_id', $request->user()->id)
            ->with('form:id,fields')
            ->firstOrFail();
        $fieldKey = rawurldecode($field);
        $file = ApplicationFileStorage::fileForField($application, $fieldKey);
        abort_unless($file, 404, 'Başvuru dosyası bulunamadı.');

        return ApplicationFileStorage::download($file, 'basvuru_dosyasi_'.$application->id);
    }

    /**
     * Respond to a waitlist invitation.
     *
     * Allows the current applicant to accept or reject an active waitlist invitation. Acceptance creates missing participation when quota rules allow it and preserves existing participation records.
     *
     * @group Applications
     *
     * @authenticated
     *
     * @urlParam id integer required Application id. Example: 1
     *
     * @bodyParam decision string required Must be `accept` or `reject`. Example: accept
     *
     * @response 200 {"message":"Yedek liste daveti kabul edildi.","application":{"id":1,"status":"accepted","waitlist_invitation_active":false}}
     * @response 422 {"message":"Yedek liste davet suresi doldu.","errors":{"application":["Yedek liste davet suresi doldu."]}}
     */
    public function respondWaitlistInvitation(Request $request, int $id, ?Application $trackedApplication = null)
    {
        $validated = $request->validate([
            'decision' => 'required|in:accept,reject',
        ]);

        $application = $trackedApplication ?? Application::query()
            ->with(['project:id,name,quota', 'period:id,credit_start_amount,status', 'applicationWindow:id,quota', 'program:id,title,application_quota', 'user:id,email,role,status'])
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $invitationError = null;
        $application = $this->applicationDecisionService->runLocked($application, function (Application $application) use ($request, $validated, &$invitationError) {
            $this->assertPeriodResolvable($request, $application->period_id);
            try {
                $this->assertWaitlistInvitationOpen($application);
            } catch (ValidationException $exception) {
                // Keep expired invitation cleanup, but do not record a decision.
                $invitationError = $exception;

                return;
            }

            if ($validated['decision'] === 'accept') {
                $this->applicationScheduleService->assertNoConflict(
                    (int) $application->user_id,
                    $application->program,
                    (int) $application->id,
                    'decision',
                );
                $this->applicationEnrollmentService->enroll($application, 'decision');

                $application->update([
                    'status' => 'accepted',
                    'waitlist_invited_at' => null,
                    'waitlist_invitation_expires_at' => null,
                    'rejection_reason' => null,
                ]);
            } else {
                $application->update([
                    'status' => 'rejected',
                    'rejection_reason' => 'Yedek liste daveti aday tarafindan reddedildi.',
                    'waitlist_invited_at' => null,
                    'waitlist_invitation_expires_at' => null,
                ]);
            }
        });

        if ($invitationError !== null) {
            throw $invitationError;
        }

        $activationLinkSent = null;
        if ($validated['decision'] === 'accept' && $application->accountCreatedOnAcceptance) {
            try {
                $activationLinkSent = Password::sendResetLink(['email' => $application->user->email]) === Password::RESET_LINK_SENT;
            } catch (\Throwable $exception) {
                $activationLinkSent = false;
                Log::warning('application.activation_link_failed', ['application_id' => $application->id, 'error' => $exception->getMessage()]);
            }
        }

        $nextWaitlistChecked = null;
        if ($validated['decision'] === 'reject') {
            try {
                $this->waitlistService->inviteNextIfSeatAvailable($application);
                $nextWaitlistChecked = true;
            } catch (\Throwable $exception) {
                $nextWaitlistChecked = false;
                Log::warning('application.next_waitlist_check_failed', [
                    'application_id' => $application->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $application->loadMissing(['project:id,name', 'period', 'program:id,title,start_at', 'form:id,fields', 'user:id,email,name,surname,role']);
        $applicantUrl = app(ApplicationTrackingService::class)->issue($application);

        $applicantEmailSent = $this->sendApplicationEmail(
            array_filter([$application->applicant()?->email]),
            $validated['decision'] === 'accept' ? 'Yedek liste davetiniz kabul edildi' : 'Yedek liste davetiniz reddedildi',
            [
                'title' => $validated['decision'] === 'accept' ? 'Davet Kabul Edildi' : 'Davet Reddedildi',
                'preheader' => 'Yedek liste daveti yanıtınız kaydedildi.',
                'intro' => $validated['decision'] === 'accept'
                    ? 'Yedek liste davetiniz kabul edildi ve başvurunuz onaylandı.'
                    : 'Yedek liste davetini reddettiğiniz kaydedildi.',
                'lines' => array_values(array_filter([
                    ['label' => 'Proje', 'value' => $application->project?->name ?? '-'],
                    $application->period ? ['label' => 'Dönem', 'value' => $application->period->name] : null,
                    $application->program ? ['label' => 'Program', 'value' => $application->program->title] : null,
                    ['label' => 'Durum', 'value' => $this->applicationStatusLabel((string) $application->status)],
                ])),
                'action_url' => $applicantUrl,
                'action_text' => $applicantUrl ? 'Başvurularımı görüntüle' : null,
                'plain_text' => 'Proje: '.($application->project?->name ?? '-').($application->period ? "\nDönem: {$application->period->name}" : '').($application->program ? "\nProgram: {$application->program->title}" : '')."\nDurum: ".$this->applicationStatusLabel((string) $application->status).($applicantUrl ? "\nBaşvurularım: {$applicantUrl}" : ''),
            ],
            $application->project_id,
            $request->user()?->id,
            $application->id
        ) > 0;

        $coordinatorEmails = $application->project
            ? $this->applicationCoordinatorEmails($application->project, $application->id)
            : [];
        $coordinatorsEmailSent = $coordinatorEmails === null ? false : null;
        if ($coordinatorEmails !== null && $coordinatorEmails !== []) {
            $coordinatorsEmailSent = $this->sendApplicationEmail(
                $coordinatorEmails,
                'Yedek liste daveti yanıtlandı',
                [
                    'title' => 'Yedek Liste Daveti Yanıtlandı',
                    'preheader' => 'Bir aday yedek liste davetine yanit verdi.',
                    'intro' => 'Yedek liste daveti yanıtı sisteme kaydedildi.',
                    'lines' => array_values(array_filter([
                        ['label' => 'Proje', 'value' => $application->project?->name ?? '-'],
                        $application->period ? ['label' => 'Dönem', 'value' => $application->period->name] : null,
                        $application->program ? ['label' => 'Program', 'value' => $application->program->title] : null,
                        ['label' => 'Aday', 'value' => trim(($application->user?->name ?? '').' '.($application->user?->surname ?? ''))],
                        ['label' => 'Yanıt', 'value' => $validated['decision'] === 'accept' ? 'Kabul' : 'Red'],
                    ])),
                    'action_url' => ApplicationMailLinks::absolute('/panel/applications'),
                    'action_text' => 'Başvuruları görüntüle',
                    'plain_text' => 'Proje: '.($application->project?->name ?? '-')."\nAday: ".trim(($application->user?->name ?? '').' '.($application->user?->surname ?? ''))."\nYanıt: ".($validated['decision'] === 'accept' ? 'Kabul' : 'Red'),
                ],
                $application->project_id,
                $request->user()?->id,
                $application->id
            ) > 0;
        }

        return response()->json([
            'message' => $validated['decision'] === 'accept'
                ? 'Yedek liste daveti kabul edildi.'
                : 'Yedek liste daveti reddedildi.',
            'application' => $this->formatStudentApplication($application),
            'follow_up' => [
                'applicant_email_sent' => $applicantEmailSent,
                'coordinators_email_sent' => $coordinatorsEmailSent,
                'next_waitlist_checked' => $nextWaitlistChecked,
                'activation_link_sent' => $activationLinkSent,
            ],
        ]);
    }
}
