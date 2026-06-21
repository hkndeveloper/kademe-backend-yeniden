<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Concerns\ResolvesProjectPeriodContext;
use App\Http\Controllers\Controller;
use App\Models\KpdAppointment;
use App\Models\KpdReport;
use App\Models\KpdRoom;
use App\Models\Period;
use App\Models\Project;
use App\Models\User;
use App\Support\MediaStorage;
use App\Support\ProjectSpecialModuleCatalog;
use App\Services\NotificationService;
use App\Services\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminKpdController extends Controller
{
    use AuthorizesGranularPermissions;
    use ResolvesProjectPeriodContext;

    public function __construct(
        private readonly PermissionResolver $permissionResolver,
        private readonly NotificationService $notificationService
    ) {
    }

    private function kpdProjectIds(): array
    {
        return Project::query()
            ->where(function ($query) {
                $query
                    ->where('type', 'kpd')
                    ->orWhere('name', 'like', '%KPD%')
                    ->orWhere('name', 'like', '%Psikolojik%')
                    ->orWhere('slug', 'like', '%kpd%');
            })
            ->get(['id', 'type', 'name', 'slug'])
            ->filter(fn (Project $project) => in_array('kpd_appointments', ProjectSpecialModuleCatalog::forProject($project), true))
            ->pluck('id')
            ->all();
    }

    private function accessibleKpdProjectIds(Request $request, string $permission): array
    {
        $this->abortUnlessAllowed($request, $permission);

        if ($this->permissionResolver->hasGlobalScope($request->user(), $permission)) {
            return $this->kpdProjectIds();
        }

        $projectIds = array_values(array_intersect(
            $this->permissionResolver->projectIdsForPermission($request->user(), $permission),
            $this->kpdProjectIds()
        ));

        abort_unless(! empty($projectIds), 403, 'KPD islemleri icin KPD projesi kapsaminda yetki gerekir.');

        return $projectIds;
    }

    private function hasGlobalKpdAccess(Request $request, string $permission): bool
    {
        return $this->permissionResolver->hasGlobalScope($request->user(), $permission);
    }

    private function abortUnlessUserInKpdScope(Request $request, string $permission, int $userId): void
    {
        if ($this->hasGlobalKpdAccess($request, $permission)) {
            return;
        }

        $projectIds = $this->accessibleKpdProjectIds($request, $permission);

        abort_unless(
            User::query()
                ->where('id', $userId)
                ->whereHas('participations', fn ($query) => $query->whereIn('project_id', $projectIds))
                ->exists(),
            422,
            'Secilen kullanici erisilebilir KPD projesine ait degil.'
        );
    }

    private function scopedKpdUsers(Request $request, string $permission)
    {
        $projectIds = $this->accessibleKpdProjectIds($request, $permission);

        return User::query()
            ->whereIn('role', ['student', 'alumni'])
            ->whereHas('participations', fn ($query) => $query->whereIn('project_id', $projectIds))
            ->with(['participations.period:id,name,status'])
            ->orderBy('name')
            ->get(['id', 'name', 'surname', 'email', 'role'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'surname' => $user->surname,
                'email' => $user->email,
                'role' => $user->role,
                'periods' => $user->participations
                    ->pluck('period')
                    ->filter()
                    ->unique('id')
                    ->map(fn ($period) => [
                        'id' => $period->id,
                        'name' => $period->name,
                        'status' => $period->status,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values();
    }

    private function counselorOptions()
    {
        return User::query()
            ->whereIn('role', ['super_admin', 'coordinator', 'staff'])
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'surname', 'role'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'surname' => $user->surname,
                'role' => $user->role,
            ])
            ->values();
    }

    private function roomOptions()
    {
        return KpdRoom::query()
            ->orderBy('name')
            ->get(['id', 'name', 'description'])
            ->map(fn (KpdRoom $room) => [
                'id' => $room->id,
                'name' => $room->name,
                'description' => $room->description,
            ])
            ->values();
    }

    private function roomSchedulePayload(array $projectIds, ?int $periodId): array
    {
        return KpdRoom::query()
            ->with(['appointments' => function ($query) use ($projectIds, $periodId) {
                $query
                    ->with(['counselor:id,name,surname,role', 'counselee:id,name,surname,email', 'period:id,name,status'])
                    ->where('status', '!=', 'cancelled')
                    ->whereHas('counselee.participations', function ($inner) use ($projectIds, $periodId) {
                        $inner->whereIn('project_id', $projectIds)
                            ->when($periodId, fn ($periodQuery) => $periodQuery->where('period_id', $periodId));
                    })
                    ->when($periodId, fn ($appointmentQuery) => $appointmentQuery->where('period_id', $periodId))
                    ->when(! $periodId, fn ($appointmentQuery) => $appointmentQuery->where('end_at', '>=', now()->subDay()))
                    ->orderBy('start_at');
            }])
            ->orderBy('name')
            ->get(['id', 'name', 'description'])
            ->map(fn (KpdRoom $room) => [
                'id' => $room->id,
                'name' => $room->name,
                'description' => $room->description,
                'appointment_count' => $room->appointments->count(),
                'next_appointment_at' => optional($room->appointments->first()?->start_at)?->toIso8601String(),
                'appointments' => $room->appointments
                    ->take(8)
                    ->map(fn (KpdAppointment $appointment) => [
                        'id' => $appointment->id,
                        'status' => $appointment->status,
                        'start_at' => optional($appointment->start_at)?->toIso8601String(),
                        'end_at' => optional($appointment->end_at)?->toIso8601String(),
                        'period' => $appointment->period ? [
                            'id' => $appointment->period->id,
                            'name' => $appointment->period->name,
                            'status' => $appointment->period->status,
                        ] : null,
                        'counselor' => $appointment->counselor ? [
                            'id' => $appointment->counselor->id,
                            'name' => $appointment->counselor->name,
                            'surname' => $appointment->counselor->surname,
                            'role' => $appointment->counselor->role,
                        ] : null,
                        'counselee' => $appointment->counselee ? [
                            'id' => $appointment->counselee->id,
                            'name' => $appointment->counselee->name,
                            'surname' => $appointment->counselee->surname,
                        ] : null,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    private function reportPayload(KpdReport $report): array
    {
        return [
            'id' => $report->id,
            'user_id' => $report->user_id,
            'period_id' => $report->period_id,
            'counselor_id' => $report->counselor_id,
            'title' => $report->title,
            'file_path' => $report->file_path,
            'download_url' => $report->file_path ? "/panel/kpd/reports/{$report->id}/download" : null,
            'created_at' => optional($report->created_at)?->toIso8601String(),
            'user' => $report->relationLoaded('user') ? $report->user : null,
            'period' => $report->relationLoaded('period') ? $report->period : null,
            'counselor' => $report->relationLoaded('counselor') ? $report->counselor : null,
        ];
    }

    private function assertPeriodMatchesUser(int $userId, ?int $periodId): void
    {
        if ($periodId === null) {
            return;
        }

        abort_unless(
            \App\Models\Participant::query()
                ->where('user_id', $userId)
                ->where('period_id', $periodId)
                ->exists(),
            422,
            'Secilen donem bu danisana ait degil.'
        );
    }

    private function resolveAccessibleKpdPeriodId(Request $request, string $permission, array $projectIds): ?int
    {
        $validated = $request->validate([
            'period_id' => 'nullable|integer|exists:periods,id',
        ]);

        if (empty($validated['period_id'])) {
            return null;
        }

        $period = Period::query()
            ->select(['id', 'project_id'])
            ->findOrFail((int) $validated['period_id']);

        if (! in_array((int) $period->project_id, $projectIds, true)) {
            throw ValidationException::withMessages([
                'period_id' => ["Secilen donem icin {$permission} yetkiniz yok."],
            ]);
        }

        return (int) $period->id;
    }

    private function streamReport(KpdReport $report): JsonResponse|StreamedResponse
    {
        if (! $report->file_path) {
            return response()->json(['message' => 'Rapor dosyasi bulunamadi.'], 404);
        }

        if ($this->isUrl($report->file_path) || (MediaStorage::directDownloadsEnabled() && MediaStorage::publicUrlConfigured())) {
            return response()->json(['download_url' => MediaStorage::url($report->file_path)]);
        }

        if (! MediaStorage::exists($report->file_path)) {
            return response()->json(['message' => 'Rapor dosyasi storage uzerinde bulunamadi.'], 404);
        }

        $extension = pathinfo($report->file_path, PATHINFO_EXTENSION);
        $filename = 'kpd_raporu_' . $report->id;

        return MediaStorage::disk()->download(
            $report->file_path,
            $filename . ($extension ? ".{$extension}" : '')
        );
    }

    private function isUrl(string $path): bool
    {
        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://');
    }

    public function options(Request $request): JsonResponse
    {
        $permission = (string) $request->query('permission', 'kpd.reports.create');
        abort_unless(
            in_array($permission, ['kpd.reports.create', 'kpd.appointments.manage'], true),
            422,
            'Gecersiz KPD yetki anahtari.'
        );

        $payload = [
            'counselees' => $this->scopedKpdUsers($request, $permission),
        ];

        if ($permission === 'kpd.appointments.manage') {
            $payload['counselors'] = $this->counselorOptions();
            $payload['rooms'] = $this->roomOptions();
        }

        return response()->json($payload);
    }

    /**
     * Tüm KPD Randevularını Listele
     */
    public function index(Request $request)
    {
        $projectIds = $this->accessibleKpdProjectIds($request, 'kpd.appointments.view');
        $periodId = $this->resolveAccessibleKpdPeriodId($request, 'kpd.appointments.view', $projectIds);

        $appointments = KpdAppointment::query()
            ->with(['counselor:id,name,surname', 'counselee:id,name,surname', 'period:id,name,status', 'room'])
            ->when(! $this->hasGlobalKpdAccess($request, 'kpd.appointments.view'), function ($query) use ($projectIds) {
                $query->whereHas('counselee.participations', fn ($inner) => $inner->whereIn('project_id', $projectIds));
            })
            ->when($periodId, fn ($query) => $query->where('period_id', $periodId))
            ->orderBy('start_at', 'desc')
            ->paginate(15);

        $payload = [
            'appointments' => $appointments,
            'room_schedule' => $this->roomSchedulePayload($projectIds, $periodId),
        ];

        if ($this->permissionResolver->hasPermission($request->user(), 'kpd.appointments.manage')) {
            $payload['counselees'] = $this->scopedKpdUsers($request, 'kpd.appointments.manage');
            $payload['counselors'] = $this->counselorOptions();
            $payload['rooms'] = $this->roomOptions();
        }

        return response()->json($payload);
    }

    public function reports(Request $request): JsonResponse
    {
        $projectIds = $this->accessibleKpdProjectIds($request, 'kpd.reports.view');
        $periodId = $this->resolveAccessibleKpdPeriodId($request, 'kpd.reports.view', $projectIds);

        $reports = KpdReport::query()
            ->with(['user:id,name,surname,email', 'period:id,name,status', 'counselor:id,name,surname,email'])
            ->when(! $this->hasGlobalKpdAccess($request, 'kpd.reports.view'), function ($query) use ($projectIds) {
                $query->whereHas('user.participations', fn ($inner) => $inner->whereIn('project_id', $projectIds));
            })
            ->when($periodId, fn ($query) => $query->where('period_id', $periodId))
            ->latest()
            ->paginate(20)
            ->through(fn (KpdReport $report) => $this->reportPayload($report));

        return response()->json(['reports' => $reports]);
    }

    public function storeReport(Request $request): JsonResponse
    {
        $this->accessibleKpdProjectIds($request, 'kpd.reports.create');

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'period_id' => 'nullable|exists:periods,id',
            'title' => 'required|string|max:255',
            'file' => 'required|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:20480',
        ]);

        $this->abortUnlessUserInKpdScope($request, 'kpd.reports.create', (int) $validated['user_id']);
        $this->assertPeriodMatchesUser((int) $validated['user_id'], isset($validated['period_id']) ? (int) $validated['period_id'] : null);
        $this->assertPeriodWritable($request, isset($validated['period_id']) ? (int) $validated['period_id'] : null);

        $path = MediaStorage::putFile('kpd-reports', $request->file('file'));

        $report = KpdReport::query()->create([
            'user_id' => $validated['user_id'],
            'period_id' => $validated['period_id'] ?? null,
            'counselor_id' => $request->user()->id,
            'title' => $validated['title'],
            'file_path' => $path,
        ])->load(['user:id,name,surname,email', 'counselor:id,name,surname,email']);

        if ($report->user?->email) {
            $this->notificationService->sendEmail(
                [$report->user->email],
                'KPD raporunuz yuklendi',
                "Merhaba {$report->user?->name},\nKPD raporlarim alanina yeni bir rapor yuklendi.\nRapor: {$report->title}",
                null,
                $request->user()->id
            );
        }

        return response()->json([
            'message' => 'KPD raporu yuklendi.',
            'report' => $this->reportPayload($report),
        ], 201);
    }

    public function downloadReport(Request $request, int $id): JsonResponse|StreamedResponse
    {
        $projectIds = $this->accessibleKpdProjectIds($request, 'kpd.reports.view');

        $report = KpdReport::query()
            ->when(! $this->hasGlobalKpdAccess($request, 'kpd.reports.view'), function ($query) use ($projectIds) {
                $query->whereHas('user.participations', fn ($inner) => $inner->whereIn('project_id', $projectIds));
            })
            ->findOrFail($id);

        return $this->streamReport($report);
    }

    public function destroyReport(Request $request, int $id): JsonResponse
    {
        $projectIds = $this->accessibleKpdProjectIds($request, 'kpd.reports.delete');

        $report = KpdReport::query()
            ->when(! $this->hasGlobalKpdAccess($request, 'kpd.reports.delete'), function ($query) use ($projectIds) {
                $query->whereHas('user.participations', fn ($inner) => $inner->whereIn('project_id', $projectIds));
            })
            ->findOrFail($id);
        $this->assertPeriodWritable($request, $report->period_id);
        MediaStorage::delete($report->file_path);
        $report->delete();

        return response()->json(['message' => 'KPD raporu silindi.']);
    }

    /**
     * Yeni Randevu Oluştur
     */
    public function store(Request $request)
    {
        $this->accessibleKpdProjectIds($request, 'kpd.appointments.manage');

        $validated = $request->validate([
            'counselor_id' => 'required|exists:users,id',
            'counselee_id' => 'required|exists:users,id',
            'period_id' => 'nullable|exists:periods,id',
            'room_id' => 'required|exists:kpd_rooms,id',
            'start_at' => 'required|date',
            'end_at' => 'required|date|after:start_at',
            'notes' => 'nullable|string',
        ]);

        $this->abortUnlessUserInKpdScope($request, 'kpd.appointments.manage', (int) $validated['counselee_id']);
        $this->assertPeriodMatchesUser((int) $validated['counselee_id'], isset($validated['period_id']) ? (int) $validated['period_id'] : null);
        $this->assertPeriodWritable($request, isset($validated['period_id']) ? (int) $validated['period_id'] : null);

        $counselor = User::query()
            ->whereKey($validated['counselor_id'])
            ->whereIn('role', ['super_admin', 'coordinator', 'staff'])
            ->where('status', 'active')
            ->first();

        if (! $counselor) {
            return response()->json(['message' => 'Secilen danisman uygun degil.'], 422);
        }

        $exists = KpdAppointment::query()
            ->where('status', '!=', 'cancelled')
            ->where(function ($query) use ($validated) {
                $query->where('room_id', $validated['room_id'])
                    ->orWhere('counselor_id', $validated['counselor_id'])
                    ->orWhere('counselee_id', $validated['counselee_id']);
            })
            ->where('start_at', '<', $validated['end_at'])
            ->where('end_at', '>', $validated['start_at'])
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Secilen zaman araliginda oda, danisman veya danisan icin cakisma var.'], 422);
        }

        $appointment = KpdAppointment::create(array_merge($validated, ['status' => 'scheduled']))
            ->load(['counselor:id,name,surname,email', 'counselee:id,name,surname,email', 'period:id,name,status', 'room']);

        $this->notifyAppointmentCreated($appointment, $request->user()->id);

        return response()->json([
            'message' => 'Randevu basariyla olusturuldu.',
            'appointment' => $appointment,
        ], 201);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $projectIds = $this->accessibleKpdProjectIds($request, 'kpd.appointments.manage');

        $validated = $request->validate([
            'status' => ['required', Rule::in(['scheduled', 'completed', 'cancelled', 'no_show'])],
        ]);

        $appointment = KpdAppointment::query()
            ->with(['counselor:id,name,surname,email', 'counselee:id,name,surname,email', 'room'])
            ->when(! $this->hasGlobalKpdAccess($request, 'kpd.appointments.manage'), function ($query) use ($projectIds) {
                $query->whereHas('counselee.participations', fn ($inner) => $inner->whereIn('project_id', $projectIds));
            })
            ->findOrFail($id);
        $this->assertPeriodWritable($request, $appointment->period_id);

        $appointment->update(['status' => $validated['status']]);
        $appointment->refresh()->load(['counselor:id,name,surname,email', 'counselee:id,name,surname,email', 'room']);

        $this->notifyAppointmentStatusChanged($appointment, $validated['status'], $request->user()->id);

        return response()->json([
            'message' => 'Randevu durumu guncellendi.',
            'appointment' => $appointment,
        ]);
    }

    private function notifyAppointmentCreated(KpdAppointment $appointment, ?int $senderId = null): void
    {
        $start = optional($appointment->start_at)?->format('d.m.Y H:i') ?? '-';
        $room = $appointment->room?->name ?? '-';

        $this->notificationService->sendEmail(
            array_filter([$appointment->counselee?->email, $appointment->counselor?->email]),
            'KPD randevusu olusturuldu',
            "Randevu tarihi: {$start}\nOda: {$room}\nDanisman: ".trim(($appointment->counselor?->name ?? '').' '.($appointment->counselor?->surname ?? ''))."\nDanisan: ".trim(($appointment->counselee?->name ?? '').' '.($appointment->counselee?->surname ?? '')),
            null,
            $senderId
        );
    }

    private function notifyAppointmentStatusChanged(KpdAppointment $appointment, string $status, ?int $senderId = null): void
    {
        $statusLabels = [
            'scheduled' => 'Planlandi',
            'completed' => 'Tamamlandi',
            'cancelled' => 'Iptal edildi',
            'no_show' => 'Katilim olmadi',
        ];
        $start = optional($appointment->start_at)?->format('d.m.Y H:i') ?? '-';

        $this->notificationService->sendEmail(
            array_filter([$appointment->counselee?->email, $appointment->counselor?->email]),
            'KPD randevu durumu guncellendi',
            "Randevu tarihi: {$start}\nYeni durum: ".($statusLabels[$status] ?? $status),
            null,
            $senderId
        );
    }
}
