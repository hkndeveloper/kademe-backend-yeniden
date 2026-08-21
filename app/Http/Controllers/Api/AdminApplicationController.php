<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Concerns\ResolvesProjectPeriodContext;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Participant;
use App\Services\NotificationService;
use App\Services\PermissionResolver;
use App\Services\WaitlistService;
use App\Support\AdminExportResponder;
use App\Support\MediaStorage;
use App\Support\IstanbulDateTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @group Admin Applications
 */
class AdminApplicationController extends Controller
{
    use AuthorizesGranularPermissions;
    use ResolvesProjectPeriodContext;

    public function __construct(
        private readonly PermissionResolver $permissionResolver,
        private readonly NotificationService $notificationService,
        private readonly WaitlistService $waitlistService,
    ) {}

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

    private function notifyApplicationUser(Application $application, string $subject, string $body, ?int $senderId = null): void
    {
        $email = $application->user?->email;
        if (! $email) {
            return;
        }

        $lines = [
            ['label' => 'Proje', 'value' => $application->project?->name ?? '-'],
            ['label' => 'Durum', 'value' => $this->applicationStatusLabel((string) $application->status)],
        ];

        if ($application->interview_at) {
            $lines[] = ['label' => 'Mulakat tarihi', 'value' => IstanbulDateTime::format($application->interview_at)];
        }
        if ($application->rejection_reason) {
            $lines[] = ['label' => 'Gerekce', 'value' => $application->rejection_reason];
        }

        $this->notificationService->sendTemplatedEmail(
            [$email],
            $subject,
            'emails.application-status',
            [
                'title' => $subject,
                'preheader' => 'Basvuru durumunuz guncellendi.',
                'intro' => $body,
                'lines' => $lines,
                'plain_text' => $body,
            ],
            $application->project_id,
            $senderId
        );
    }

    /** @return int[] */
    private function manageableProjectIdList(Request $request, string $permission): array
    {
        return $this->permissionResolver->projectIdsForPermission($request->user(), $permission);
    }

    private function allowedStatusesFor(Application $application): array
    {
        $hasInterview = $application->usesInterview();

        if (! $hasInterview) {
            return match ($application->status) {
                'pending', 'waitlisted' => ['accepted', 'rejected', 'waitlisted'],
                default => [],
            };
        }

        return match ($application->status) {
            'pending', 'waitlisted' => ['rejected', 'waitlisted', 'interview_planned'],
            'interview_planned' => ['rejected', 'waitlisted', 'interview_passed', 'interview_failed'],
            'interview_passed' => ['accepted', 'rejected', 'waitlisted'],
            'interview_failed' => ['rejected', 'waitlisted'],
            default => [],
        };
    }

    private function assertStatusAllowed(Application $application, string $nextStatus): void
    {
        if (! in_array($nextStatus, $this->allowedStatusesFor($application), true)) {
            throw ValidationException::withMessages([
                'status' => ['Bu basvuru akisi icin secilen durum gecislerine izin verilmiyor.'],
            ]);
        }
    }

    private function assertProjectHasSeatFor(Application $application): void
    {
        $quota = $application->program?->application_quota ?? $application->projectQuota();
        if ($quota === null || (int) $quota <= 0) {
            return;
        }

        if ($application->program?->application_quota !== null) {
            $acceptedCount = Application::query()
                ->where('project_id', $application->project_id)
                ->where('period_id', $application->period_id)
                ->where('program_id', $application->program_id)
                ->where('status', 'accepted')
                ->where('user_id', '!=', $application->user_id)
                ->count();
        } else {
            $acceptedCount = Participant::query()
                ->where('project_id', $application->project_id)
                ->where('period_id', $application->period_id)
                ->where('status', 'active')
                ->where('user_id', '!=', $application->user_id)
                ->count();
        }

        if ($acceptedCount >= (int) $quota) {
            throw ValidationException::withMessages([
                'status' => ['Kontenjan dolu. Basvuruyu kabul etmeden once kontenjan acin veya yedek listede birakin.'],
            ]);
        }
    }

    private function isUrl(string $path): bool
    {
        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://');
    }

    private function formEntries(Application $application): array
    {
        $fields = collect($application->form?->fields ?? [])
            ->mapWithKeys(function (array $field) {
                $id = $field['id'] ?? $field['key'] ?? null;

                return $id ? [$id => $field] : [];
            });

        return collect($application->form_data ?? [])
            ->map(function (mixed $value, string $key) use ($fields, $application) {
                $field = $fields->get($key, []);
                $type = $field['type'] ?? (is_array($value) && isset($value['path']) ? 'file' : 'text');
                $isFile = is_array($value) && isset($value['path']);

                return [
                    'id' => $key,
                    'label' => $field['label'] ?? $key,
                    'type' => $type,
                    'value' => $isFile ? null : $value,
                    'file' => $isFile ? [
                        'original_name' => $value['original_name'] ?? basename((string) $value['path']),
                        'mime_type' => $value['mime_type'] ?? null,
                        'size' => $value['size'] ?? null,
                        'download_url' => "/panel/applications/{$application->id}/form-files/".rawurlencode($key),
                    ] : null,
                ];
            })
            ->values()
            ->all();
    }

    private function formatApplication(Application $application): array
    {
        return [
            'id' => $application->id,
            'user' => $application->user,
            'period' => $application->period,
            'program' => $application->program,
            'project' => $application->project,
            'status' => $application->status,
            'waitlist_order' => $application->waitlist_order,
            'waitlist_invited_at' => optional($application->waitlist_invited_at)?->toISOString(),
            'waitlist_invitation_expires_at' => optional($application->waitlist_invitation_expires_at)?->toISOString(),
            'created_at' => optional($application->created_at)?->toISOString(),
            'interview_at' => optional($application->interview_at)?->toISOString(),
            'evaluation_note' => $application->evaluation_note,
            'rejection_reason' => $application->rejection_reason,
            'form_entries' => $this->formEntries($application),
            'available_statuses' => $this->allowedStatusesFor($application),
            'workflow' => [
                'has_interview' => $application->usesInterview(),
                'next_step' => $this->nextWorkflowStep($application),
            ],
        ];
    }

    private function nextWorkflowStep(Application $application): ?string
    {
        if (! $application->usesInterview()) {
            return $application->status === 'pending' ? 'final_decision' : null;
        }

        return match ($application->status) {
            'pending', 'waitlisted' => 'plan_interview',
            'interview_planned' => 'record_interview_result',
            'interview_passed' => 'final_decision',
            default => null,
        };
    }

    /**
     * Export panel applications.
     *
     * Requires permission: `applications.export`. Project and period filters are resolved through action+scope. Returns a binary CSV/XLSX/PDF/DOCX file depending on `format`. The same method is exposed under `/api/admin/applications/export` and `/api/panel/applications/export` aliases.
     *
     * @authenticated
     * @queryParam project_id integer Optional project filter. Example: 1
     * @queryParam period_id integer Optional period filter. Example: 3
     * @queryParam status string Optional application status filter. Example: accepted
     * @queryParam search string Optional applicant search. Example: ayse
     * @queryParam format string Optional export format: `csv`, `xlsx`, `pdf`, `docx`, `excel` or `word`. Defaults to csv. Example: xlsx
     * @response 200 binary Applications export file.
     * @response 403 {"message":"Bu proje icin yetkiniz yok."}
     */
    public function export(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'nullable|exists:projects,id',
            'period_id' => 'nullable|exists:periods,id',
            'status' => 'nullable|string',
            'search' => 'nullable|string|max:255',
            'format' => 'nullable|string|max:20',
        ]);
        $context = $this->resolveProjectPeriodContext(
            $request,
            'applications.export',
            ! empty($validated['project_id']) ? (int) $validated['project_id'] : null,
            ! empty($validated['period_id']) ? (int) $validated['period_id'] : null,
        );

        $query = Application::query()->with(['user:id,name,surname,email,phone', 'period', 'applicationWindow:id,has_interview,quota', 'program:id,title,start_at', 'project:id,name,has_interview,quota']);
        $this->applyProjectPeriodContext($query, $context);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->whereHas('user', function ($builder) use ($search) {
                $builder
                    ->where('name', 'like', "%$search%")
                    ->orWhere('surname', 'like', "%$search%")
                    ->orWhere('email', 'like', "%$search%");
            });
        }

        $applications = $query->orderByDesc('created_at')->get();

        $headings = ['ID', 'Proje', 'Donem', 'Program', 'Ad', 'Soyad', 'E-posta', 'Telefon', 'Durum', 'Yedek Sira', 'Degerlendirme Notu', 'Ret Nedeni', 'Basvuru Tarihi'];
        $rows = $applications->map(fn (Application $application) => [
            $application->id,
            $application->project->name ?? '-',
            $application->period->name ?? '-',
            $application->program->title ?? '-',
            $application->user->name ?? '-',
            $application->user->surname ?? '-',
            $application->user->email ?? '-',
            $application->user->phone ?? '-',
            $application->status,
            $application->waitlist_order ?? '-',
            $application->evaluation_note ?? '-',
            $application->rejection_reason ?? '-',
            $application->created_at?->format('d.m.Y H:i') ?? '-',
        ])->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'basvurular_'.now()->format('Ymd_His'),
            'Basvurular',
            $headings,
            $rows,
        );
    }

    /**
     * List applications for staff view.
     *
     * Requires permission: `applications.view`. This staff alias uses the same project/period action+scope resolver, so staff users only see applications in their permitted projects.
     *
     * @authenticated
     * @queryParam project_id integer Optional project filter. Example: 1
     * @queryParam period_id integer Optional period filter. Example: 3
     * @queryParam status string Optional application status filter. Example: pending
     * @queryParam search string Optional applicant search. Example: hakan
     * @response 200 {"applications":{"data":[]}}
     * @response 403 {"message":"Bu proje icin yetkiniz yok."}
     */
    public function staffIndex(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'nullable|exists:projects,id',
            'period_id' => 'nullable|exists:periods,id',
            'status' => 'nullable|string',
            'search' => 'nullable|string|max:255',
        ]);
        $context = $this->resolveProjectPeriodContext(
            $request,
            'applications.view',
            ! empty($validated['project_id']) ? (int) $validated['project_id'] : null,
            ! empty($validated['period_id']) ? (int) $validated['period_id'] : null,
        );

        $query = Application::query()
            ->with(['user:id,name,surname,email,phone', 'period', 'applicationWindow:id,has_interview,quota', 'program:id,title,start_at', 'project:id,name,has_interview,quota']);
        $this->applyProjectPeriodContext($query, $context);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->whereHas('user', function ($builder) use ($search) {
                $builder
                    ->where('name', 'like', "%$search%")
                    ->orWhere('surname', 'like', "%$search%")
                    ->orWhere('email', 'like', "%$search%");
            });
        }

        return response()->json([
            'applications' => $query->orderByDesc('created_at')->paginate(20),
        ]);
    }

    /**
     * Export applications for staff view.
     *
     * Requires permission: `applications.export`. This staff alias uses the same project/period action+scope resolver and returns a binary CSV/XLSX/PDF/DOCX file depending on `format`.
     *
     * @authenticated
     * @queryParam project_id integer Optional project filter. Example: 1
     * @queryParam period_id integer Optional period filter. Example: 3
     * @queryParam status string Optional application status filter. Example: accepted
     * @queryParam search string Optional applicant search. Example: ayse
     * @queryParam format string Optional export format: `csv`, `xlsx`, `pdf`, `docx`, `excel` or `word`. Defaults to csv. Example: csv
     * @response 200 binary Staff applications export file.
     * @response 403 {"message":"Bu proje icin yetkiniz yok."}
     */
    public function staffExport(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'nullable|exists:projects,id',
            'period_id' => 'nullable|exists:periods,id',
            'status' => 'nullable|string',
            'search' => 'nullable|string|max:255',
            'format' => 'nullable|string|max:20',
        ]);
        $context = $this->resolveProjectPeriodContext(
            $request,
            'applications.export',
            ! empty($validated['project_id']) ? (int) $validated['project_id'] : null,
            ! empty($validated['period_id']) ? (int) $validated['period_id'] : null,
        );

        $query = Application::query()
            ->with(['user:id,name,surname,email,phone', 'period', 'applicationWindow:id,has_interview,quota', 'program:id,title,start_at', 'project:id,name,has_interview,quota']);
        $this->applyProjectPeriodContext($query, $context);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->whereHas('user', function ($builder) use ($search) {
                $builder
                    ->where('name', 'like', "%$search%")
                    ->orWhere('surname', 'like', "%$search%")
                    ->orWhere('email', 'like', "%$search%");
            });
        }

        $applications = $query->orderByDesc('created_at')->get();

        $headings = ['ID', 'Proje', 'Donem', 'Program', 'Ad', 'Soyad', 'E-posta', 'Telefon', 'Durum', 'Yedek Sira', 'Basvuru Tarihi'];
        $rows = $applications->map(fn (Application $application) => [
            $application->id,
            $application->project->name ?? '-',
            $application->period->name ?? '-',
            $application->program->title ?? '-',
            $application->user->name ?? '-',
            $application->user->surname ?? '-',
            $application->user->email ?? '-',
            $application->user->phone ?? '-',
            $application->status,
            $application->waitlist_order ?? '-',
            $application->created_at?->format('d.m.Y H:i') ?? '-',
        ])->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'personel_basvurulari_'.now()->format('Ymd_His'),
            'Personel Basvurulari',
            $headings,
            $rows,
        );
    }

    /**
     * Update an application status from staff view.
     *
     * Requires permission: `applications.update_status`. The staff alias first checks the application project against the staff user permitted project IDs, then delegates to the standard status update workflow.
     *
     * @authenticated
     * @urlParam id integer required Application ID. Example: 12
     * @bodyParam status string required New status. Example: rejected
     * @bodyParam interview_at date Optional future interview date when needed. Example: 2026-07-10 14:30:00
     * @bodyParam rejection_reason string Optional rejection reason. Example: Belgeler eksik.
     * @bodyParam evaluation_note string Optional internal evaluation note. Example: Tekrar basvurabilir.
     * @response 200 {"message":"Basvuru durumu basariyla guncellendi.","application":{"id":12,"status":"rejected"}}
     * @response 403 {"message":"Bu basvuru icin yetkiniz bulunmuyor."}
     */
    public function staffUpdateStatus(Request $request, int $id)
    {
        $this->abortUnlessAllowed($request, 'applications.update_status');

        $projectIds = $this->manageableProjectIdList($request, 'applications.update_status');
        $application = Application::with('period')->findOrFail($id);

        abort_unless(in_array((int) $application->project_id, $projectIds, true), 403, 'Bu basvuru icin yetkiniz bulunmuyor.');

        return $this->updateStatus($request, $id);
    }

    /**
     * List panel applications.
     *
     * Requires permission: `applications.view`. Project and period filters are resolved through action+scope, so global users can list all projects while scoped users only see applications in allowed projects. The same method is exposed under `/api/admin/applications` and `/api/panel/applications` aliases.
     *
     * @authenticated
     * @queryParam project_id integer Optional project filter. User must be allowed for `applications.view`. Example: 1
     * @queryParam period_id integer Optional period filter. Must belong to the selected/allowed project. Example: 3
     * @queryParam status string Optional application status filter. Example: pending
     * @queryParam search string Optional applicant/project search. Example: hakan
     * @queryParam per_page integer Optional page size between 1 and 100. Example: 20
     * @response 200 {"applications":{"data":[{"id":12,"status":"pending","waitlist_order":null,"user":{"id":30,"name":"Hakan"},"project":{"id":1,"name":"Kademe"},"available_statuses":["accepted","rejected","waitlisted"],"workflow":{"has_interview":false,"next_step":"final_decision"}}]}}
     * @response 403 {"message":"Bu proje icin yetkiniz yok."}
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'nullable|exists:projects,id',
            'period_id' => 'nullable|exists:periods,id',
            'status' => 'nullable|string',
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $context = $this->resolveProjectPeriodContext(
            $request,
            'applications.view',
            ! empty($validated['project_id']) ? (int) $validated['project_id'] : null,
            ! empty($validated['period_id']) ? (int) $validated['period_id'] : null,
        );

        $query = Application::query()
            ->with(['user:id,name,surname,email,phone', 'period', 'applicationWindow:id,has_interview,quota', 'program:id,title,start_at', 'project:id,name,has_interview,quota', 'form:id,fields']);
        $this->applyProjectPeriodContext($query, $context);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($builder) use ($search) {
                $builder
                    ->whereHas('user', function ($userQuery) use ($search) {
                        $userQuery
                            ->where('name', 'like', "%$search%")
                            ->orWhere('surname', 'like', "%$search%")
                            ->orWhere('email', 'like', "%$search%")
                            ->orWhere('phone', 'like', "%$search%");
                    })
                    ->orWhereHas('project', function ($projectQuery) use ($search) {
                        $projectQuery->where('name', 'like', "%$search%");
                    });
            });
        }

        $applications = $query
            ->orderByDesc('created_at')
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        $applications->getCollection()->transform(fn (Application $application) => $this->formatApplication($application));

        return response()->json([
            'applications' => $applications,
        ]);
    }

    /**
     * Download an application form file.
     *
     * Requires permission: `applications.view` and project access for the application project. If direct public downloads are configured, the endpoint returns a JSON `download_url`; otherwise it streams the stored file.
     *
     * @authenticated
     * @urlParam id integer required Application ID. Example: 12
     * @urlParam field string required Dynamic form field key containing the uploaded file. Example: cv_file
     * @response 200 {"download_url":"https://cdn.example.com/applications/cv.pdf"}
     * @response 200 binary Application form file stream.
     * @response 403 {"message":"Bu basvuru icin yetkiniz bulunmuyor."}
     * @response 404 {"message":"Basvuru dosyasi bulunamadi."}
     */
    public function downloadFormFile(Request $request, int $id, string $field): JsonResponse|StreamedResponse
    {
        $this->abortUnlessAllowed($request, 'applications.view');

        $application = Application::query()->findOrFail($id);
        abort_unless(
            $this->permissionResolver->canAccessProject($request->user(), 'applications.view', (int) $application->project_id),
            403,
            'Bu basvuru icin yetkiniz bulunmuyor.'
        );

        $fieldKey = rawurldecode($field);
        $value = ($application->form_data ?? [])[$fieldKey] ?? null;

        abort_unless(is_array($value) && ! empty($value['path']), 404, 'Basvuru dosyasi bulunamadi.');

        $path = (string) $value['path'];
        if ($this->isUrl($path) || (MediaStorage::directDownloadsEnabled() && MediaStorage::publicUrlConfigured())) {
            return response()->json(['download_url' => MediaStorage::url($path)]);
        }

        if (! MediaStorage::exists($path)) {
            return response()->json(['message' => 'Basvuru dosyasi depolamada bulunamadi.'], 404);
        }

        $filename = $value['original_name'] ?? ('basvuru_dosyasi_'.$application->id);

        return MediaStorage::disk()->download($path, $filename);
    }

    /**
     * Update an application status.
     *
     * Requires permission: `applications.update_status` and project access for the application project. Status transitions are validated against the project interview workflow, capacity rules and completed-period archive lock. Accepting an application can create/update the participant record and send the password setup/reset email.
     *
     * @authenticated
     * @urlParam id integer required Application ID. Example: 12
     * @bodyParam status string required New status: `accepted`, `rejected`, `waitlisted`, `interview_planned`, `interview_passed` or `interview_failed`. Example: accepted
     * @bodyParam interview_at date Optional future interview date. Required when planning an interview and no date already exists. Example: 2026-07-10 14:30:00
     * @bodyParam rejection_reason string Optional rejection reason. Example: Kontenjan dolu.
     * @bodyParam evaluation_note string Optional internal evaluation note. Example: Uygun aday.
     * @response 200 {"message":"Basvuru durumu basariyla guncellendi.","application":{"id":12,"status":"accepted"}}
     * @response 403 {"message":"Bu basvuru icin yetkiniz bulunmuyor."}
     * @response 422 {"message":"The given data was invalid.","errors":{"status":["Bu basvuru akisi icin secilen durum gecislerine izin verilmiyor."]}}
     * @response 423 {"message":"Tamamlanmis donem arsiv modundadir. Degisiklik icin arsiv duzeltme yetkisi gerekir."}
     */
    public function updateStatus(Request $request, $id)
    {
        $this->abortUnlessAllowed($request, 'applications.update_status');
        if ($request->filled('interview_at')) {
            $request->merge(['interview_at' => IstanbulDateTime::toUtcIso($request->input('interview_at'))]);
        }

        $validated = $request->validate([
            'status' => 'required|in:accepted,rejected,waitlisted,interview_planned,interview_passed,interview_failed',
            'interview_at' => 'nullable|date|after:now',
            'rejection_reason' => 'nullable|string',
            'evaluation_note' => 'nullable|string',
        ]);

        $application = Application::with(['period', 'applicationWindow:id,has_interview,quota', 'program:id,title,application_quota', 'project:id,name,has_interview,quota'])->findOrFail($id);

        $ids = $this->manageableProjectIdList($request, 'applications.update_status');
        abort_unless(in_array((int) $application->project_id, $ids, true), 403, 'Bu basvuru icin yetkiniz bulunmuyor.');
        $this->assertPeriodResolvable($request, $application->period_id);
        $this->assertStatusAllowed($application, $validated['status']);

        if ($validated['status'] === 'interview_planned' && empty($validated['interview_at']) && empty($application->interview_at)) {
            throw ValidationException::withMessages([
                'interview_at' => ['Mulakat tarihi zorunludur.'],
            ]);
        }

        DB::beginTransaction();
        try {
            $application->update([
                'status' => $validated['status'],
                'interview_at' => $validated['status'] === 'interview_planned'
                    ? ($validated['interview_at'] ?? $application->interview_at)
                    : $application->interview_at,
                'rejection_reason' => $validated['rejection_reason'] ?? $application->rejection_reason,
                'evaluation_note' => $validated['evaluation_note'] ?? $application->evaluation_note,
            ]);

            if ($validated['status'] === 'accepted') {
                $application->loadMissing('user');
                $this->assertProjectHasSeatFor($application);

                $hasAnotherActiveProject = Participant::query()
                    ->where('user_id', $application->user_id)
                    ->where('status', 'active')
                    ->where('project_id', '!=', $application->project_id)
                    ->exists();

                if ($hasAnotherActiveProject) {
                    throw ValidationException::withMessages([
                        'status' => ['Bu kullanici aktif olarak baska bir projede yer aldigi icin kabul edilemez.'],
                    ]);
                }

                Participant::updateOrCreate([
                    'user_id' => $application->user_id,
                    'project_id' => $application->project_id,
                    'period_id' => $application->period_id,
                ], [
                    'status' => 'active',
                    'credit' => $application->period->credit_start_amount ?? 100,
                    'enrolled_at' => now(),
                ]);

                if ($application->user) {
                    // UserProfile satırı yoksa oluştur
                    $application->user->profile()->firstOrCreate(
                        ['user_id' => $application->user->id],
                        []
                    );

                    if (! in_array($application->user->role, ['student', 'alumni'], true)) {
                        $application->user->update([
                            'role' => 'student',
                            'status' => 'active',
                        ]);
                        $application->user->syncRoles(['student']);
                    } elseif ($application->user->status !== 'active' && $application->user->role === 'student') {
                        $application->user->update(['status' => 'active']);
                    }

                    // Kullanıcı onaylandığında/kabul edildiğinde şifre belirleme maili (reset linki) gönder
                    \Illuminate\Support\Facades\Password::sendResetLink(['email' => $application->user->email]);
                }
            }

            DB::commit();

            $application->loadMissing(['user:id,email', 'project:id,name']);
            $this->notifyApplicationUser(
                $application,
                'Basvuru durumunuz guncellendi',
                'Proje: '.($application->project?->name ?? '-')."\nYeni durum: {$application->status}",
                $request->user()->id
            );

            if ($validated['status'] === 'rejected') {
                $this->waitlistService->inviteNextIfSeatAvailable($application, $request->user()->id);
            }

            return response()->json([
                'message' => 'Başvuru durumu başarıyla güncellendi.',
                'application' => $application,
            ]);
        } catch (ValidationException $e) {
            DB::rollBack();

            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => 'Bir hata oluştu.'], 500);
        }
    }

    /**
     * Plan an application interview.
     *
     * Requires permission: `applications.plan_interview` and project access for the application project. Only projects with interview workflow enabled can use this endpoint, and completed periods require archive override permission.
     *
     * @authenticated
     * @urlParam id integer required Application ID. Example: 12
     * @bodyParam interview_at date required Future interview date. Example: 2026-07-10 14:30:00
     * @response 200 {"message":"Mulakat tarihi basariyla planlandi.","application":{"id":12,"status":"interview_planned","interview_at":"2026-07-10T14:30:00+03:00"}}
     * @response 403 {"message":"Bu basvuru icin yetkiniz bulunmuyor."}
     * @response 422 {"message":"Bu proje mulakatli basvuru akisi kullanmiyor."}
     */
    public function planInterview(Request $request, $id)
    {
        $this->abortUnlessAllowed($request, 'applications.plan_interview');
        $request->merge(['interview_at' => IstanbulDateTime::toUtcIso($request->input('interview_at'))]);

        $validated = $request->validate([
            'interview_at' => 'required|date|after:now',
        ]);

        $application = Application::with(['project:id,name,has_interview', 'applicationWindow:id,has_interview'])->findOrFail($id);

        abort_unless(
            $this->permissionResolver->canAccessProject(
                $request->user(),
                'applications.plan_interview',
                (int) $application->project_id
            ),
            403,
            'Bu basvuru icin yetkiniz bulunmuyor.'
        );

        abort_unless($application->usesInterview(), 422, 'Bu basvuru mulakatli basvuru akisi kullanmiyor.');
        $this->assertPeriodResolvable($request, $application->period_id);
        $this->assertStatusAllowed($application, 'interview_planned');

        $application->update([
            'status' => 'interview_planned',
            'interview_at' => $validated['interview_at'],
        ]);

        $application->loadMissing(['user:id,email', 'project:id,name']);
        $this->notifyApplicationUser(
            $application,
            'Mulakat planlandi',
            'Proje: '.($application->project?->name ?? '-')."\nMulakat tarihi: ".IstanbulDateTime::format($validated['interview_at']),
            $request->user()->id
        );

        return response()->json([
            'message' => 'Mülakat tarihi başarıyla planlandı.',
            'application' => $application,
        ]);
    }

    /**
     * Move an application to the waitlist.
     *
     * Requires permission: `applications.waitlist.manage` and project access for the application project. The endpoint validates the current workflow state, period archive lock and assigns the next waitlist order when needed.
     *
     * @authenticated
     * @urlParam id integer required Application ID. Example: 12
     * @bodyParam evaluation_note string Optional internal waitlist note. Example: Kontenjan acilinca davet edilecek.
     * @response 200 {"message":"Basvuru yedege alindi.","application":{"id":12,"status":"waitlisted","waitlist_order":4}}
     * @response 403 {"message":"Bu basvuru icin yetkiniz bulunmuyor."}
     * @response 422 {"message":"The given data was invalid."}
     */
    public function addToWaitlist(Request $request, $id)
    {
        $this->abortUnlessAllowed($request, 'applications.waitlist.manage');

        $application = Application::with(['project:id,name,has_interview', 'applicationWindow:id,has_interview'])->findOrFail($id);

        abort_unless(
            $this->permissionResolver->canAccessProject(
                $request->user(),
                'applications.waitlist.manage',
                (int) $application->project_id
            ),
            403,
            'Bu basvuru icin yetkiniz bulunmuyor.'
        );

        $this->assertPeriodResolvable($request, $application->period_id);
        $this->assertStatusAllowed($application, 'waitlisted');

        $application->update([
            'status' => 'waitlisted',
            'waitlist_order' => $application->waitlist_order ?: $this->nextWaitlistOrder($application),
            'evaluation_note' => $request->evaluation_note ?? $application->evaluation_note,
        ]);

        $application->loadMissing(['user:id,email', 'project:id,name']);
        $this->notifyApplicationUser(
            $application,
            'Basvurunuz yedek listeye alindi',
            'Proje: '.($application->project?->name ?? '-')."\nDurum: yedek listede.",
            $request->user()->id
        );

        return response()->json([
            'message' => 'Başvuru yedeğe alındı.',
            'application' => $application,
        ]);
    }

    /**
     * Update waitlist order for an application.
     *
     * Requires permission: `applications.waitlist.manage` and project access for the application project. Only applications currently in `waitlisted` status can be reordered.
     *
     * @authenticated
     * @urlParam id integer required Application ID. Example: 12
     * @bodyParam waitlist_order integer required New waitlist order, minimum 1. Example: 2
     * @response 200 {"message":"Yedek liste sirasi guncellendi.","application":{"id":12,"waitlist_order":2}}
     * @response 403 {"message":"Bu basvuru icin yetkiniz bulunmuyor."}
     * @response 422 {"message":"Sadece yedek listedeki basvurular siralanabilir."}
     */
    public function updateWaitlistOrder(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'applications.waitlist.manage');
        $validated = $request->validate([
            'waitlist_order' => 'required|integer|min:1',
        ]);

        $application = Application::query()->findOrFail($id);
        abort_unless(
            $this->permissionResolver->canAccessProject($request->user(), 'applications.waitlist.manage', (int) $application->project_id),
            403,
            'Bu basvuru icin yetkiniz bulunmuyor.'
        );
        $this->assertPeriodResolvable($request, $application->period_id);
        abort_unless($application->status === 'waitlisted', 422, 'Sadece yedek listedeki basvurular siralanabilir.');

        $application->update(['waitlist_order' => (int) $validated['waitlist_order']]);

        return response()->json([
            'message' => 'Yedek liste sirasi guncellendi.',
            'application' => $application->fresh(['user:id,name,surname,email', 'project:id,name', 'period:id,name', 'program:id,title']),
        ]);
    }

    /**
     * Send a waitlist invitation.
     *
     * Requires permission: `applications.waitlist.manage` and project access for the application project. Only waitlisted applications can be invited; quota and active invitation rules are enforced by the waitlist service.
     *
     * @authenticated
     * @urlParam id integer required Application ID. Example: 12
     * @bodyParam expires_at date Optional invitation expiry date. Defaults to three days from now. Example: 2026-07-03 23:59:00
     * @response 200 {"message":"Yedek liste daveti gonderildi.","application":{"id":12,"status":"waitlisted","waitlist_invited_at":"2026-06-30T12:00:00+03:00"}}
     * @response 403 {"message":"Bu basvuru icin yetkiniz bulunmuyor."}
     * @response 422 {"message":"Sadece yedek listedeki basvurular davet edilebilir."}
     */
    public function inviteFromWaitlist(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'applications.waitlist.manage');
        if ($request->filled('expires_at')) {
            $request->merge(['expires_at' => IstanbulDateTime::toUtcIso($request->input('expires_at'))]);
        }
        $validated = $request->validate([
            'expires_at' => 'nullable|date|after:now',
        ]);

        $application = Application::with(['user:id,email', 'project:id,name'])->findOrFail($id);
        abort_unless(
            $this->permissionResolver->canAccessProject($request->user(), 'applications.waitlist.manage', (int) $application->project_id),
            403,
            'Bu basvuru icin yetkiniz bulunmuyor.'
        );
        $this->assertPeriodResolvable($request, $application->period_id);
        abort_unless($application->status === 'waitlisted', 422, 'Sadece yedek listedeki basvurular davet edilebilir.');

        $expiresAt = $validated['expires_at'] ?? now()->addDays(3);
        try {
            $application = $this->waitlistService->inviteSpecific($application, $request->user()->id, $expiresAt);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Yedek liste daveti gonderildi.',
            'application' => $application,
        ]);
    }

    /**
     * Refresh overdue waitlist invitations.
     *
     * Requires permission: `applications.waitlist.manage` and project access for the application project. The endpoint expires overdue waitlist invitations and invites the next eligible application if a seat is available.
     *
     * @authenticated
     * @urlParam id integer required Application ID used as the waitlist context. Example: 12
     * @response 200 {"message":"Yedek davet sureleri guncellendi.","expired_count":1,"auto_invited_application_id":15}
     * @response 403 {"message":"Bu basvuru icin yetkiniz bulunmuyor."}
     * @response 422 {"message":"Sadece yedek listedeki basvurular icin yenileme yapilabilir."}
     */
    public function refreshWaitlistInvitations(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'applications.waitlist.manage');
        $application = Application::query()->findOrFail($id);
        abort_unless(
            $this->permissionResolver->canAccessProject($request->user(), 'applications.waitlist.manage', (int) $application->project_id),
            403,
            'Bu basvuru icin yetkiniz bulunmuyor.'
        );
        $this->assertPeriodResolvable($request, $application->period_id);
        abort_unless($application->status === 'waitlisted', 422, 'Sadece yedek listedeki basvurular icin yenileme yapilabilir.');

        $expiredCount = $this->waitlistService->expireOverdueInvitations($application);
        $invited = $this->waitlistService->inviteNextIfSeatAvailable($application, $request->user()->id);

        return response()->json([
            'message' => 'Yedek davet sureleri guncellendi.',
            'expired_count' => $expiredCount,
            'auto_invited_application_id' => $invited?->id,
        ]);
    }

    private function expireOverdueWaitlistInvitations(Application $application): int
    {
        return $this->waitlistService->expireOverdueInvitations($application);
    }

    private function nextWaitlistOrder(Application $application): int
    {
        $max = Application::query()
            ->where('project_id', $application->project_id)
            ->where('period_id', $application->period_id)
            ->when($application->program_id, fn ($query) => $query->where('program_id', $application->program_id), fn ($query) => $query->whereNull('program_id'))
            ->where('status', 'waitlisted')
            ->max('waitlist_order');

        return ((int) $max) + 1;
    }
}
