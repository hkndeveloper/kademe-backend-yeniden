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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminApplicationController extends Controller
{
    use AuthorizesGranularPermissions;
    use ResolvesProjectPeriodContext;

    public function __construct(
        private readonly PermissionResolver $permissionResolver,
        private readonly NotificationService $notificationService,
        private readonly WaitlistService $waitlistService,
    ) {}

    private function notifyApplicationUser(Application $application, string $subject, string $body, ?int $senderId = null): void
    {
        $email = $application->user?->email;
        if (! $email) {
            return;
        }

        $this->notificationService->sendEmail(
            [$email],
            $subject,
            $body,
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
        $hasInterview = (bool) $application->project?->has_interview;

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
        $quota = $application->program?->application_quota ?? $application->project?->quota;
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
                'has_interview' => (bool) $application->project?->has_interview,
                'next_step' => $this->nextWorkflowStep($application),
            ],
        ];
    }

    private function nextWorkflowStep(Application $application): ?string
    {
        if (! $application->project?->has_interview) {
            return $application->status === 'pending' ? 'final_decision' : null;
        }

        return match ($application->status) {
            'pending', 'waitlisted' => 'plan_interview',
            'interview_planned' => 'record_interview_result',
            'interview_passed' => 'final_decision',
            default => null,
        };
    }

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

        $query = Application::query()->with(['user:id,name,surname,email,phone', 'period', 'program:id,title,start_at', 'project:id,name,has_interview']);
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
            ->with(['user:id,name,surname,email,phone', 'period', 'program:id,title,start_at', 'project:id,name,has_interview']);
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
            ->with(['user:id,name,surname,email,phone', 'period', 'program:id,title,start_at', 'project:id,name,has_interview']);
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

    public function staffUpdateStatus(Request $request, int $id)
    {
        $this->abortUnlessAllowed($request, 'applications.update_status');

        $projectIds = $this->manageableProjectIdList($request, 'applications.update_status');
        $application = Application::with('period')->findOrFail($id);

        abort_unless(in_array((int) $application->project_id, $projectIds, true), 403, 'Bu basvuru icin yetkiniz bulunmuyor.');

        return $this->updateStatus($request, $id);
    }

    /**
     * Projeye ait tüm başvuruları getirir
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
            ->with(['user:id,name,surname,email,phone', 'period', 'program:id,title,start_at', 'project:id,name,has_interview', 'form:id,fields']);
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
     * Başvuru Durumunu Güncelleme (Kabul/Red/Yedek vb.)
     */
    public function updateStatus(Request $request, $id)
    {
        $this->abortUnlessAllowed($request, 'applications.update_status');

        $validated = $request->validate([
            'status' => 'required|in:accepted,rejected,waitlisted,interview_planned,interview_passed,interview_failed',
            'interview_at' => 'nullable|date|after:now',
            'rejection_reason' => 'nullable|string',
            'evaluation_note' => 'nullable|string',
        ]);

        $application = Application::with(['period', 'program:id,title,application_quota', 'project:id,name,has_interview,quota'])->findOrFail($id);

        $ids = $this->manageableProjectIdList($request, 'applications.update_status');
        abort_unless(in_array((int) $application->project_id, $ids, true), 403, 'Bu basvuru icin yetkiniz bulunmuyor.');
        $this->assertPeriodWritable($request, $application->period_id);
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
     * PUT /admin/applications/{id}/interview — Mülakat Tarihi Planla
     */
    public function planInterview(Request $request, $id)
    {
        $this->abortUnlessAllowed($request, 'applications.plan_interview');

        $validated = $request->validate([
            'interview_at' => 'required|date|after:now',
        ]);

        $application = Application::with('project:id,name,has_interview')->findOrFail($id);

        abort_unless(
            $this->permissionResolver->canAccessProject(
                $request->user(),
                'applications.plan_interview',
                (int) $application->project_id
            ),
            403,
            'Bu basvuru icin yetkiniz bulunmuyor.'
        );

        abort_unless((bool) $application->project?->has_interview, 422, 'Bu proje mulakatli basvuru akisi kullanmiyor.');
        $this->assertPeriodWritable($request, $application->period_id);
        $this->assertStatusAllowed($application, 'interview_planned');

        $application->update([
            'status' => 'interview_planned',
            'interview_at' => $validated['interview_at'],
        ]);

        $application->loadMissing(['user:id,email', 'project:id,name']);
        $this->notifyApplicationUser(
            $application,
            'Mulakat planlandi',
            'Proje: '.($application->project?->name ?? '-')."\nMulakat tarihi: {$validated['interview_at']}",
            $request->user()->id
        );

        return response()->json([
            'message' => 'Mülakat tarihi başarıyla planlandı.',
            'application' => $application,
        ]);
    }

    /**
     * POST /admin/applications/{id}/waitlist — Yedeğe Al
     */
    public function addToWaitlist(Request $request, $id)
    {
        $this->abortUnlessAllowed($request, 'applications.waitlist.manage');

        $application = Application::with('project:id,name,has_interview')->findOrFail($id);

        abort_unless(
            $this->permissionResolver->canAccessProject(
                $request->user(),
                'applications.waitlist.manage',
                (int) $application->project_id
            ),
            403,
            'Bu basvuru icin yetkiniz bulunmuyor.'
        );

        $this->assertPeriodWritable($request, $application->period_id);
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
        $this->assertPeriodWritable($request, $application->period_id);
        abort_unless($application->status === 'waitlisted', 422, 'Sadece yedek listedeki basvurular siralanabilir.');

        $application->update(['waitlist_order' => (int) $validated['waitlist_order']]);

        return response()->json([
            'message' => 'Yedek liste sirasi guncellendi.',
            'application' => $application->fresh(['user:id,name,surname,email', 'project:id,name', 'period:id,name', 'program:id,title']),
        ]);
    }

    public function inviteFromWaitlist(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'applications.waitlist.manage');
        $validated = $request->validate([
            'expires_at' => 'nullable|date|after:now',
        ]);

        $application = Application::with(['user:id,email', 'project:id,name'])->findOrFail($id);
        abort_unless(
            $this->permissionResolver->canAccessProject($request->user(), 'applications.waitlist.manage', (int) $application->project_id),
            403,
            'Bu basvuru icin yetkiniz bulunmuyor.'
        );
        $this->assertPeriodWritable($request, $application->period_id);
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

    public function refreshWaitlistInvitations(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'applications.waitlist.manage');
        $application = Application::query()->findOrFail($id);
        abort_unless(
            $this->permissionResolver->canAccessProject($request->user(), 'applications.waitlist.manage', (int) $application->project_id),
            403,
            'Bu basvuru icin yetkiniz bulunmuyor.'
        );
        $this->assertPeriodWritable($request, $application->period_id);
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
