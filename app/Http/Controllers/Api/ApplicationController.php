<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationForm;
use App\Models\Participant;
use App\Models\Period;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Support\MediaStorage;

class ApplicationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService
    ) {
    }

    private function resolveApplicantUser(array $applicant): User
    {
        $email = Str::lower(trim($applicant['email']));
        $existing = User::where('email', $email)->first();

        if ($existing) {
            return $existing;
        }

        $user = User::create([
            'name' => trim($applicant['name']),
            'surname' => trim($applicant['surname']),
            'email' => $email,
            'phone' => ! empty($applicant['phone']) ? trim((string) $applicant['phone']) : null,
            'password' => Hash::make(Str::random(32)),
            'role' => 'student',
            'status' => 'active',
            'email_verified_at' => now(),
            'must_change_password' => false,
        ]);

        $user->syncRoles(['student']);

        return $user;
    }

    private function ensureSingleProjectRule(User $user, Project $project): void
    {
        $activeParticipationExists = Participant::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where('project_id', '!=', $project->id)
            ->exists();

        if ($activeParticipationExists) {
            throw ValidationException::withMessages([
                'project_id' => ['Aktif olarak baska bir projede yer aldiginiz icin bu projeye basvuru yapamazsiniz.'],
            ]);
        }
    }

    private function ensureUserCanApply(User $user): void
    {
        if ($user->status === 'blacklisted' && (! $user->blacklisted_until || now()->isBefore($user->blacklisted_until))) {
            throw ValidationException::withMessages([
                'user' => ['Kara listede oldugunuz icin su anda basvuru yapamazsiniz.'],
            ]);
        }
    }

    private function projectPeriodHasAvailableSeat(Project $project, Period $period, ?Program $program = null): bool
    {
        $quota = $program?->application_quota ?? $project->quota;
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

    private function fileMetadata(string $path, \Illuminate\Http\UploadedFile $file): array
    {
        return [
            'path' => $path,
            'url' => MediaStorage::url($path),
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ];
    }

    private function createApplicationForUser(User $user, Project $project, array $formData, array $formFiles = [], bool $consentAccepted = false, ?int $programId = null): Application
    {
        $this->ensureSingleProjectRule($user, $project);
        $this->ensureUserCanApply($user);

        $currentPeriod = Period::where('project_id', $project->id)
            ->where('status', 'active')
            ->first();

        if (! $currentPeriod) {
            throw ValidationException::withMessages([
                'project_id' => ['Bu proje icin aktif bir donem bulunamadi.'],
            ]);
        }

        $program = null;
        if ($programId !== null) {
            $program = Program::query()
                ->where('id', $programId)
                ->where('project_id', $project->id)
                ->where('period_id', $currentPeriod->id)
                ->whereIn('status', ['scheduled', 'active'])
                ->first();

            if (! $program) {
                throw ValidationException::withMessages([
                    'program_id' => ['Secilen program bu proje/donem icin basvuruya uygun degil.'],
                ]);
            }
        }

        $existingApp = Application::where('user_id', $user->id)
            ->where('project_id', $project->id)
            ->where('period_id', $currentPeriod->id)
            ->when($program, fn ($query) => $query->where('program_id', $program->id), fn ($query) => $query->whereNull('program_id'))
            ->first();

        if ($existingApp) {
            throw ValidationException::withMessages([
                'project_id' => ['Bu projeye ve doneme ait zaten bir basvurunuz bulunuyor.'],
            ]);
        }

        $form = $this->activeFormForPeriod($project, $currentPeriod, $program);

        if ($form?->require_consent && ! $consentAccepted) {
            throw ValidationException::withMessages([
                'consent_accepted' => ['Basvuru kosullarini kabul etmeniz gerekiyor.'],
            ]);
        }

        $normalizedFormData = $this->validateDynamicFields($form, Arr::wrap($formData), $formFiles);
        $autoRejectReason = $this->autoRejectReason($form, $normalizedFormData, $user);
        $initialStatus = $autoRejectReason
            ? 'rejected'
            : ($this->projectPeriodHasAvailableSeat($project, $currentPeriod, $program) ? 'pending' : 'waitlisted');
        $waitlistOrder = $initialStatus === 'waitlisted'
            ? $this->nextWaitlistOrder($project, $currentPeriod, $program)
            : null;

        $application = Application::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'period_id' => $currentPeriod->id,
            'program_id' => $program?->id,
            'application_form_id' => $form?->id,
            'form_data' => $normalizedFormData,
            'status' => $initialStatus,
            'waitlist_order' => $waitlistOrder,
            'auto_rejected' => (bool) $autoRejectReason,
            'auto_rejection_reason' => $autoRejectReason,
            'rejection_reason' => $autoRejectReason,
        ]);

        $this->notificationService->sendEmail(
            array_filter([$user->email]),
            'Basvurunuz alindi',
            "Proje: {$project->name}\n" .
            ($program ? "Program: {$program->title}\n" : '') .
            ($autoRejectReason
                ? "Basvurunuz otomatik degerlendirme kurali nedeniyle reddedildi: {$autoRejectReason}"
                : "Basvurunuz basariyla alindi. Degerlendirme sureci tamamlandiginda bilgilendirileceksiniz." . ($initialStatus === 'waitlisted' ? "\nKontenjan dolu oldugu icin basvurunuz yedek listeye alindi." : '')),
            $project->id,
            $user->id
        );

        $project->loadMissing('coordinators:id,email,name,surname');
        $coordinatorEmails = $project->coordinators
            ->pluck('email')
            ->filter()
            ->values()
            ->all();

        if ($coordinatorEmails !== []) {
            $this->notificationService->sendEmail(
                $coordinatorEmails,
                'Yeni basvuru alindi',
                "Proje: {$project->name}\nYeni bir basvuru sisteme dustu. Basvuru ID: {$application->id}",
                $project->id,
                $user->id
            );
        }

        return $application;
    }

    private function activeFormForPeriod(Project $project, ?Period $period, ?Program $program = null): ?ApplicationForm
    {
        if ($program) {
            $programForm = ApplicationForm::where('project_id', $project->id)
                ->where('program_id', $program->id)
                ->where('is_active', true)
                ->latest()
                ->first();

            if ($programForm) {
                return $programForm;
            }
        }

        if ($period) {
            $periodForm = ApplicationForm::where('project_id', $project->id)
                ->where('period_id', $period->id)
                ->where('is_active', true)
                ->latest()
                ->first();

            if ($periodForm) {
                return $periodForm;
            }
        }

        return ApplicationForm::where('project_id', $project->id)
            ->whereNull('period_id')
            ->where('is_active', true)
            ->latest()
            ->first();
    }

    private function formEntriesForStudent(Application $application): array
    {
        $fields = collect($application->form?->fields ?? [])
            ->mapWithKeys(function (array $field) {
                $id = $field['id'] ?? $field['key'] ?? null;

                return $id ? [$id => $field] : [];
            });

        return collect($application->form_data ?? [])
            ->map(function (mixed $value, string $key) use ($fields) {
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
            'status' => $application->status,
            'waitlist_order' => $application->waitlist_order,
            'waitlist_invited_at' => optional($application->waitlist_invited_at)?->toISOString(),
            'waitlist_invitation_expires_at' => optional($application->waitlist_invitation_expires_at)?->toISOString(),
            'created_at' => optional($application->created_at)?->toISOString(),
            'interview_at' => optional($application->interview_at)?->toISOString(),
            'rejection_reason' => $application->rejection_reason,
            'auto_rejected' => (bool) $application->auto_rejected,
            'auto_rejection_reason' => $application->auto_rejection_reason,
            'form_entries' => $this->formEntriesForStudent($application),
        ];
    }

    /**
     * Kullanicinin kendi basvurularini listelemesi
     */
    public function myApplications(Request $request)
    {
        $applications = Application::where('user_id', $request->user()->id)
            ->with(['project', 'period', 'program:id,title,start_at', 'form:id,fields'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (Application $application) => $this->formatStudentApplication($application));

        return response()->json([
            'applications' => $applications,
        ]);
    }

    private function validateDynamicFields(?ApplicationForm $form, array $formData, array $formFiles = []): array
    {
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
                    $errors[$fieldId] = [$label . ' alani zorunludur.'];
                    continue;
                }
            }

            if ($value === null || $value === '') {
                $normalized[$fieldId] = $type === 'checkbox' ? [] : $value;
                continue;
            }

            if ($type === 'file') {
                if ($uploadedFile instanceof \Illuminate\Http\UploadedFile) {
                    $path = MediaStorage::putFile('application-files', $uploadedFile);
                    $normalized[$fieldId] = $this->fileMetadata($path, $uploadedFile);
                    continue;
                }

                if (is_array($value) && isset($value['path'])) {
                    $normalized[$fieldId] = $value;
                    continue;
                }

                if (is_string($value)) {
                    $normalized[$fieldId] = trim($value);
                    continue;
                }

                $errors[$fieldId] = [$label . ' icin gecerli bir dosya bekleniyor.'];
                continue;
            }

            if (in_array($type, ['checkbox'], true)) {
                if (! is_array($value)) {
                    $errors[$fieldId] = [$label . ' icin birden fazla secim dizisi bekleniyor.'];
                    continue;
                }

                $normalized[$fieldId] = array_values(array_filter($value, fn ($item) => $item !== null && $item !== ''));
                continue;
            }

            if (in_array($type, ['select', 'radio'], true) && isset($field['options']) && is_array($field['options'])) {
                if (! in_array($value, $field['options'], true)) {
                    $errors[$fieldId] = [$label . ' icin gecerli bir secim yapin.'];
                    continue;
                }
            }

            $normalized[$fieldId] = is_string($value) ? trim($value) : $value;
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    private function autoRejectReason(?ApplicationForm $form, array $formData, User $user): ?string
    {
        foreach (($form?->auto_reject_rules ?? []) as $rule) {
            $field = $rule['field'] ?? null;
            $operator = $rule['operator'] ?? 'equals';
            if (! is_string($field) || $field === '') {
                continue;
            }

            $actual = match ($field) {
                'email' => $user->email,
                'phone' => $user->phone,
                default => $formData[$field] ?? null,
            };
            $expected = $rule['value'] ?? null;
            $matched = match ($operator) {
                'not_equals' => (string) $actual !== (string) $expected,
                'in' => is_array($expected) && in_array($actual, $expected, true),
                'not_in' => is_array($expected) && ! in_array($actual, $expected, true),
                'empty' => $actual === null || $actual === '' || $actual === [],
                'not_empty' => ! ($actual === null || $actual === '' || $actual === []),
                default => (string) $actual === (string) $expected,
            };

            if ($matched) {
                return trim((string) ($rule['message'] ?? 'Basvurunuz kriter uyumsuzlugu nedeniyle reddedilmistir.'));
            }
        }

        return null;
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
     * Bir projeye yeni basvuru yapma
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'program_id' => 'nullable|exists:programs,id',
            'form_data' => 'nullable|array',
            'form_files' => 'nullable|array',
            'form_files.*' => 'file|max:20480',
            'consent_accepted' => 'nullable|boolean',
        ]);

        $project = Project::findOrFail($validated['project_id']);

        if (! $project->application_open) {
            throw ValidationException::withMessages([
                'project_id' => ['Bu proje icin basvurular su an kapali.'],
            ]);
        }

        $application = $this->createApplicationForUser(
            $request->user(),
            $project,
            $validated['form_data'] ?? [],
            $request->file('form_files', []),
            (bool) ($validated['consent_accepted'] ?? false),
            isset($validated['program_id']) ? (int) $validated['program_id'] : null
        );

        return response()->json([
            'message' => 'Basvurunuz basariyla alindi.',
            'application' => $application,
        ], 201);
    }

    public function storePublic(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'program_id' => 'nullable|exists:programs,id',
            'form_data' => 'nullable|array',
            'form_files' => 'nullable|array',
            'form_files.*' => 'file|max:20480',
            'consent_accepted' => 'nullable|boolean',
            'applicant.name' => 'required|string|max:255',
            'applicant.surname' => 'required|string|max:255',
            'applicant.email' => 'required|email|max:255',
            'applicant.phone' => 'nullable|string|max:30',
        ]);

        $project = Project::findOrFail($validated['project_id']);
        if (! $project->application_open) {
            throw ValidationException::withMessages([
                'project_id' => ['Bu proje icin basvurular su an kapali.'],
            ]);
        }

        $user = $this->resolveApplicantUser($validated['applicant']);
        $application = $this->createApplicationForUser(
            $user,
            $project,
            $validated['form_data'] ?? [],
            $request->file('form_files', []),
            (bool) ($validated['consent_accepted'] ?? false),
            isset($validated['program_id']) ? (int) $validated['program_id'] : null
        );

        return response()->json([
            'message' => 'Basvurunuz basariyla alindi.',
            'application' => $application,
        ], 201);
    }

    /**
     * Basvuru detayi
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
}
