<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\KpdAppointment;
use App\Models\KpdReport;
use App\Models\KpdRoom;
use App\Models\Project;
use App\Models\User;
use App\Support\MediaStorage;
use App\Services\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminKpdController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
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
            ->orderBy('name')
            ->get(['id', 'name', 'surname', 'email', 'role'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'surname' => $user->surname,
                'email' => $user->email,
                'role' => $user->role,
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

    private function reportPayload(KpdReport $report): array
    {
        return [
            'id' => $report->id,
            'user_id' => $report->user_id,
            'counselor_id' => $report->counselor_id,
            'title' => $report->title,
            'file_path' => $report->file_path,
            'download_url' => $report->file_path ? "/panel/kpd/reports/{$report->id}/download" : null,
            'created_at' => optional($report->created_at)?->toIso8601String(),
            'user' => $report->relationLoaded('user') ? $report->user : null,
            'counselor' => $report->relationLoaded('counselor') ? $report->counselor : null,
        ];
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

        $appointments = KpdAppointment::query()
            ->with(['counselor:id,name,surname', 'counselee:id,name,surname', 'room'])
            ->when(! $this->hasGlobalKpdAccess($request, 'kpd.appointments.view'), function ($query) use ($projectIds) {
                $query->whereHas('counselee.participations', fn ($inner) => $inner->whereIn('project_id', $projectIds));
            })
            ->orderBy('start_at', 'desc')
            ->paginate(15);

        $payload = ['appointments' => $appointments];

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

        $reports = KpdReport::query()
            ->with(['user:id,name,surname,email', 'counselor:id,name,surname,email'])
            ->when(! $this->hasGlobalKpdAccess($request, 'kpd.reports.view'), function ($query) use ($projectIds) {
                $query->whereHas('user.participations', fn ($inner) => $inner->whereIn('project_id', $projectIds));
            })
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
            'title' => 'required|string|max:255',
            'file' => 'required|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:20480',
        ]);

        $this->abortUnlessUserInKpdScope($request, 'kpd.reports.create', (int) $validated['user_id']);

        $path = MediaStorage::putFile('kpd-reports', $request->file('file'));

        $report = KpdReport::query()->create([
            'user_id' => $validated['user_id'],
            'counselor_id' => $request->user()->id,
            'title' => $validated['title'],
            'file_path' => $path,
        ])->load(['user:id,name,surname,email', 'counselor:id,name,surname,email']);

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
            'room_id' => 'required|exists:kpd_rooms,id',
            'start_at' => 'required|date',
            'end_at' => 'required|date|after:start_at',
            'notes' => 'nullable|string',
        ]);

        $this->abortUnlessUserInKpdScope($request, 'kpd.appointments.manage', (int) $validated['counselee_id']);

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
            ->load(['counselor:id,name,surname', 'counselee:id,name,surname', 'room']);

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
            ->with(['counselor:id,name,surname', 'counselee:id,name,surname', 'room'])
            ->when(! $this->hasGlobalKpdAccess($request, 'kpd.appointments.manage'), function ($query) use ($projectIds) {
                $query->whereHas('counselee.participations', fn ($inner) => $inner->whereIn('project_id', $projectIds));
            })
            ->findOrFail($id);

        $appointment->update(['status' => $validated['status']]);

        return response()->json([
            'message' => 'Randevu durumu guncellendi.',
            'appointment' => $appointment->fresh(['counselor:id,name,surname', 'counselee:id,name,surname', 'room']),
        ]);
    }
}
