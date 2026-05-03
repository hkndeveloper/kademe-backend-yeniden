<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\Project;
use App\Models\StaffProfile;
use App\Models\User;
use App\Support\AdminExportResponder;
use App\Support\MediaStorage;
use App\Services\PermissionResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StaffController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {
    }

    private function visibleProjectIdsForStaff(User $user): \Illuminate\Support\Collection
    {
        return collect($this->permissionResolver->projectIdsForPermission($user, 'projects.export'));
    }

    private function coordinatorUnit(?User $user): ?string
    {
        if (! $user || ! $this->permissionResolver->hasPermission($user, 'staff.view')) {
            return null;
        }

        if ($this->permissionResolver->hasGlobalScope($user, 'staff.view')) {
            return null;
        }

        return $user->staffProfile?->unit;
    }

    private function applyCoordinatorUnitScope(Request $request, $query)
    {
        $unit = $this->coordinatorUnit($request->user()->loadMissing('staffProfile'));

        if ($unit) {
            $query->whereHas('staffProfile', fn ($builder) => $builder->where('unit', $unit));
        }

        return $query;
    }

    private function normalizeUnit(?string $unit): ?string
    {
        $normalized = mb_strtolower(trim((string) $unit));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * GET /staff/projects
     * Personelin gorev kapsamindaki projeleri dondurur.
     */
    public function myProjects(Request $request)
    {
        $this->abortUnlessAllowed($request, 'projects.view');
        $user = $request->user()->load('staffProfile');
        $unit = mb_strtolower((string) $user->staffProfile?->unit);

        $query = Project::query()
            ->with([
                'activePeriods:id,project_id,name,start_date,end_date,status',
                'participants:id,project_id,status,graduation_status',
            ]);

        $projectIds = collect($this->permissionResolver->projectIdsForPermission($user, 'projects.view'));

        if ($projectIds->isEmpty()) {
            return response()->json([
                'scope' => 'assignment',
                'projects' => [],
                'message' => 'Kullaniciya atanmis proje kaydi bulunmuyor.',
            ]);
        }

        $query->whereIn('id', $projectIds);

        $scope = (str_contains($unit, 'medya') || str_contains($unit, 'media'))
            ? 'all_active_for_media_unit'
            : 'assignment';

        $projects = $query
            ->orderBy('name')
            ->get()
            ->map(function (Project $project) {
                $activePeriod = $project->activePeriods->first();
                $participants = $project->participants;

                return [
                    'id' => $project->id,
                    'name' => $project->name,
                    'slug' => $project->slug,
                    'type' => $project->type,
                    'status' => $project->status,
                    'short_description' => $project->short_description,
                    'description' => $project->description,
                    'active_period' => $activePeriod ? [
                        'id' => $activePeriod->id,
                        'name' => $activePeriod->name,
                        'start_date' => optional($activePeriod->start_date)?->toDateString(),
                        'end_date' => optional($activePeriod->end_date)?->toDateString(),
                    ] : null,
                    'participant_summary' => [
                        'total' => $participants->count(),
                        'active' => $participants->where('graduation_status', '!=', 'graduated')->count(),
                        'graduates' => $participants->where('graduation_status', 'graduated')->count(),
                    ],
                    'application_open' => (bool) $project->application_open,
                    'next_application_date' => optional($project->next_application_date)?->toDateString(),
                ];
            })
            ->values();

        return response()->json([
            'scope' => $scope,
            'projects' => $projects,
        ]);
    }

    public function exportMyProjects(Request $request)
    {
        $this->abortUnlessAllowed($request, 'projects.export');
        $user = $request->user();
        $projectIds = $this->visibleProjectIdsForStaff($user);

        $projects = Project::query()
            ->with(['activePeriods:id,project_id,name,start_date,end_date,status', 'participants:id,project_id,status,graduation_status'])
            ->when($projectIds->isNotEmpty(), fn ($query) => $query->whereIn('id', $projectIds))
            ->when($projectIds->isEmpty(), fn ($query) => $query->whereRaw('1 = 0'))
            ->orderBy('name')
            ->get();

        $headings = ['ID', 'Proje', 'Tur', 'Durum', 'Aktif Donem', 'Toplam Katilimci', 'Aktif Ogrenci', 'Mezun', 'Basvuru Durumu', 'Sonraki Basvuru Tarihi'];
        $rows = $projects->map(function (Project $project) {
            $activePeriod = $project->activePeriods->first();
            $participants = $project->participants;

            return [
                $project->id,
                $project->name,
                $project->type ?? '-',
                $project->status ?? '-',
                $activePeriod?->name ?? '-',
                $participants->count(),
                $participants->where('graduation_status', '!=', 'graduated')->count(),
                $participants->where('graduation_status', 'graduated')->count(),
                $project->application_open ? 'acik' : 'kapali',
                optional($project->next_application_date)?->toDateString() ?? '-',
            ];
        })->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'personel_projeleri_' . now()->format('Ymd_His'),
            'Personel Projeleri',
            $headings,
            $rows,
        );
    }

    /**
     * GET /staff/members
     * Personelin kendi birimine ait sade ekip listesini dondurur.
     */
    public function unitMembers(Request $request)
    {
        $this->abortUnlessAllowed($request, 'staff.view');
        $user = $request->user()->load('staffProfile');
        $unit = $user->staffProfile?->unit;

        if (!$unit) {
            return response()->json([
                'members' => [],
                'unit' => null,
                'message' => 'Kullaniciya bagli birim bilgisi bulunmuyor.',
            ]);
        }

        $this->abortUnlessUnitAllowed($request, 'staff.view', $unit);

        $query = User::with('staffProfile')
            ->whereIn('role', ['coordinator', 'staff'])
            ->where('status', '!=', 'banned')
            ->whereHas('staffProfile', fn($q) => $q->where('unit', $unit));

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn($q) =>
                $q->where('name', 'like', "%$search%")
                    ->orWhere('surname', 'like', "%$search%")
                    ->orWhere('email', 'like', "%$search%")
                    ->orWhereHas('staffProfile', fn($staffQ) =>
                        $staffQ->where('title', 'like', "%$search%")
                    )
            );
        }

        return response()->json([
            'unit' => $unit,
            'members' => $query->orderBy('name')->paginate(20),
        ]);
    }

    public function exportUnitMembers(Request $request)
    {
        $this->abortUnlessAllowed($request, 'staff.export');
        $user = $request->user()->load('staffProfile');
        $unit = $user->staffProfile?->unit;

        abort_if(! $unit, 422, 'Kullaniciya bagli birim bilgisi bulunmuyor.');
        $this->abortUnlessUnitAllowed($request, 'staff.export', $unit);

        $query = User::with('staffProfile')
            ->whereIn('role', ['coordinator', 'staff'])
            ->where('status', '!=', 'banned')
            ->whereHas('staffProfile', fn($q) => $q->where('unit', $unit));

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn($q) =>
                $q->where('name', 'like', "%$search%")
                    ->orWhere('surname', 'like', "%$search%")
                    ->orWhere('email', 'like', "%$search%")
                    ->orWhereHas('staffProfile', fn($staffQ) =>
                        $staffQ->where('title', 'like', "%$search%")
                    )
            );
        }

        $members = $query->orderBy('name')->get();
        $headings = ['ID', 'Ad', 'Soyad', 'E-posta', 'Telefon', 'Rol', 'Birim', 'Unvan'];
        $rows = $members->map(fn (User $member) => [
            $member->id,
            $member->name,
            $member->surname,
            $member->email,
            $member->phone ?? '-',
            $member->role,
            $member->staffProfile?->unit ?? '-',
            $member->staffProfile?->title ?? '-',
        ])->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'birim_uyeleri_' . now()->format('Ymd_His'),
            'Birim Uyeleri',
            $headings,
            $rows,
        );
    }

    /**
     * GET /admin/staff
     * Tüm personel listesi (filtreli).
     */
    public function index(Request $request)
    {
        $this->abortUnlessAllowed($request, 'staff.view');
        $query = User::with('staffProfile')
            ->whereIn('role', ['coordinator', 'staff'])
            ->where('status', '!=', 'banned');
        $query = $this->applyCoordinatorUnitScope($request, $query);

        if ($request->filled('unit')) {
            $query->whereHas('staffProfile', fn($q) => $q->where('unit', $request->unit));
        }
        if ($request->filled('title')) {
            $query->whereHas('staffProfile', fn($q) => $q->where('title', 'like', '%' . $request->title . '%'));
        }
        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn($q) =>
                $q->where('name', 'like', "%$search%")
                  ->orWhere('surname', 'like', "%$search%")
                  ->orWhere('email', 'like', "%$search%")
                  ->orWhere('phone', 'like', "%$search%")
            );
        }

        $staff = $query->paginate(20);

        return response()->json(['staff' => $staff]);
    }

    /**
     * GET /admin/staff/active
     * Şu anda giriş yapmış (aktif) ve izinli personeller.
     */
    public function active(Request $request)
    {
        $this->abortUnlessAllowed($request, 'staff.view');
        // Aktif personel: son 8 saat içinde token aktivitesi olanlar (yaklaşık)
        $activeStaff = User::with('staffProfile')
            ->whereIn('role', ['coordinator', 'staff'])
            ->whereHas('tokens', fn($q) => $q->where('last_used_at', '>=', now()->subHours(8)))
            ->tap(fn ($query) => $this->applyCoordinatorUnitScope($request, $query))
            ->get(['id', 'name', 'surname', 'email', 'role', 'profile_photo_path']);

        // İzinli personeller
        $onLeave = User::with(['staffProfile', 'leaveRequests' => fn($q) =>
            $q->where('status', 'approved')
              ->where('start_date', '<=', today())
              ->where('end_date', '>=', today())
        ])->whereIn('role', ['coordinator', 'staff'])
          ->tap(fn ($query) => $this->applyCoordinatorUnitScope($request, $query))
          ->whereHas('leaveRequests', fn($q) =>
              $q->where('status', 'approved')
                ->where('start_date', '<=', today())
                ->where('end_date', '>=', today())
          )->get(['id', 'name', 'surname', 'email', 'role']);

        return response()->json([
            'active_staff' => $activeStaff,
            'on_leave'     => $onLeave,
        ]);
    }

    /**
     * GET /admin/staff/{id}
     * Personel detayı (özlük bilgileri dahil).
     */
    public function show(Request $request, int $id)
    {
        $this->abortUnlessAllowed($request, 'staff.view');
        $user = User::with(['staffProfile', 'leaveRequests' => fn($q) => $q->latest()->take(10)])
            ->whereIn('role', ['coordinator', 'staff'])
            ->findOrFail($id);

        $this->abortUnlessUnitAllowed($request, 'staff.view', $user->staffProfile?->unit);

        return response()->json(['staff' => $user]);
    }

    /**
     * PUT /admin/staff/{id}
     * Personel özlük bilgisi güncelle.
     */
    public function update(Request $request, int $id)
    {
        $this->abortUnlessAllowed($request, 'staff.update');
        $user = User::with('staffProfile')->whereIn('role', ['coordinator', 'staff'])->findOrFail($id);
        $this->abortUnlessUnitAllowed($request, 'staff.update', $user->staffProfile?->unit);

        $validated = $request->validate([
            'title'         => 'nullable|string|max:255',
            'unit'          => 'nullable|string|max:255',
            'contract_type' => 'nullable|string|max:100',
            'start_date'    => 'nullable|date',
            'phone'         => 'nullable|string|max:20',
        ]);

        if (! $this->permissionResolver->hasGlobalScope($request->user(), 'staff.update')) {
            $currentUnit = $this->normalizeUnit($user->staffProfile?->unit);
            $requestedUnit = array_key_exists('unit', $validated)
                ? $this->normalizeUnit($validated['unit'])
                : $currentUnit;

            abort_unless(
                $currentUnit !== null && $requestedUnit === $currentUnit,
                403,
                'Birim kapsami olan kullanici personeli baska birime tasiyamaz.'
            );
        }

        // StaffProfile güncelle veya oluştur
        $user->staffProfile()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'title'         => $validated['title'] ?? null,
                'unit'          => $validated['unit'] ?? null,
                'contract_type' => $validated['contract_type'] ?? null,
                'start_date'    => $validated['start_date'] ?? null,
            ]
        );

        if (!empty($validated['phone'])) {
            $user->update(['phone' => $validated['phone']]);
        }

        return response()->json([
            'message' => 'Personel bilgileri güncellendi.',
            'staff'   => $user->fresh('staffProfile'),
        ]);
    }

    /**
     * POST /admin/staff/{id}/documents
     * Personel belgesi yükle (CV vb.).
     */
    public function uploadDocument(Request $request, int $id)
    {
        $this->abortUnlessAllowed($request, 'staff.documents.upload');
        $request->validate([
            'document' => 'required|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:10240',
            'label'    => 'nullable|string|max:100',
        ]);

        $user = User::whereIn('role', ['coordinator', 'staff'])->findOrFail($id);
        $this->abortUnlessUnitAllowed($request, 'staff.documents.upload', $user->staffProfile?->unit);
        $profile = $user->staffProfile()->firstOrCreate(['user_id' => $user->id]);

        $path = MediaStorage::putFile("staff/{$user->id}/documents", $request->file('document'));

        $docs = $profile->personal_documents ?? [];
        $docs[] = [
            'path'       => $path,
            'label'      => $request->label ?? $request->file('document')->getClientOriginalName(),
            'uploaded_at' => now()->toDateTimeString(),
        ];

        $profile->update(['personal_documents' => $docs]);

        return response()->json(['message' => 'Belge yüklendi.', 'documents' => $docs]);
    }

    /**
     * GET /admin/staff/export
     * Personel listesini CSV olarak dışa aktar.
     */
    public function export(Request $request)
    {
        $this->abortUnlessAllowed($request, 'staff.export');
        $query = User::with('staffProfile')
            ->whereIn('role', ['coordinator', 'staff'])
            ->where('status', '!=', 'banned');
        $query = $this->applyCoordinatorUnitScope($request, $query);

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn ($builder) =>
                $builder->where('name', 'like', "%$search%")
                    ->orWhere('surname', 'like', "%$search%")
                    ->orWhere('email', 'like', "%$search%")
                    ->orWhere('phone', 'like', "%$search%")
            );
        }

        $staff = $query->get();

        $headings = ['ID', 'Ad', 'Soyad', 'E-posta', 'Telefon', 'Rol', 'Unvan', 'Birim', 'Sozlesme Turu', 'Baslangic Tarihi'];
        $rows = $staff->map(fn (User $staffUser) => [
            $staffUser->id,
            $staffUser->name,
            $staffUser->surname,
            $staffUser->email,
            $staffUser->phone ?? '-',
            $staffUser->role,
            $staffUser->staffProfile->title ?? '-',
            $staffUser->staffProfile->unit ?? '-',
            $staffUser->staffProfile->contract_type ?? '-',
            $staffUser->staffProfile->start_date?->format('d.m.Y') ?? '-',
        ])->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'personel_listesi_' . now()->format('Ymd_His'),
            'Personel Listesi',
            $headings,
            $rows,
        );
    }

    public function exportLeaveRequests(Request $request)
    {
        $this->abortUnlessAllowed($request, 'staff.export');
        $query = LeaveRequest::with(['user:id,name,surname,email,role', 'approver:id,name,surname']);
        if ($unit = $this->coordinatorUnit($request->user()->loadMissing('staffProfile'))) {
            $query->whereHas('user.staffProfile', fn ($builder) => $builder->where('unit', $unit));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $leaveRequests = $query->latest()->get();

        $headings = ['ID', 'Personel', 'Rol', 'Baslangic', 'Bitis', 'Sebep', 'Durum', 'Onaylayan'];
        $rows = $leaveRequests->map(fn (LeaveRequest $leaveRequest) => [
            $leaveRequest->id,
            trim(($leaveRequest->user->name ?? '') . ' ' . ($leaveRequest->user->surname ?? '')),
            $leaveRequest->user->role ?? '-',
            $leaveRequest->start_date?->format('d.m.Y') ?? '-',
            $leaveRequest->end_date?->format('d.m.Y') ?? '-',
            $leaveRequest->reason ?? '-',
            $leaveRequest->status,
            $leaveRequest->approver ? trim($leaveRequest->approver->name . ' ' . $leaveRequest->approver->surname) : '-',
        ])->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'izin_talepleri_' . now()->format('Ymd_His'),
            'Izin Talepleri',
            $headings,
            $rows,
        );
    }

    // ─── İZİN TALEPLERİ ───────────────────────────────────────────────────────

    /**
     * GET /admin/leave-requests
     * Tüm izin taleplerini listele.
     */
    public function leaveRequests(Request $request)
    {
        $this->abortUnlessAllowed($request, 'staff.view');
        $query = LeaveRequest::with(['user:id,name,surname,email,role', 'approver:id,name,surname']);
        if ($unit = $this->coordinatorUnit($request->user()->loadMissing('staffProfile'))) {
            $query->whereHas('user.staffProfile', fn ($builder) => $builder->where('unit', $unit));
        }

        if ($request->filled('status')) $query->where('status', $request->status);
        if ($request->filled('user_id')) $query->where('user_id', $request->user_id);

        return response()->json(['leave_requests' => $query->latest()->paginate(20)]);
    }

    /**
     * POST /leave-requests  (Personel izin talep eder)
     */
    public function storeLeaveRequest(Request $request)
    {
        $this->abortUnlessAllowed($request, 'staff.leave.request');

        $validated = $request->validate([
            'start_date' => 'required|date|after_or_equal:today',
            'end_date'   => 'required|date|after_or_equal:start_date',
            'reason'     => 'nullable|string|max:1000',
        ]);

        $leave = LeaveRequest::create([
            'user_id'    => Auth::id(),
            'start_date' => $validated['start_date'],
            'end_date'   => $validated['end_date'],
            'reason'     => $validated['reason'] ?? null,
            'status'     => 'pending',
        ]);

        return response()->json(['message' => 'İzin talebiniz iletildi.', 'leave_request' => $leave], 201);
    }

    /**
     * PUT /admin/leave-requests/{id}/approve
     */
    public function approveLeave(Request $request, int $id)
    {
        $this->abortUnlessAllowed($request, 'staff.leave.approve');
        $leave = LeaveRequest::with('user.staffProfile')->findOrFail($id);
        $this->abortUnlessUnitAllowed($request, 'staff.leave.approve', $leave->user?->staffProfile?->unit);
        $leave->update(['status' => 'approved', 'approved_by' => Auth::id()]);

        return response()->json(['message' => 'İzin talebi onaylandı.', 'leave_request' => $leave]);
    }

    /**
     * PUT /admin/leave-requests/{id}/reject
     */
    public function rejectLeave(Request $request, int $id)
    {
        $this->abortUnlessAllowed($request, 'staff.leave.reject');
        $leave = LeaveRequest::with('user.staffProfile')->findOrFail($id);
        $this->abortUnlessUnitAllowed($request, 'staff.leave.reject', $leave->user?->staffProfile?->unit);
        $leave->update(['status' => 'rejected', 'approved_by' => Auth::id()]);

        return response()->json(['message' => 'İzin talebi reddedildi.', 'leave_request' => $leave]);
    }

    /**
     * GET /staff/my-leave-requests  (Personelin kendi izinleri)
     */
    public function myLeaveRequests(Request $request)
    {
        $this->abortUnlessAllowed($request, 'staff.leave.request');

        $leaves = LeaveRequest::where('user_id', Auth::id())
            ->with('approver:id,name,surname')
            ->latest()
            ->get();

        return response()->json(['leave_requests' => $leaves]);
    }
}
