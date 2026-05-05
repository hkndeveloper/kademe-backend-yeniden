<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationForm;
use App\Models\Participant;
use App\Models\Period;
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
            'phone' => $applicant['phone'] ? trim((string) $applicant['phone']) : null,
            'password' => Hash::make(Str::random(32)),
            'role' => 'student',
            'status' => 'active',
            'email_verified_at' => now(),
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

    private function createApplicationForUser(User $user, Project $project, array $formData, array $formFiles = []): Application
    {
        $this->ensureSingleProjectRule($user, $project);

        $currentPeriod = Period::where('project_id', $project->id)
            ->where('status', 'active')
            ->first();

        if (! $currentPeriod) {
            throw ValidationException::withMessages([
                'project_id' => ['Bu proje icin aktif bir donem bulunamadi.'],
            ]);
        }

        $existingApp = Application::where('user_id', $user->id)
            ->where('project_id', $project->id)
            ->where('period_id', $currentPeriod->id)
            ->first();

        if ($existingApp) {
            throw ValidationException::withMessages([
                'project_id' => ['Bu projeye ve doneme ait zaten bir basvurunuz bulunuyor.'],
            ]);
        }

        $form = ApplicationForm::where('project_id', $project->id)
            ->where('is_active', true)
            ->latest()
            ->first();

        $normalizedFormData = $this->validateDynamicFields($form, Arr::wrap($formData), $formFiles);

        $application = Application::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'period_id' => $currentPeriod->id,
            'application_form_id' => $form?->id,
            'form_data' => $normalizedFormData,
            'status' => 'pending',
        ]);

        $this->notificationService->sendEmail(
            array_filter([$user->email]),
            'Basvurunuz alindi',
            "Proje: {$project->name}\nBasvurunuz basariyla alindi. Degerlendirme sureci tamamlandiginda bilgilendirileceksiniz.",
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

    /**
     * Kullanicinin kendi basvurularini listelemesi
     */
    public function myApplications(Request $request)
    {
        $applications = Application::where('user_id', $request->user()->id)
            ->with(['project', 'period'])
            ->orderBy('created_at', 'desc')
            ->get();

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

    /**
     * Bir projeye yeni basvuru yapma
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'form_data' => 'nullable|array',
            'form_files' => 'nullable|array',
            'form_files.*' => 'file|max:20480',
        ]);

        $project = Project::findOrFail($validated['project_id']);

        if (! $project->application_open) {
            throw ValidationException::withMessages([
                'project_id' => ['Bu proje icin basvurular su an kapali.'],
            ]);
        }

        $application = $this->createApplicationForUser($request->user(), $project, $validated['form_data'] ?? [], $request->file('form_files', []));

        return response()->json([
            'message' => 'Basvurunuz basariyla alindi.',
            'application' => $application,
        ], 201);
    }

    public function storePublic(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'form_data' => 'nullable|array',
            'form_files' => 'nullable|array',
            'form_files.*' => 'file|max:20480',
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
        $application = $this->createApplicationForUser($user, $project, $validated['form_data'] ?? [], $request->file('form_files', []));

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
            ->with(['project', 'period'])
            ->firstOrFail();

        return response()->json([
            'application' => $application,
        ]);
    }
}
