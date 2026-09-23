<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\CoordinationUnitMembership;
use App\Models\LeaveRequest;
use App\Models\Project;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\PermissionResolver;
use App\Services\WorkflowStatusHistoryRecorder;
use App\Support\AdminExportResponder;
use App\Support\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * @group Staff
 */
class StaffController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver,
        private readonly NotificationService $notificationService,
        private readonly WorkflowStatusHistoryRecorder $statusHistoryRecorder,
    ) {}

    private function notifyLeaveApprovers(LeaveRequest $leaveRequest): void
    {
        $leaveRequest->loadMissing('user.staffProfile');
        $unit = $leaveRequest->user?->staffProfile?->unit;

        if ($leaveRequest->reviewer_scope === 'super_admin') {
            $emails = User::query()
                ->where('status', 'active')
                ->where('role', 'super_admin')
                ->pluck('email');
        } elseif ($leaveRequest->unit_id !== null) {
            $emails = User::query()
                ->where('status', 'active')
                ->whereHas('coordinationUnitMemberships', fn ($query) => $query
                    ->active()
                    ->where('unit_id', $leaveRequest->unit_id)
                    ->where('position', CoordinationUnitMembership::POSITION_COORDINATOR))
                ->pluck('email');

            if ($emails->filter()->isEmpty()) {
                $emails = User::query()
                    ->where('status', 'active')
                    ->where('role', 'super_admin')
                    ->pluck('email');
            }
        } else {
            // Birim snapshot'i olmayan eski kayitlar icin legacy bildirim rotasi korunur.
            $emails = User::query()
                ->where('status', 'active')
                ->where(function ($query) use ($unit) {
                    $query->where('role', 'super_admin');
                    if ($unit) {
                        $query->orWhere(function ($inner) use ($unit) {
                            $inner->where('role', 'coordinator')
                                ->whereHas('staffProfile', fn ($builder) => $builder->where('unit', $unit));
                        });
                    }
                })
                ->pluck('email');
        }

        $emails = $emails->filter()->unique()->values()->all();

        if ($emails === []) {
            return;
        }

        $this->notificationService->sendEmail(
            $emails,
            'Yeni izin talebi',
            "Personel: {$leaveRequest->user?->name} {$leaveRequest->user?->surname}\nBaslangic: {$leaveRequest->start_date}\nBitis: {$leaveRequest->end_date}",
            null,
            $leaveRequest->user_id
        );
    }

    private function canReviewLeave(User $actor, LeaveRequest $leave, string $permission): bool
    {
        if ((int) $leave->user_id === (int) $actor->id
            || ! $this->permissionResolver->hasPermission($actor, $permission)) {
            return false;
        }

        if ($this->permissionResolver->hasGlobalScope($actor, $permission)) {
            return true;
        }

        if ($leave->reviewer_scope === 'super_admin') {
            return false;
        }

        if ($leave->unit_id !== null) {
            return $this->permissionResolver->canAccessCoordinationUnit(
                $actor,
                $permission,
                (int) $leave->unit_id
            );
        }

        return $this->permissionResolver->canAccessUnit(
            $actor,
            $permission,
            $leave->user?->staffProfile?->unit
        );
    }

    private function applyLeaveVisibility(Request $request, $query, string $permission): void
    {
        $actor = $request->user()->loadMissing('staffProfile');
        if ($this->permissionResolver->hasGlobalScope($actor, $permission)) {
            return;
        }

        $unitIds = $this->permissionResolver->coordinationUnitIdsForPermission($actor, $permission);
        $legacyUnit = $this->coordinatorUnit($actor, $permission);

        $query->where(function ($builder) use ($unitIds, $legacyUnit) {
            if ($unitIds !== []) {
                $builder->whereIn('unit_id', $unitIds);
            } else {
                $builder->whereRaw('1 = 0');
            }

            if ($legacyUnit) {
                $builder->orWhere(function ($legacyQuery) use ($legacyUnit) {
                    $legacyQuery->whereNull('unit_id')
                        ->whereHas('user.staffProfile', fn ($profileQuery) => $profileQuery->where('unit', $legacyUnit));
                });
            }
        });
    }

    private function visibleProjectIdsForStaff(User $user): Collection
    {
        return collect($this->permissionResolver->projectIdsForPermission($user, 'projects.export'));
    }

    private function coordinatorUnit(?User $user, string $permission): ?string
    {
        if (! $user || ! $this->permissionResolver->hasPermission($user, $permission)) {
            return null;
        }

        if ($this->permissionResolver->hasGlobalScope($user, $permission)) {
            return null;
        }

        return $this->permissionResolver->unitNameForPermission($user, $permission);
    }

    private function applyCoordinatorUnitScope(Request $request, $query, string $permission = 'staff.view')
    {
        $this->permissionResolver->applyUserScope($query, $request->user(), $permission);

        return $query;
    }

    private function normalizeUnit(?string $unit): ?string
    {
        $normalized = mb_strtolower(trim((string) $unit));

        return $normalized === '' ? null : $normalized;
    }

    private function documentsWithUrls(array $documents): array
    {
        return array_map(function (array $document) {
            $document['url'] = MediaStorage::url($document['path'] ?? null);

            return $document;
        }, $documents);
    }

    private function applyEmployeeScope($query)
    {
        return $query->where(function ($builder) {
            $builder
                ->whereIn('role', ['coordinator', 'staff'])
                ->orWhereHas('staffProfile')
                ->orWhereHas('coordinatedProjects')
                ->orWhereHas('assignedProjects')
                ->orWhere(function ($customRoleQuery) {
                    $customRoleQuery
                        ->whereNotNull('role')
                        ->whereNotIn('role', ['super_admin', 'student', 'alumni', 'visitor']);
                });
        });
    }

    private function projectSummary(User $user): array
    {
        $coordinated = $user->coordinatedProjects
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'assignment_type' => 'coordinator',
            ]);

        $assigned = $user->assignedProjects
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'assignment_type' => 'staff',
            ]);

        return collect($coordinated->all())
            ->merge($assigned)
            ->unique(fn (array $project) => $project['assignment_type'].':'.$project['id'])
            ->values()
            ->all();
    }

    /**
     * Get staff creation options.
     *
     * Requires permission: `staff.update` with global `all` scope. Returns assignable non-participant, non-super-admin Spatie roles for the panel staff creation form.
     *
     * @group Staff
     *
     * @response 200 {"roles":[{"name":"coordinator","label":"Koordinator"},{"name":"staff","label":"Personel"}]}
     * @response 403 {"message":"Calisan olusturmak icin tum sistem kapsami gerekir."}
     */
    public function createOptions(Request $request)
    {
        $this->abortUnlessAllowed($request, 'staff.update');
        abort_unless(
            $this->permissionResolver->hasGlobalScope($request->user(), 'staff.update'),
            403,
            'Calisan olusturmak icin tum sistem kapsami gerekir.'
        );

        $roles = Role::query()
            ->whereNotIn('name', ['super_admin', 'student', 'alumni', 'visitor'])
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => [
                'name' => $role->name,
                'label' => config('permission_catalog.role_labels.'.$role->name) ?? Str::headline($role->name),
            ])
            ->values();

        return response()->json(['roles' => $roles]);
    }

    /**
     * Create a staff account.
     *
     * Requires permission: `staff.update` with global `all` scope. Creates an active coordinator/staff/custom authority user, syncs the selected role, creates the staff profile, assigns coordinated projects for coordinators or assigned projects for other staff, and sends the password setup email.
     *
     * @group Staff
     *
     * @bodyParam name string required Staff first name. Example: Ayse
     * @bodyParam surname string required Staff surname. Example: Yilmaz
     * @bodyParam email string required Unique email address. Example: ayse@example.com
     * @bodyParam phone string Optional phone number. Example: 05550000000
     * @bodyParam tc_no string Optional identity number, exactly 11 characters. Example: 12345678901
     * @bodyParam role string required Existing role name except super_admin/student/alumni/visitor. Example: coordinator
     * @bodyParam unit string Optional unit name. Defaults to Genel. Example: Program
     * @bodyParam title string Optional staff title. Allowed values: researcher, specialist, coordinator, manager, other. Example: coordinator
     * @bodyParam contract_type string Optional contract type. Example: full_time
     * @bodyParam project_ids array Optional project IDs to assign. Coordinators receive coordinated projects; other staff receive assigned projects. Example: [1,2]
     *
     * @response 201 {"message":"Calisan olusturuldu. Sifre belirleme baglantisi e-posta ile gonderildi.","staff":{"id":8,"role":"coordinator","projects":[{"id":1,"name":"KADEME","assignment_type":"coordinator"}]},"reset_email_status":"passwords.sent"}
     * @response 422 {"message":"Bu rol personel ekranindan olusturulamaz."}
     */
    public function store(Request $request)
    {
        $this->abortUnlessAllowed($request, 'staff.update');
        abort_unless(
            $this->permissionResolver->hasGlobalScope($request->user(), 'staff.update'),
            403,
            'Calisan olusturmak icin tum sistem kapsami gerekir.'
        );

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'surname' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'phone' => 'nullable|string|max:20',
            'tc_no' => 'nullable|string|size:11',
            'role' => 'required|string|exists:roles,name',
            'unit' => 'nullable|string|max:255',
            'title' => ['nullable', 'string', 'max:255', Rule::in(['researcher', 'specialist', 'coordinator', 'manager', 'other'])],
            'contract_type' => 'nullable|string|max:100',
            'project_ids' => 'sometimes|array',
            'project_ids.*' => 'integer|exists:projects,id',
        ]);

        $roleName = $validated['role'];
        abort_if(in_array($roleName, ['super_admin', 'student', 'alumni', 'visitor'], true), 422, 'Bu rol personel ekranindan olusturulamaz.');

        $passwordPlain = Str::password(24);
        $user = User::create([
            'name' => trim($validated['name']),
            'surname' => trim($validated['surname']),
            'email' => Str::lower(trim($validated['email'])),
            'phone' => isset($validated['phone']) ? trim((string) $validated['phone']) : null,
            'tc_no' => $validated['tc_no'] ?? null,
            'password' => Hash::make($passwordPlain),
            'role' => $roleName,
            'status' => 'active',
            'email_verified_at' => now(),
            'must_change_password' => true,
        ]);

        $user->syncRoles([$roleName]);
        $user->staffProfile()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'title' => $validated['title'] ?? ($roleName === 'coordinator' ? 'coordinator' : 'specialist'),
                'unit' => trim((string) ($validated['unit'] ?? 'Genel')) ?: 'Genel',
                'contract_type' => $validated['contract_type'] ?? 'full_time',
                'start_date' => now()->toDateString(),
            ]
        );

        $projectIds = collect($validated['project_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        if ($roleName === 'coordinator') {
            $user->coordinatedProjects()->sync($projectIds);
        } else {
            $user->assignedProjects()->sync($projectIds);
        }

        $linkStatus = Password::sendResetLink(['email' => $user->email]);
        $fresh = $user->fresh(['staffProfile', 'coordinatedProjects:id,name', 'assignedProjects:id,name', 'roles:id,name']);
        $fresh->setAttribute('projects', $this->projectSummary($fresh));

        return response()->json([
            'message' => $linkStatus === Password::RESET_LINK_SENT
                ? 'Calisan olusturuldu. Sifre belirleme baglantisi e-posta ile gonderildi.'
                : 'Calisan olusturuldu. E-posta gonderilemedi; kullanici "Sifremi unuttum" ile baglanti talep edebilir.',
            'staff' => $fresh,
            'reset_email_status' => $linkStatus,
        ], 201);
    }

    /**
     * List the current staff user projects.
     *
     * Requires permission: `projects.view`. Project visibility comes from `PermissionResolver::projectIdsForPermission`; if no project is visible an empty assignment response is returned. Media unit users are labelled with an `all_active_for_media_unit` scope in the response.
     *
     * @group Staff
     *
     * @response 200 {"scope":"assignment","projects":[{"id":1,"name":"KADEME","active_period":{"id":3,"name":"2026 Bahar"},"participant_summary":{"total":40,"active":35,"graduates":5}}]}
     * @response 403 {"message":"Bu islem icin yetkiniz bulunmuyor."}
     */
    public function myProjects(Request $request)
    {
        $this->abortUnlessAllowed($request, 'projects.view');
        $user = $request->user()->load('staffProfile');
        $unit = mb_strtolower((string) $this->permissionResolver->unitNameForPermission($user, 'projects.view'));

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

    /**
     * Export the current staff user projects.
     *
     * Requires permission: `projects.export`. Uses the same project visibility resolver as the staff project list and exports through the shared admin export responder.
     *
     * @group Staff
     *
     * @queryParam format string Optional export format. Allowed values: xlsx, excel, pdf, docx, word, csv. Defaults to csv. Example: xlsx
     *
     * @response 200 {"download":"Staff project export file stream"}
     */
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
            'personel_projeleri_'.now()->format('Ymd_His'),
            'Personel Projeleri',
            $headings,
            $rows,
        );
    }

    /**
     * List members visible to the current staff user.
     *
     * Requires permission: `staff.view`. The current user must have a staff unit. Rows are filtered through `PermissionResolver::applyUserScope`, so global users can see all matching staff while own-unit users only see their unit.
     *
     * @group Staff
     *
     * @queryParam search string Optional name, email or title search. Example: uzman
     *
     * @response 200 {"unit":"Program","members":{"data":[{"id":8,"name":"Ayse","role":"coordinator","staff_profile":{"unit":"Program"}}],"current_page":1}}
     * @response 200 {"members":[],"unit":null,"message":"Kullaniciya bagli birim bilgisi bulunmuyor."}
     */
    public function unitMembers(Request $request)
    {
        $this->abortUnlessAllowed($request, 'staff.view');
        $user = $request->user()->load('staffProfile');
        $unit = $this->permissionResolver->unitNameForPermission($user, 'staff.view');

        if (! $unit) {
            return response()->json([
                'members' => [],
                'unit' => null,
                'message' => 'Kullaniciya bagli birim bilgisi bulunmuyor.',
            ]);
        }

        $query = User::with('staffProfile')
            ->whereIn('role', ['coordinator', 'staff'])
            ->where('status', '!=', 'banned');

        // Scope kurali own_unit/all durumuna gore tek noktadan uygulanir.
        $this->permissionResolver->applyUserScope($query, $user, 'staff.view');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn ($q) => $q->where('name', 'like', "%$search%")
                ->orWhere('surname', 'like', "%$search%")
                ->orWhere('email', 'like', "%$search%")
                ->orWhereHas('staffProfile', fn ($staffQ) => $staffQ->where('title', 'like', "%$search%")
                )
            );
        }

        return response()->json([
            'unit' => $unit,
            'members' => $query->orderBy('name')->paginate(20),
        ]);
    }

    /**
     * Export members visible to the current staff user.
     *
     * Requires permission: `staff.export`. The current user must have a staff unit and the export rows are filtered through `PermissionResolver::applyUserScope`.
     *
     * @group Staff
     *
     * @queryParam search string Optional name, email or title search. Example: uzman
     * @queryParam format string Optional export format. Allowed values: xlsx, excel, pdf, docx, word, csv. Defaults to csv. Example: csv
     *
     * @response 200 {"download":"Unit members export file stream"}
     * @response 422 {"message":"Kullaniciya bagli birim bilgisi bulunmuyor."}
     */
    public function exportUnitMembers(Request $request)
    {
        $this->abortUnlessAllowed($request, 'staff.export');
        $user = $request->user()->load('staffProfile');
        $unit = $this->permissionResolver->unitNameForPermission($user, 'staff.export');

        abort_if(! $unit, 422, 'Kullaniciya bagli birim bilgisi bulunmuyor.');

        $query = User::with('staffProfile')
            ->whereIn('role', ['coordinator', 'staff'])
            ->where('status', '!=', 'banned');

        $this->permissionResolver->applyUserScope($query, $user, 'staff.export');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn ($q) => $q->where('name', 'like', "%$search%")
                ->orWhere('surname', 'like', "%$search%")
                ->orWhere('email', 'like', "%$search%")
                ->orWhereHas('staffProfile', fn ($staffQ) => $staffQ->where('title', 'like', "%$search%")
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
            'birim_uyeleri_'.now()->format('Ymd_His'),
            'Birim Uyeleri',
            $headings,
            $rows,
        );
    }

    /**
     * List panel staff.
     *
     * Requires permission: `staff.view`. Returns authority users and users with staff/project assignments. Global scope can see every staff record; non-global staff view scope is limited to the caller staff unit through `applyCoordinatorUnitScope`.
     *
     * @group Staff
     *
     * @queryParam project_id integer Optional project assignment filter. Example: 1
     * @queryParam unit string Optional staff unit filter. Example: Program
     * @queryParam title string Optional title search. Example: coordinator
     * @queryParam role string Optional role filter. Example: staff
     * @queryParam search string Optional name, surname, email or phone search. Example: ayse
     *
     * @response 200 {"staff":{"data":[{"id":8,"name":"Ayse","role":"coordinator","projects":[{"id":1,"assignment_type":"coordinator"}]}],"current_page":1}}
     */
    public function index(Request $request)
    {
        $this->abortUnlessAllowed($request, 'staff.view');
        $query = User::with(['staffProfile', 'coordinatedProjects:id,name', 'assignedProjects:id,name'])
            ->where('status', '!=', 'banned');
        $this->applyEmployeeScope($query);
        $query = $this->applyCoordinatorUnitScope($request, $query);

        if ($request->filled('project_id')) {
            $projectId = (int) $request->input('project_id');
            $query->where(function ($builder) use ($projectId) {
                $builder
                    ->whereHas('coordinatedProjects', fn ($projectQuery) => $projectQuery->where('projects.id', $projectId))
                    ->orWhereHas('assignedProjects', fn ($projectQuery) => $projectQuery->where('projects.id', $projectId));
            });
        }
        if ($request->filled('unit')) {
            $query->whereHas('staffProfile', fn ($q) => $q->where('unit', $request->unit));
        }
        if ($request->filled('title')) {
            $query->whereHas('staffProfile', fn ($q) => $q->where('title', 'like', '%'.$request->title.'%'));
        }
        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn ($q) => $q->where('name', 'like', "%$search%")
                ->orWhere('surname', 'like', "%$search%")
                ->orWhere('email', 'like', "%$search%")
                ->orWhere('phone', 'like', "%$search%")
            );
        }

        $staff = $query->paginate(20);

        $staff->getCollection()->transform(function (User $user) {
            $user->setAttribute('projects', $this->projectSummary($user));

            return $user;
        });

        return response()->json(['staff' => $staff]);
    }

    /**
     * List active and on-leave staff.
     *
     * Requires permission: `staff.view`. Active staff are approximated by Sanctum tokens used in the last 8 hours; on-leave staff are approved leave requests covering today. Non-global viewers are limited to their unit.
     *
     * @group Staff
     *
     * @response 200 {"active_staff":[{"id":8,"name":"Ayse","role":"coordinator"}],"on_leave":[{"id":9,"name":"Mehmet","role":"staff"}]}
     */
    public function active(Request $request)
    {
        $this->abortUnlessAllowed($request, 'staff.view');
        // Aktif personel: son 8 saat içinde token aktivitesi olanlar (yaklaşık)
        $activeStaff = User::with('staffProfile')
            ->tap(fn ($query) => $this->applyEmployeeScope($query))
            ->whereHas('tokens', fn ($q) => $q->where('last_used_at', '>=', now()->subHours(8)))
            ->tap(fn ($query) => $this->applyCoordinatorUnitScope($request, $query))
            ->get(['id', 'name', 'surname', 'email', 'role', 'profile_photo_path']);

        // İzinli personeller
        $onLeave = User::with(['staffProfile', 'leaveRequests' => fn ($q) => $q->where('status', 'approved')
            ->where('start_date', '<=', today())
            ->where('end_date', '>=', today()),
        ])->tap(fn ($query) => $this->applyEmployeeScope($query))
            ->tap(fn ($query) => $this->applyCoordinatorUnitScope($request, $query))
            ->whereHas('leaveRequests', fn ($q) => $q->where('status', 'approved')
                ->where('start_date', '<=', today())
                ->where('end_date', '>=', today())
            )->get(['id', 'name', 'surname', 'email', 'role']);

        return response()->json([
            'active_staff' => $activeStaff,
            'on_leave' => $onLeave,
        ]);
    }

    /**
     * Get panel staff details.
     *
     * Requires permission: `staff.view`. The target must be an authority/staff-like user. Unit access is enforced through `abortUnlessUnitAllowed`; personal document paths are returned with storage URLs and project assignments are summarized.
     *
     * @group Staff
     *
     * @urlParam id integer required Staff user ID. Example: 8
     *
     * @response 200 {"staff":{"id":8,"name":"Ayse","role":"coordinator","staff_profile":{"unit":"Program","personal_documents":[{"label":"CV","url":"https://storage.example.com/cv.pdf"}]},"projects":[{"id":1,"assignment_type":"coordinator"}]}}
     * @response 403 {"message":"Bu birim icin yetkiniz bulunmuyor."}
     */
    public function show(Request $request, int $id)
    {
        $this->abortUnlessAllowed($request, 'staff.view');
        $user = User::with([
            'staffProfile',
            'coordinatedProjects:id,name',
            'assignedProjects:id,name',
            'leaveRequests' => fn ($q) => $q->latest()->take(10),
        ])->findOrFail($id);
        abort_unless(
            in_array($user->role, ['coordinator', 'staff'], true)
                || $user->staffProfile
                || $user->coordinatedProjects->isNotEmpty()
                || $user->assignedProjects->isNotEmpty()
                || ($user->role && ! in_array($user->role, ['super_admin', 'student', 'alumni', 'visitor'], true)),
            404
        );

        abort_unless(
            $this->permissionResolver->canAccessUser($request->user(), 'staff.view', $user),
            403,
            'Bu birim icin yetkiniz bulunmuyor.'
        );

        if ($user->staffProfile) {
            $user->staffProfile->personal_documents = $this->documentsWithUrls($user->staffProfile->personal_documents ?? []);
        }

        $user->setAttribute('projects', $this->projectSummary($user));

        return response()->json(['staff' => $user]);
    }

    /**
     * Sync staff project assignments.
     *
     * Requires permission: `staff.update` with global `all` scope. Super admin and participant/visitor accounts cannot be managed here. Coordinators receive `coordinated_project_ids`; other staff receive `assigned_project_ids`; the unused relation is cleared.
     *
     * @group Staff
     *
     * @urlParam id integer required Staff user ID. Example: 8
     *
     * @bodyParam coordinated_project_ids array required Project IDs for coordinator assignment. Send empty array when not applicable. Example: [1]
     * @bodyParam assigned_project_ids array required Project IDs for staff assignment. Send empty array when not applicable. Example: [2]
     *
     * @response 200 {"message":"Calisan proje atamalari guncellendi.","staff":{"id":8,"projects":[{"id":1,"assignment_type":"coordinator"}]}}
     * @response 403 {"message":"Proje atamasi icin tum sistem kapsami gerekir."}
     */
    public function syncProjects(Request $request, int $id)
    {
        $this->abortUnlessAllowed($request, 'staff.update');
        abort_unless(
            $this->permissionResolver->hasGlobalScope($request->user(), 'staff.update'),
            403,
            'Proje atamasi icin tum sistem kapsami gerekir.'
        );

        $user = User::with(['staffProfile', 'coordinatedProjects:id,name', 'assignedProjects:id,name'])->findOrFail($id);
        abort_if($user->role === 'super_admin', 422, 'Ust admin hesabi proje gorevlendirme listesinden yonetilemez.');
        abort_if(in_array($user->role, ['student', 'alumni', 'visitor'], true), 422, 'Bu kullanici calisan rolu tasimiyor.');

        $validated = $request->validate([
            'coordinated_project_ids' => 'present|array',
            'coordinated_project_ids.*' => 'integer|exists:projects,id',
            'assigned_project_ids' => 'present|array',
            'assigned_project_ids.*' => 'integer|exists:projects,id',
        ]);

        if ($user->role === 'coordinator') {
            $user->coordinatedProjects()->sync($validated['coordinated_project_ids']);
            $user->assignedProjects()->sync([]);
        } else {
            $user->coordinatedProjects()->sync([]);
            $user->assignedProjects()->sync($validated['assigned_project_ids']);
        }

        if (! $user->staffProfile) {
            $user->staffProfile()->firstOrCreate(
                ['user_id' => $user->id],
                [
                    'title' => $user->role === 'coordinator' ? 'coordinator' : 'specialist',
                    'unit' => 'Genel',
                    'contract_type' => 'full_time',
                    'start_date' => now()->toDateString(),
                ]
            );
        }

        $fresh = $user->fresh(['staffProfile', 'coordinatedProjects:id,name', 'assignedProjects:id,name']);
        $fresh->setAttribute('projects', $this->projectSummary($fresh));

        return response()->json([
            'message' => 'Calisan proje atamalari guncellendi.',
            'staff' => $fresh,
        ]);
    }

    /**
     * Update staff profile information.
     *
     * Requires permission: `staff.update`. Unit access is enforced with `abortUnlessUnitAllowed`; non-global users cannot move staff to another unit. Updates staff profile fields and optionally the user phone number.
     *
     * @group Staff
     *
     * @urlParam id integer required Staff user ID. Example: 8
     *
     * @bodyParam title string Optional title. Allowed values: researcher, specialist, coordinator, manager, other. Example: specialist
     * @bodyParam unit string Optional unit name. Example: Program
     * @bodyParam contract_type string Optional contract type. Example: full_time
     * @bodyParam start_date date Optional start date. Example: 2026-01-15
     * @bodyParam phone string Optional phone number. Example: 05550000000
     *
     * @response 200 {"message":"Personel bilgileri guncellendi.","staff":{"id":8,"staff_profile":{"unit":"Program","title":"specialist"}}}
     * @response 403 {"message":"Birim kapsami olan kullanici personeli baska birime tasiyamaz."}
     */
    public function update(Request $request, int $id)
    {
        $this->abortUnlessAllowed($request, 'staff.update');
        $user = User::with('staffProfile')->findOrFail($id);
        abort_if(in_array($user->role, ['super_admin', 'student', 'alumni', 'visitor'], true), 422, 'Bu kullanici calisan rolu tasimiyor.');
        abort_unless(
            $this->permissionResolver->canAccessUser($request->user(), 'staff.update', $user),
            403,
            'Bu birim icin yetkiniz bulunmuyor.'
        );

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255', Rule::in(['researcher', 'specialist', 'coordinator', 'manager', 'other'])],
            'unit' => 'nullable|string|max:255',
            'contract_type' => 'nullable|string|max:100',
            'start_date' => 'nullable|date',
            'phone' => 'nullable|string|max:20',
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
                'title' => $validated['title'] ?? null,
                'unit' => $validated['unit'] ?? null,
                'contract_type' => $validated['contract_type'] ?? null,
                'start_date' => $validated['start_date'] ?? null,
            ]
        );

        if (! empty($validated['phone'])) {
            $user->update(['phone' => $validated['phone']]);
        }

        return response()->json([
            'message' => 'Personel bilgileri güncellendi.',
            'staff' => $user->fresh('staffProfile'),
        ]);
    }

    /**
     * Upload a staff personal document.
     *
     * Requires permission: `staff.documents.upload` and unit access for the target staff member. Stores the file through `MediaStorage` under the staff document folder and appends it to `staff_profile.personal_documents` with a label and URL.
     *
     * @group Staff
     *
     * @urlParam id integer required Staff user ID. Example: 8
     *
     * @bodyParam document file required Staff document. Allowed: pdf, doc, docx, jpg, jpeg, png. Max 10 MB.
     * @bodyParam label string Optional document label. Max 100 characters. Example: CV
     *
     * @response 200 {"message":"Belge yuklendi.","documents":[{"label":"CV","url":"https://storage.example.com/staff/8/documents/cv.pdf"}]}
     * @response 422 {"message":"Bu kullanici calisan rolu tasimiyor."}
     */
    public function uploadDocument(Request $request, int $id)
    {
        $this->abortUnlessAllowed($request, 'staff.documents.upload');
        $request->validate([
            'document' => 'required|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:10240',
            'label' => 'nullable|string|max:100',
        ]);

        $user = User::findOrFail($id);
        abort_if(in_array($user->role, ['super_admin', 'student', 'alumni', 'visitor'], true), 422, 'Bu kullanici calisan rolu tasimiyor.');
        abort_unless(
            $this->permissionResolver->canAccessUser($request->user(), 'staff.documents.upload', $user),
            403,
            'Bu birim icin yetkiniz bulunmuyor.'
        );
        $profile = $user->staffProfile()->firstOrCreate(['user_id' => $user->id]);

        $path = MediaStorage::putFile("staff/{$user->id}/documents", $request->file('document'));

        $docs = $profile->personal_documents ?? [];
        $docs[] = [
            'path' => $path,
            'url' => MediaStorage::url($path),
            'label' => $request->label ?? $request->file('document')->getClientOriginalName(),
            'uploaded_at' => now()->toDateTimeString(),
        ];

        $profile->update(['personal_documents' => $docs]);

        return response()->json(['message' => 'Belge yüklendi.', 'documents' => $this->documentsWithUrls($docs)]);
    }

    /**
     * Export panel staff.
     *
     * Requires permission: `staff.export`. Applies employee and coordinator unit scope, supports project/role/search filters and exports through the shared admin export responder.
     *
     * @group Staff
     *
     * @queryParam project_id integer Optional project assignment filter. Example: 1
     * @queryParam role string Optional role filter. Example: staff
     * @queryParam search string Optional search filter. Example: ayse
     * @queryParam format string Optional export format. Allowed values: xlsx, excel, pdf, docx, word, csv. Defaults to csv. Example: xlsx
     *
     * @response 200 {"download":"Staff export file stream"}
     */
    public function export(Request $request)
    {
        $this->abortUnlessAllowed($request, 'staff.export');
        $query = User::with(['staffProfile', 'coordinatedProjects:id,name', 'assignedProjects:id,name'])
            ->where('status', '!=', 'banned');
        $this->applyEmployeeScope($query);
        $query = $this->applyCoordinatorUnitScope($request, $query, 'staff.export');

        if ($request->filled('project_id')) {
            $projectId = (int) $request->input('project_id');
            $query->where(function ($builder) use ($projectId) {
                $builder
                    ->whereHas('coordinatedProjects', fn ($projectQuery) => $projectQuery->where('projects.id', $projectId))
                    ->orWhereHas('assignedProjects', fn ($projectQuery) => $projectQuery->where('projects.id', $projectId));
            });
        }

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn ($builder) => $builder->where('name', 'like', "%$search%")
                ->orWhere('surname', 'like', "%$search%")
                ->orWhere('email', 'like', "%$search%")
                ->orWhere('phone', 'like', "%$search%")
            );
        }

        $staff = $query->get();

        $headings = ['ID', 'Ad', 'Soyad', 'E-posta', 'Telefon', 'Rol', 'Unvan', 'Birim', 'Projeler', 'Sozlesme Turu', 'Baslangic Tarihi'];
        $rows = $staff->map(fn (User $staffUser) => [
            $staffUser->id,
            $staffUser->name,
            $staffUser->surname,
            $staffUser->email,
            $staffUser->phone ?? '-',
            $staffUser->role,
            $staffUser->staffProfile->title ?? '-',
            $staffUser->staffProfile->unit ?? '-',
            collect($this->projectSummary($staffUser))->map(fn (array $project) => $project['name'].' ('.$project['assignment_type'].')')->join(', ') ?: '-',
            $staffUser->staffProfile->contract_type ?? '-',
            $staffUser->staffProfile->start_date?->format('d.m.Y') ?? '-',
        ])->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'personel_listesi_'.now()->format('Ymd_His'),
            'Personel Listesi',
            $headings,
            $rows,
        );
    }

    /**
     * Export staff leave requests.
     *
     * Requires permission: `staff.export`. Global users can export all leave requests; non-global staff view scope is limited to the caller unit. Exports through the shared admin export responder.
     *
     * @group Staff
     *
     * @queryParam status string Optional leave status filter. Example: pending
     * @queryParam format string Optional export format. Allowed values: xlsx, excel, pdf, docx, word, csv. Defaults to csv. Example: csv
     *
     * @response 200 {"download":"Leave requests export file stream"}
     */
    public function exportLeaveRequests(Request $request)
    {
        $this->abortUnlessAllowed($request, 'staff.export');
        $query = LeaveRequest::with(['user:id,name,surname,email,role', 'approver:id,name,surname', 'unit:id,code,name,kind']);
        $this->applyLeaveVisibility($request, $query, 'staff.export');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $leaveRequests = $query->latest()->get();

        $headings = ['ID', 'Personel', 'Rol', 'Baslangic', 'Bitis', 'Sebep', 'Durum', 'Onaylayan'];
        $rows = $leaveRequests->map(fn (LeaveRequest $leaveRequest) => [
            $leaveRequest->id,
            trim(($leaveRequest->user->name ?? '').' '.($leaveRequest->user->surname ?? '')),
            $leaveRequest->user->role ?? '-',
            $leaveRequest->start_date?->format('d.m.Y') ?? '-',
            $leaveRequest->end_date?->format('d.m.Y') ?? '-',
            $leaveRequest->reason ?? '-',
            $leaveRequest->status,
            $leaveRequest->approver ? trim($leaveRequest->approver->name.' '.$leaveRequest->approver->surname) : '-',
        ])->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'izin_talepleri_'.now()->format('Ymd_His'),
            'Izin Talepleri',
            $headings,
            $rows,
        );
    }

    // ─── İZİN TALEPLERİ ───────────────────────────────────────────────────────

    /**
     * List staff leave requests for the panel.
     *
     * Requires permission: `staff.view`. Global users see all leave requests; non-global viewers are limited to their own staff unit. Supports status and user filters.
     *
     * @group Staff
     *
     * @queryParam status string Optional leave status filter. Example: pending
     * @queryParam user_id integer Optional staff user filter. Example: 8
     *
     * @response 200 {"leave_requests":{"data":[{"id":5,"status":"pending","user":{"id":8,"name":"Ayse"}}],"current_page":1}}
     */
    public function leaveRequests(Request $request)
    {
        $this->abortUnlessAllowed($request, 'staff.view');
        $query = LeaveRequest::with(['user:id,name,surname,email,role', 'approver:id,name,surname', 'unit:id,code,name,kind']);
        $this->applyLeaveVisibility($request, $query, 'staff.view');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        $actor = $request->user();
        $leaveRequests = $query->latest()->paginate(20)->through(function (LeaveRequest $leave) use ($actor) {
            $leave->setAttribute('can_approve', $leave->status === 'pending' && $this->canReviewLeave($actor, $leave, 'staff.leave.approve'));
            $leave->setAttribute('can_reject', $leave->status === 'pending' && $this->canReviewLeave($actor, $leave, 'staff.leave.reject'));

            return $leave;
        });

        return response()->json(['leave_requests' => $leaveRequests]);
    }

    /**
     * Create a leave request for the current staff user.
     *
     * Requires permission: `staff.leave.request`. Creates a pending leave request for the authenticated user and notifies super admins plus coordinators in the same unit when available.
     *
     * @group Staff
     *
     * @bodyParam start_date date required Leave start date. Must be today or later. Example: 2026-07-10
     * @bodyParam end_date date required Leave end date. Must be after or equal to start_date. Example: 2026-07-12
     * @bodyParam reason string Optional reason. Max 1000 characters. Example: Yillik izin
     *
     * @response 201 {"message":"Izin talebiniz iletildi.","leave_request":{"id":5,"status":"pending"}}
     * @response 422 {"message":"The start date must be a date after or equal to today."}
     */
    public function storeLeaveRequest(Request $request)
    {
        $this->abortUnlessAllowed($request, 'staff.leave.request');

        $validated = $request->validate([
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'nullable|string|max:1000',
            'unit_id' => 'nullable|integer|exists:coordination_units,id',
        ]);

        $membershipQuery = CoordinationUnitMembership::query()
            ->active()
            ->where('user_id', $request->user()->id)
            ->whereHas('unit', fn ($query) => $query->active());

        $membership = ! empty($validated['unit_id'])
            ? (clone $membershipQuery)->where('unit_id', (int) $validated['unit_id'])->first()
            : $membershipQuery->orderByDesc('is_primary')->orderBy('id')->first();

        if (! empty($validated['unit_id']) && ! $membership) {
            abort(422, 'Secilen koordinatörlükte aktif üyeliğiniz bulunmuyor.');
        }

        $positionSnapshot = $membership?->position;
        $reviewerScope = $positionSnapshot === CoordinationUnitMembership::POSITION_COORDINATOR
            ? 'super_admin'
            : ($membership ? 'unit_coordinator' : 'legacy_unit');

        $leave = LeaveRequest::create([
            'user_id' => Auth::id(),
            'unit_id' => $membership?->unit_id,
            'membership_id' => $membership?->id,
            'position_snapshot' => $positionSnapshot,
            'reviewer_scope' => $reviewerScope,
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'reason' => $validated['reason'] ?? '',
            'status' => 'pending',
        ]);

        $leave->loadMissing('user:id,name,surname,email');
        $this->notifyLeaveApprovers($leave);

        return response()->json(['message' => 'İzin talebiniz iletildi.', 'leave_request' => $leave], 201);
    }

    /**
     * Approve a staff leave request.
     *
     * Requires permission: `staff.leave.approve` and unit access for the leave owner. Marks the leave as approved, stores the approver and emails the staff member when an address exists.
     *
     * @group Staff
     *
     * @urlParam id integer required Leave request ID. Example: 5
     *
     * @response 200 {"message":"Izin talebi onaylandi.","leave_request":{"id":5,"status":"approved"}}
     * @response 403 {"message":"Bu birim icin yetkiniz bulunmuyor."}
     */
    public function approveLeave(Request $request, int $id)
    {
        $this->abortUnlessAllowed($request, 'staff.leave.approve');
        $leave = LeaveRequest::with('user.staffProfile')->findOrFail($id);
        abort_unless($leave->status === 'pending', 422, 'Yalniz bekleyen izin talebi onaylanabilir.');
        abort_unless($this->canReviewLeave($request->user(), $leave, 'staff.leave.approve'), 403, 'Bu izin talebini onaylama yetkiniz bulunmuyor.');
        $oldStatus = $leave->status;
        $leave->update(['status' => 'approved', 'approved_by' => Auth::id()]);
        $this->statusHistoryRecorder->record(
            $leave,
            $oldStatus,
            'approved',
            $request->user()->id,
            $leave->unit_id,
            ['action' => 'staff.leave.approve', 'reviewer_scope' => $leave->reviewer_scope]
        );

        $leave->loadMissing('user:id,email,name,surname');
        $this->notificationService->sendEmail(
            array_filter([$leave->user?->email]),
            'Izin talebiniz onaylandi',
            "Sayin {$leave->user?->name}, {$leave->start_date} - {$leave->end_date} tarihli izin talebiniz onaylanmistir.",
            null,
            $request->user()->id
        );

        return response()->json(['message' => 'İzin talebi onaylandı.', 'leave_request' => $leave]);
    }

    /**
     * Reject a staff leave request.
     *
     * Requires permission: `staff.leave.reject` and unit access for the leave owner. Marks the leave as rejected, stores the reviewer and emails the staff member when an address exists.
     *
     * @group Staff
     *
     * @urlParam id integer required Leave request ID. Example: 5
     *
     * @response 200 {"message":"Izin talebi reddedildi.","leave_request":{"id":5,"status":"rejected"}}
     * @response 403 {"message":"Bu birim icin yetkiniz bulunmuyor."}
     */
    public function rejectLeave(Request $request, int $id)
    {
        $this->abortUnlessAllowed($request, 'staff.leave.reject');
        $leave = LeaveRequest::with('user.staffProfile')->findOrFail($id);
        abort_unless($leave->status === 'pending', 422, 'Yalniz bekleyen izin talebi reddedilebilir.');
        abort_unless($this->canReviewLeave($request->user(), $leave, 'staff.leave.reject'), 403, 'Bu izin talebini reddetme yetkiniz bulunmuyor.');
        $oldStatus = $leave->status;
        $leave->update(['status' => 'rejected', 'approved_by' => Auth::id()]);
        $this->statusHistoryRecorder->record(
            $leave,
            $oldStatus,
            'rejected',
            $request->user()->id,
            $leave->unit_id,
            ['action' => 'staff.leave.reject', 'reviewer_scope' => $leave->reviewer_scope]
        );

        $leave->loadMissing('user:id,email,name,surname');
        $this->notificationService->sendEmail(
            array_filter([$leave->user?->email]),
            'Izin talebiniz reddedildi',
            "Sayin {$leave->user?->name}, {$leave->start_date} - {$leave->end_date} tarihli izin talebiniz reddedilmistir.",
            null,
            $request->user()->id
        );

        return response()->json(['message' => 'İzin talebi reddedildi.', 'leave_request' => $leave]);
    }

    /**
     * List the current staff user leave requests.
     *
     * Requires permission: `staff.leave.request`. Returns the authenticated user leave history with approver metadata.
     *
     * @group Staff
     *
     * @response 200 {"leave_requests":[{"id":5,"status":"pending","approver":null}]}
     */
    public function myLeaveRequests(Request $request)
    {
        $this->abortUnlessAllowed($request, 'staff.leave.request');

        $leaves = LeaveRequest::where('user_id', Auth::id())
            ->with(['approver:id,name,surname', 'unit:id,code,name,kind'])
            ->latest()
            ->get();

        return response()->json(['leave_requests' => $leaves]);
    }
}
