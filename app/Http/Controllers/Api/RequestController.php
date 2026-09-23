<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Concerns\ResolvesProjectPeriodContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\RequestResource;
use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitMembership;
use App\Models\Period;
use App\Models\Project;
use App\Models\Request as WorkflowRequest;
use App\Models\SystemNotification;
use App\Models\User;
use App\Services\PermissionResolver;
use App\Services\WorkflowStatusHistoryRecorder;
use App\Support\AdminExportResponder;
use App\Support\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @group Requests
 */
class RequestController extends Controller
{
    use AuthorizesGranularPermissions;
    use ResolvesProjectPeriodContext;

    public function __construct(
        private readonly PermissionResolver $permissionResolver,
        private readonly WorkflowStatusHistoryRecorder $statusHistoryRecorder,
    ) {}

    private const REQUEST_TYPES = [
        'vehicle',
        'food',
        'accommodation',
        'ticket',
        'official_doc',
        'media_design',
        'other',
    ];

    private const STATUS_OPTIONS = [
        'pending',
        'in_progress',
        'completed',
        'rejected',
    ];

    private const TARGET_UNITS = [
        'media',
        'operations',
        'program',
        'finance',
        'official_affairs',
        'general',
    ];

    private const TARGET_UNIT_ALIASES = [
        'media' => ['media', 'medya', 'icerik', 'content', 'tasarim', 'tasarım'],
        'operations' => ['operations', 'operasyon', 'lojistik', 'logistics'],
        'program' => ['program', 'proje', 'project', 'egitim', 'eğitim'],
        'finance' => ['finance', 'finans', 'mali', 'muhasebe'],
        'official_affairs' => ['official_affairs', 'official affairs', 'resmi', 'evrak', 'idari'],
        'general' => ['general', 'genel'],
    ];

    private function canAccessRequestProject(User $user, string $permission, ?int $projectId): bool
    {
        if ($projectId === null) {
            return $this->permissionResolver->hasGlobalScope($user, $permission);
        }

        return $this->permissionResolver->canAccessProject($user, $permission, $projectId);
    }

    private function resolveWorkflowRequestPeriod(
        Request $request,
        array &$validated,
        string $permission,
        bool $forWrite = false,
    ): ?int {
        if (empty($validated['period_id'])) {
            return null;
        }

        $period = Period::query()->select(['id', 'project_id', 'status'])->findOrFail((int) $validated['period_id']);
        if (! empty($validated['project_id']) && (int) $validated['project_id'] !== (int) $period->project_id) {
            throw ValidationException::withMessages([
                'period_id' => ['Secilen donem bu projeye ait degil.'],
            ]);
        }

        $validated['project_id'] = (int) $period->project_id;
        $this->resolveProjectPeriodContext($request, $permission, (int) $period->project_id, (int) $period->id);
        if ($forWrite) {
            $this->assertPeriodWritable($request, (int) $period->id);
        }

        return (int) $period->id;
    }

    private function normalizedUnitText(?string $unit): ?string
    {
        if ($unit === null || trim($unit) === '') {
            return null;
        }

        $normalized = mb_strtolower(trim($unit));
        $normalized = str_replace(['ı', 'ğ', 'ü', 'ş', 'ö', 'ç'], ['i', 'g', 'u', 's', 'o', 'c'], $normalized);
        $normalized = preg_replace('/[^a-z0-9_ ]+/', ' ', $normalized) ?: $normalized;

        return preg_replace('/\s+/', ' ', trim($normalized)) ?: null;
    }

    private function matchesTargetUnit(?string $staffUnit, ?string $targetUnit): bool
    {
        $staffUnit = $this->normalizedUnitText($staffUnit);
        $targetUnit = $this->normalizedUnitText($targetUnit);

        if (! $staffUnit || ! $targetUnit) {
            return false;
        }

        $aliases = self::TARGET_UNIT_ALIASES[$targetUnit] ?? [$targetUnit];

        foreach ($aliases as $alias) {
            $normalizedAlias = $this->normalizedUnitText($alias);
            if ($normalizedAlias && str_contains($staffUnit, $normalizedAlias)) {
                return true;
            }
        }

        return false;
    }

    private function canAccessRequestUnit(User $user, string $permission, WorkflowRequest $workflowRequest): bool
    {
        if ($workflowRequest->target_unit_id !== null) {
            return $this->permissionResolver->canAccessCoordinationUnit(
                $user,
                $permission,
                (int) $workflowRequest->target_unit_id
            );
        }

        // Metin tabanli birim eslesmesi yalniz FK'si olmayan legacy kayitlarda kullanilir.
        return $this->permissionResolver->canAccessTargetUnit($user, $permission, $workflowRequest->target_unit);
    }

    private function canManageRequest(User $user, WorkflowRequest $workflowRequest, string $permission): bool
    {
        if (! $this->permissionResolver->hasPermission($user, $permission)) {
            return false;
        }

        if ($this->permissionResolver->hasGlobalScope($user, $permission)) {
            return true;
        }

        if ((int) $workflowRequest->target_user_id === (int) $user->id) {
            return $workflowRequest->target_unit_id === null
                || $this->canAccessRequestUnit($user, $permission, $workflowRequest);
        }

        if ($this->canAccessRequestUnit($user, $permission, $workflowRequest)) {
            return true;
        }

        return $this->canAccessRequestProject($user, $permission, $workflowRequest->project_id);
    }

    private function canRespondToRequest(User $user, WorkflowRequest $workflowRequest, string $permission): bool
    {
        if (! $this->permissionResolver->hasPermission($user, $permission)) {
            return false;
        }

        if ($this->permissionResolver->hasGlobalScope($user, $permission)) {
            return true;
        }

        if ($workflowRequest->target_user_id !== null) {
            if ((int) $workflowRequest->target_user_id !== (int) $user->id) {
                return false;
            }

            return $workflowRequest->target_unit_id === null
                || $this->canAccessRequestUnit($user, $permission, $workflowRequest);
        }

        // Hedef kisi icermeyen eski kayitlar, gecis tamamlanana kadar eski record policy ile yonetilebilir.
        return $this->canManageRequest($user, $workflowRequest, $permission);
    }

    private function decorateRequestCapabilities(WorkflowRequest $workflowRequest, User $user): WorkflowRequest
    {
        $workflowRequest->setAttribute(
            'can_update_status',
            $this->canRespondToRequest($user, $workflowRequest, 'requests.update_status')
        );
        $workflowRequest->setAttribute(
            'can_upload_response',
            $this->canRespondToRequest($user, $workflowRequest, 'requests.upload_response')
        );

        return $workflowRequest;
    }

    private function targetCoordinationUnits(): array
    {
        return CoordinationUnit::query()
            ->active()
            ->whereHas('memberships', function ($query) {
                $query->active()
                    ->whereHas('user', fn ($userQuery) => $userQuery
                        ->where('status', 'active')
                        ->whereIn('role', ['coordinator', 'staff']));
            })
            ->with([
                'memberships' => fn ($query) => $query
                    ->active()
                    ->whereHas('user', fn ($userQuery) => $userQuery
                        ->where('status', 'active')
                        ->whereIn('role', ['coordinator', 'staff']))
                    ->with('user:id,name,surname,role,status')
                    ->orderByRaw("CASE WHEN position = 'coordinator' THEN 0 ELSE 1 END")
                    ->orderBy('id'),
                'project:id,name',
            ])
            ->orderBy('kind')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'kind', 'project_id'])
            ->map(fn (CoordinationUnit $unit) => [
                'id' => (int) $unit->id,
                'code' => $unit->code,
                'name' => $unit->name,
                'kind' => $unit->kind,
                'project_id' => $unit->project_id === null ? null : (int) $unit->project_id,
                'project_name' => $unit->project?->name,
                'members' => $unit->memberships
                    ->map(fn (CoordinationUnitMembership $membership) => [
                        'membership_id' => (int) $membership->id,
                        'user_id' => (int) $membership->user_id,
                        'name' => $membership->user?->name,
                        'surname' => $membership->user?->surname,
                        'role' => $membership->user?->role,
                        'position' => $membership->position,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    private function requestVisibilityFilter($builder, User $user, string $permission): void
    {
        $manageableProjectIds = $this->permissionResolver->projectIdsForPermission($user, $permission);

        $unitIds = $this->permissionResolver->coordinationUnitIdsForPermission($user, $permission);

        $builder->where('requester_id', $user->id)
            ->orWhere(function ($targetQuery) use ($user, $unitIds) {
                $targetQuery->where('target_user_id', $user->id)
                    ->where(function ($unitQuery) use ($unitIds) {
                        $unitQuery->whereNull('target_unit_id');
                        if ($unitIds !== []) {
                            $unitQuery->orWhereIn('target_unit_id', $unitIds);
                        }
                    });
            });

        if (! empty($manageableProjectIds)) {
            $builder->orWhereIn('project_id', $manageableProjectIds);
        }

        $targetUnits = $this->permissionResolver->targetUnitsForUser($user, self::TARGET_UNITS, $permission);
        if (! empty($targetUnits)) {
            $builder->orWhere(function ($legacyQuery) use ($targetUnits) {
                $legacyQuery->whereNull('target_unit_id')->whereIn('target_unit', $targetUnits);
            });
        }

        if ($unitIds !== []) {
            $builder->orWhereIn('target_unit_id', $unitIds);
        }
    }

    private function usersForRequestNotification(WorkflowRequest $workflowRequest): array
    {
        $users = User::query()
            ->with('staffProfile')
            ->whereIn('role', ['super_admin', 'coordinator', 'staff'])
            ->where('status', 'active')
            ->get(['id', 'name', 'surname', 'role'])
            ->filter(function (User $user) use ($workflowRequest) {
                if ($workflowRequest->target_user_id === $user->id) {
                    return true;
                }

                if (! $this->permissionResolver->hasPermission($user, 'requests.view')) {
                    return false;
                }

                if ($this->permissionResolver->hasGlobalScope($user, 'requests.view')) {
                    return true;
                }

                return $this->canAccessRequestUnit($user, 'requests.view', $workflowRequest);
            })
            ->pluck('id')
            ->push($workflowRequest->target_user_id)
            ->filter(fn ($id) => $id !== null && (int) $id !== (int) $workflowRequest->requester_id)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        return $users->all();
    }

    private function notifyRequestTargets(WorkflowRequest $workflowRequest): void
    {
        $workflowRequest->loadMissing('requester:id,name,surname', 'project:id,name');
        $requesterName = trim(($workflowRequest->requester?->name ?? '').' '.($workflowRequest->requester?->surname ?? ''));
        $projectName = $workflowRequest->project?->name;
        $body = trim(implode("\n", array_filter([
            $requesterName ? "Talep sahibi: {$requesterName}" : null,
            $projectName ? "Proje: {$projectName}" : null,
            "Tip: {$workflowRequest->type}",
            $workflowRequest->target_unit ? "Hedef birim: {$workflowRequest->target_unit}" : null,
            mb_substr($workflowRequest->description, 0, 240),
        ])));

        foreach ($this->usersForRequestNotification($workflowRequest) as $userId) {
            SystemNotification::notify(
                $userId,
                'request.created',
                'Yeni talep oluşturuldu',
                $body,
                '/panel/requests',
                WorkflowRequest::class,
                $workflowRequest->id
            );
        }
    }

    private function streamResponseFile(WorkflowRequest $workflowRequest): JsonResponse|StreamedResponse
    {
        if (! $workflowRequest->response_file_path) {
            return response()->json(['message' => 'Yanit dosyasi bulunamadi.'], 404);
        }

        if ($this->isUrl($workflowRequest->response_file_path) || (MediaStorage::directDownloadsEnabled() && MediaStorage::publicUrlConfigured())) {
            return response()->json([
                'download_url' => MediaStorage::url($workflowRequest->response_file_path),
            ]);
        }

        if (! MediaStorage::exists($workflowRequest->response_file_path)) {
            return response()->json(['message' => 'Yanit dosyasi storage uzerinde bulunamadi.'], 404);
        }

        $extension = pathinfo($workflowRequest->response_file_path, PATHINFO_EXTENSION);
        $filename = 'talep_yanit_'.$workflowRequest->id;

        return MediaStorage::disk()->download(
            $workflowRequest->response_file_path,
            $filename.($extension ? ".{$extension}" : '')
        );
    }

    private function isUrl(string $path): bool
    {
        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://');
    }

    /**
     * List workflow requests.
     *
     * Participant/mobile routes use the route-level `participant.support.manage` gate; panel/admin routes require `requests.view`. The controller then applies the action+scope matrix: global scope sees all requests, otherwise visibility is limited to requester, target user, manageable project ids, or allowed target units.
     *
     * The response also returns form metadata for the UI: active projects/periods inside view/create scope, eligible target users, request types, status options, and target units.
     *
     * @group Requests
     *
     * @authenticated
     *
     * @queryParam status string Optional status filter: pending, in_progress, completed, rejected. Example: pending
     * @queryParam type string Optional request type filter. Example: official_doc
     * @queryParam project_id integer Optional project filter; scoped by `requests.view`. Example: 1
     * @queryParam period_id integer Optional period filter; resolved with project-period scope. Example: 1
     *
     * @response 200 {"requests":[{"id":1,"type":"official_doc","status":"pending","description":"Belge talebi"}],"projects":[{"id":1,"name":"KADEME","periods":[]}],"target_users":[{"id":2,"name":"Ayse","role":"staff"}],"request_types":["vehicle","food","official_doc"],"status_options":["pending","in_progress","completed","rejected"],"target_units":["media","operations","official_affairs"]}
     * @response 403 {"message":"This action is unauthorized."}
     */
    public function index(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'requests.view');
        $user = $request->user();
        $validated = $request->validate([
            'status' => 'nullable|string|in:'.implode(',', self::STATUS_OPTIONS),
            'type' => 'nullable|string|in:'.implode(',', self::REQUEST_TYPES),
            'project_id' => 'nullable|integer|exists:projects,id',
            'period_id' => 'nullable|integer|exists:periods,id',
        ]);
        $periodId = $this->resolveWorkflowRequestPeriod($request, $validated, 'requests.view');

        $query = WorkflowRequest::query()
            ->with([
                'requester:id,name,surname,role',
                'targetUser:id,name,surname,role',
                'project:id,name,slug,type',
                'period:id,name,status,start_date,end_date',
                'targetUnit:id,code,name,kind,project_id',
            ])
            ->orderByDesc('created_at');

        if (! $this->permissionResolver->hasGlobalScope($user, 'requests.view')) {
            $query->where(function ($builder) use ($user) {
                $this->requestVisibilityFilter($builder, $user, 'requests.view');
            });
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        if (! empty($validated['project_id'])) {
            $query->where('project_id', (int) $validated['project_id']);
        }

        if ($periodId !== null) {
            $query->where('period_id', $periodId);
        }

        $requests = $query->get();
        $requests->each(fn (WorkflowRequest $workflowRequest) => $this->decorateRequestCapabilities($workflowRequest, $user));

        $projectScopeIds = collect([
            ...$this->permissionResolver->projectIdsForPermission($user, 'requests.view'),
            ...$this->permissionResolver->projectIdsForPermission($user, 'requests.create'),
        ])->map(fn ($id) => (int) $id)->unique()->values()->all();
        $projects = Project::query()
            ->with([
                'periods' => fn ($query) => $query->orderByDesc('start_date'),
                'currentPeriod',
            ])
            ->where('status', 'active')
            ->when(
                ! $this->permissionResolver->hasGlobalScope($user, 'requests.view')
                    && ! $this->permissionResolver->hasGlobalScope($user, 'requests.create'),
                fn ($q) => $q->whereIn('id', $projectScopeIds === [] ? [-1] : $projectScopeIds)
            )
            ->orderBy('name')
            ->get(['id', 'current_period_id', 'name', 'slug', 'type'])
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'type' => $project->type,
                'active_period' => optional($project->currentPeriodOrLegacy())?->only(['id', 'name', 'status', 'start_date', 'end_date']),
                'periods' => $project->periods->map->only(['id', 'name', 'status', 'start_date', 'end_date'])->values(),
            ])
            ->values();

        $targetUserQuery = User::query()
            ->with('staffProfile')
            ->whereIn('role', ['super_admin', 'coordinator', 'staff'])
            ->where('status', 'active')
            ->orderBy('name');

        if (! $this->permissionResolver->hasGlobalScope($user, 'requests.create')) {
            $unitIds = $this->permissionResolver->coordinationUnitIdsForPermission($user, 'requests.create');
            $targetUserQuery->where(function ($builder) use ($unitIds) {
                $builder->where('role', 'super_admin');

                if ($unitIds !== []) {
                    $builder->orWhereHas('coordinationUnitMemberships', fn ($query) => $query
                        ->active()
                        ->whereIn('unit_id', $unitIds)
                        ->whereHas('unit', fn ($unitQuery) => $unitQuery->where('status', 'active')));
                }
            });
        }

        $targetUsers = $targetUserQuery
            ->get(['id', 'name', 'surname', 'role'])
            ->map(fn (User $targetUser) => [
                'id' => $targetUser->id,
                'name' => $targetUser->name,
                'surname' => $targetUser->surname,
                'role' => $targetUser->role,
            ])
            ->values();

        return response()->json([
            'requests' => RequestResource::collection($requests),
            'projects' => $projects,
            'target_users' => $targetUsers,
            'request_types' => self::REQUEST_TYPES,
            'status_options' => self::STATUS_OPTIONS,
            'target_units' => self::TARGET_UNITS,
            'coordination_units' => $this->targetCoordinationUnits(),
        ]);
    }

    /**
     * Export workflow requests.
     *
     * Participant/mobile routes use the route-level `participant.support.manage` gate; panel/admin routes require `requests.export`. Non-global users are filtered to requester, target user, manageable project ids, or allowed target units. The shared export responder accepts `csv`, `xlsx`, or `pdf` when enabled.
     *
     * @group Requests
     *
     * @authenticated
     *
     * @queryParam status string Optional status filter: pending, in_progress, completed, rejected. Example: completed
     * @queryParam type string Optional request type filter. Example: vehicle
     * @queryParam project_id integer Optional project filter; scoped by `requests.export`. Example: 1
     * @queryParam period_id integer Optional period filter; resolved with project-period scope. Example: 1
     * @queryParam format string Optional export format. Example: csv
     *
     * @response 200 {"download":"Export file stream"}
     * @response 403 {"message":"This action is unauthorized."}
     */
    public function export(Request $request)
    {
        $this->abortUnlessAllowed($request, 'requests.export');
        $user = $request->user();
        $validated = $request->validate([
            'status' => 'nullable|string|in:'.implode(',', self::STATUS_OPTIONS),
            'type' => 'nullable|string|in:'.implode(',', self::REQUEST_TYPES),
            'project_id' => 'nullable|integer|exists:projects,id',
            'period_id' => 'nullable|integer|exists:periods,id',
        ]);
        $periodId = $this->resolveWorkflowRequestPeriod($request, $validated, 'requests.export');

        $query = WorkflowRequest::query()
            ->with([
                'requester:id,name,surname,role',
                'targetUser:id,name,surname,role',
                'project:id,name,slug,type',
                'period:id,name,status',
            ])
            ->orderByDesc('created_at');

        if (! $this->permissionResolver->hasGlobalScope($user, 'requests.export')) {
            $query->where(function ($builder) use ($user) {
                $this->requestVisibilityFilter($builder, $user, 'requests.export');
            });
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        if (! empty($validated['project_id'])) {
            $query->where('project_id', (int) $validated['project_id']);
        }

        if ($periodId !== null) {
            $query->where('period_id', $periodId);
        }

        $requests = $query->get();

        $headings = ['ID', 'Tip', 'Hedef Birim', 'Durum', 'Talep Sahibi', 'Hedef Kisi', 'Proje', 'Donem', 'Aciklama', 'Olusturma Tarihi'];
        $rows = $requests->map(fn (WorkflowRequest $workflowRequest) => [
            $workflowRequest->id,
            $workflowRequest->type,
            $workflowRequest->target_unit ?? '-',
            $workflowRequest->status,
            $workflowRequest->requester ? trim($workflowRequest->requester->name.' '.$workflowRequest->requester->surname) : '-',
            $workflowRequest->targetUser ? trim($workflowRequest->targetUser->name.' '.$workflowRequest->targetUser->surname) : '-',
            $workflowRequest->project?->name ?? '-',
            $workflowRequest->period?->name ?? '-',
            $workflowRequest->description,
            $workflowRequest->created_at?->format('d.m.Y H:i') ?? '-',
        ])->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'talepler_'.now()->format('Ymd_His'),
            'Talep Kayitlari',
            $headings,
            $rows,
        );
    }

    /**
     * Create a workflow request.
     *
     * Participant/mobile routes use the route-level `participant.support.manage` gate; panel/admin routes require `requests.create`. Either `target_unit` or `target_user_id` is required. Project and period values are checked through the action+scope matrix. `vehicle`, `accommodation`, and `ticket` require a project; `official_doc` is routed to `official_affairs`.
     *
     * When the actor does not have global create scope, direct `target_user_id` routing is limited to super admins or staff in the same unit. Creating a request notifies eligible target users who can view that unit/request.
     *
     * @group Requests
     *
     * @authenticated
     *
     * @bodyParam type string required One of vehicle, food, accommodation, ticket, official_doc, media_design, other. Example: official_doc
     * @bodyParam target_unit string Optional target unit: media, operations, program, finance, official_affairs, general. Example: official_affairs
     * @bodyParam target_user_id integer Optional active staff/admin target user id. Example: 2
     * @bodyParam description string required Request description, min 10 and max 3000 characters. Example: Resmi belge talep ediyorum.
     * @bodyParam project_id integer Optional project id. Required for vehicle, accommodation, and ticket. Example: 1
     * @bodyParam period_id integer Optional period id. Example: 1
     *
     * @response 201 {"message":"Talep basariyla olusturuldu.","request_item":{"id":1,"type":"official_doc","status":"pending"}}
     * @response 403 {"message":"Bu proje icin talep olusturma yetkiniz bulunmuyor."}
     * @response 422 {"message":"Talep icin hedef birim veya hedef kisi secmelisin."}
     */
    public function store(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'requests.create');
        $validated = $request->validate([
            'type' => 'required|in:'.implode(',', self::REQUEST_TYPES),
            'target_unit' => 'nullable|in:'.implode(',', self::TARGET_UNITS),
            'target_user_id' => 'nullable|exists:users,id',
            'target_unit_id' => 'nullable|integer|exists:coordination_units,id',
            'description' => 'required|string|min:10|max:3000',
            'project_id' => 'nullable|exists:projects,id',
            'period_id' => 'nullable|exists:periods,id',
        ]);
        $periodId = $this->resolveWorkflowRequestPeriod($request, $validated, 'requests.create', true);

        if (empty($validated['target_unit']) && empty($validated['target_user_id']) && empty($validated['target_unit_id'])) {
            return response()->json([
                'message' => 'Talep icin hedef birim veya hedef kisi secmelisin.',
            ], 422);
        }

        // Tip bazlı zorunlu alan kontrolleri
        $typeRequiresProject = in_array($validated['type'], ['vehicle', 'accommodation', 'ticket'], true);
        if ($typeRequiresProject && empty($validated['project_id'])) {
            return response()->json([
                'message' => 'Bu talep tipi icin proje secimi zorunludur.',
                'errors' => ['project_id' => ['Bu talep tipi icin proje secimi zorunludur.']],
            ], 422);
        }

        if ($validated['type'] === 'official_doc' && ($validated['target_unit'] ?? null) !== 'official_affairs') {
            $validated['target_unit'] = 'official_affairs';
        }

        $targetMembership = null;
        $targetCoordinationUnit = null;
        if (! empty($validated['target_unit_id'])) {
            $targetCoordinationUnit = CoordinationUnit::query()
                ->active()
                ->findOrFail((int) $validated['target_unit_id']);

            if (empty($validated['target_user_id'])) {
                throw ValidationException::withMessages([
                    'target_user_id' => ['Hedef koordinatörlük seçildiğinde o birimden hedef kişi seçilmelidir.'],
                ]);
            }

            $targetMembership = CoordinationUnitMembership::query()
                ->active()
                ->where('unit_id', $targetCoordinationUnit->id)
                ->where('user_id', (int) $validated['target_user_id'])
                ->whereIn('position', [CoordinationUnitMembership::POSITION_COORDINATOR, CoordinationUnitMembership::POSITION_STAFF])
                ->whereHas('user', fn ($query) => $query
                    ->where('status', 'active')
                    ->whereIn('role', ['coordinator', 'staff']))
                ->first();

            if (! $targetMembership) {
                throw ValidationException::withMessages([
                    'target_user_id' => ['Seçilen kişi hedef koordinatörlüğün aktif koordinatör veya personeli değildir.'],
                ]);
            }

            if ((int) $validated['target_user_id'] === (int) $request->user()->id) {
                throw ValidationException::withMessages([
                    'target_user_id' => ['Kendinize talep gönderemezsiniz.'],
                ]);
            }

            // Yeni kayitlarda legacy alan okunabilir bir yedek olarak korunur.
            $validated['target_unit'] = $targetCoordinationUnit->code;
        }

        if (! empty($validated['project_id'])) {
            abort_unless(
                $this->canAccessRequestProject($request->user(), 'requests.create', (int) $validated['project_id']),
                403,
                'Bu proje icin talep olusturma yetkiniz bulunmuyor.'
            );
        }

        if (! empty($validated['target_user_id']) && empty($validated['target_unit_id']) && ! $this->permissionResolver->hasGlobalScope($request->user(), 'requests.create')) {
            $targetUser = User::query()->with('staffProfile')->findOrFail((int) $validated['target_user_id']);
            if ($targetUser->role !== 'super_admin' && $this->permissionResolver->coordinationUnitsAreAuthoritative($request->user())) {
                throw ValidationException::withMessages([
                    'target_unit_id' => ['Hedef kişi için koordinatörlük seçilmelidir.'],
                ]);
            }

            $actorUnit = $request->user()->staffProfile?->unit;
            $sameLegacyUnit = $actorUnit && $targetUser->staffProfile?->unit === $actorUnit;
            abort_unless(
                $targetUser->role === 'super_admin' || $sameLegacyUnit,
                403,
                'Bu kisiye talep gonderme yetkiniz bulunmuyor.'
            );
        }

        $workflowRequest = WorkflowRequest::create([
            'requester_id' => $request->user()->id,
            'type' => $validated['type'],
            'target_unit' => $validated['target_unit'] ?? null,
            'target_unit_id' => $targetCoordinationUnit?->id,
            'target_user_id' => $validated['target_user_id'] ?? null,
            'target_membership_id' => $targetMembership?->id,
            'description' => $validated['description'],
            'status' => 'pending',
            'project_id' => $validated['project_id'] ?? null,
            'period_id' => $periodId,
        ])->load([
            'requester:id,name,surname,role',
            'targetUser:id,name,surname,role',
            'project:id,name,slug,type',
            'period:id,name,status,start_date,end_date',
            'targetUnit:id,code,name,kind,project_id',
        ]);

        $this->decorateRequestCapabilities($workflowRequest, $request->user());

        $this->notifyRequestTargets($workflowRequest);

        return response()->json([
            'message' => 'Talep basariyla olusturuldu.',
            'request_item' => new RequestResource($workflowRequest),
        ], 201);
    }

    /**
     * Update workflow request status.
     *
     * Participant/mobile routes use the route-level `participant.support.manage` gate; panel/admin routes require `requests.update_status`. The target request must be manageable through global scope, target user ownership, target unit scope, or project scope. Status changes are audit logged.
     *
     * @group Requests
     *
     * @authenticated
     *
     * @urlParam id integer required Request id. Example: 1
     *
     * @bodyParam status string required One of pending, in_progress, completed, rejected. Example: completed
     *
     * @response 200 {"message":"Talep durumu guncellendi.","request_item":{"id":1,"status":"completed"}}
     * @response 403 {"message":"Bu talebin durumunu guncelleme yetkin yok."}
     * @response 422 {"message":"The selected status is invalid."}
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'requests.update_status');
        $validated = $request->validate([
            'status' => 'required|in:'.implode(',', self::STATUS_OPTIONS),
        ]);

        $workflowRequest = WorkflowRequest::query()
            ->with([
                'requester:id,name,surname,role',
                'targetUser:id,name,surname,role',
                'project:id,name,slug,type',
                'period:id,name,status,start_date,end_date',
            ])
            ->findOrFail($id);

        if (! $this->canRespondToRequest($request->user(), $workflowRequest, 'requests.update_status')) {
            return response()->json([
                'message' => 'Bu talebin durumunu guncelleme yetkin yok.',
            ], 403);
        }
        $this->assertPeriodResolvable($request, $workflowRequest->period_id);

        $before = [
            'status' => $workflowRequest->status,
        ];
        $workflowRequest->update([
            'status' => $validated['status'],
        ]);
        $this->statusHistoryRecorder->record(
            $workflowRequest,
            $before['status'],
            $validated['status'],
            $request->user()->id,
            $workflowRequest->target_unit_id,
            ['action' => 'requests.update_status']
        );
        $request->attributes->set('audit.subject', $workflowRequest);
        $request->attributes->set('audit.event', 'requests.status.updated');
        $request->attributes->set('audit.description', 'requests.status.updated');
        $request->attributes->set('audit.attribute_changes', [
            'before' => $before,
            'after' => [
                'status' => $validated['status'],
            ],
        ]);

        return response()->json([
            'message' => 'Talep durumu guncellendi.',
            'request_item' => new RequestResource($this->decorateRequestCapabilities($workflowRequest->fresh([
                'requester:id,name,surname,role',
                'targetUser:id,name,surname,role',
                'project:id,name,slug,type',
                'period:id,name,status,start_date,end_date',
                'targetUnit:id,code,name,kind,project_id',
            ]), $request->user())),
        ]);
    }

    /**
     * Upload a workflow request response file.
     *
     * Participant/mobile routes use the route-level `participant.support.manage` gate; panel/admin routes require `requests.upload_response`. The target request must be manageable through global scope, target user ownership, target unit scope, or project scope. Send as `multipart/form-data`; uploading a new file marks the request as completed and removes the previous response file when replaced.
     *
     * @group Requests
     *
     * @authenticated
     *
     * @urlParam id integer required Request id. Example: 1
     *
     * @bodyParam response_file file required Response attachment, max 10MB.
     *
     * @response 200 {"message":"Dosya basariyla yuklendi ve talep tamamlandi.","request_item":{"id":1,"status":"completed"}}
     * @response 403 {"message":"Bu talebe dosya yukleme yetkin yok."}
     * @response 422 {"message":"The response file field is required.","errors":{"response_file":["The response file field is required."]}}
     */
    public function uploadResponseFile(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'requests.upload_response');
        $request->validate([
            'response_file' => 'required|file|max:10240', // Maks 10MB
        ]);

        $workflowRequest = WorkflowRequest::query()
            ->with([
                'requester:id,name,surname,role',
                'targetUser:id,name,surname,role',
                'project:id,name,slug,type',
                'period:id,name,status,start_date,end_date',
            ])
            ->findOrFail($id);

        if (! $this->canRespondToRequest($request->user(), $workflowRequest, 'requests.upload_response')) {
            return response()->json([
                'message' => 'Bu talebe dosya yukleme yetkin yok.',
            ], 403);
        }
        $this->assertPeriodResolvable($request, $workflowRequest->period_id);

        $oldPath = $workflowRequest->response_file_path;
        $oldStatus = $workflowRequest->status;
        $path = MediaStorage::putFile('requests/responses', $request->file('response_file'));

        $workflowRequest->update([
            'response_file_path' => $path,
            'status' => 'completed',
        ]);
        $this->statusHistoryRecorder->record(
            $workflowRequest,
            $oldStatus,
            'completed',
            $request->user()->id,
            $workflowRequest->target_unit_id,
            ['action' => 'requests.upload_response']
        );

        if ($oldPath && $oldPath !== $path) {
            MediaStorage::delete($oldPath);
        }
        $request->attributes->set('audit.subject', $workflowRequest);
        $request->attributes->set('audit.event', 'requests.response_file.uploaded');
        $request->attributes->set('audit.description', 'requests.response_file.uploaded');
        $request->attributes->set('audit.attribute_changes', [
            'before' => [
                'status' => $oldStatus,
                'response_file_path' => $oldPath,
            ],
            'after' => [
                'status' => 'completed',
                'response_file_path' => $path,
            ],
        ]);

        return response()->json([
            'message' => 'Dosya basariyla yuklendi ve talep tamamlandi.',
            'request_item' => new RequestResource($this->decorateRequestCapabilities($workflowRequest->fresh([
                'requester:id,name,surname,role',
                'targetUser:id,name,surname,role',
                'project:id,name,slug,type',
                'period:id,name,status,start_date,end_date',
                'targetUnit:id,code,name,kind,project_id',
            ]), $request->user())),
        ]);
    }

    /**
     * Download a workflow request response file.
     *
     * The requester can download their response file. Panel users can download when they can manage the request through `requests.view`, `requests.upload_response`, or `requests.update_status`. Returns a storage direct URL when configured; otherwise streams the binary file. Downloads are audit logged.
     *
     * @group Requests
     *
     * @authenticated
     *
     * @urlParam id integer required Request id. Example: 1
     *
     * @response 200 {"download_url":"https://storage.example.com/requests/responses/file.pdf"}
     * @response 200 {"download":"Binary response file stream"}
     * @response 403 {"message":"Bu talep dosyasini indirme yetkiniz yok."}
     * @response 404 {"message":"Yanit dosyasi bulunamadi."}
     */
    public function downloadResponseFile(Request $request, int $id): JsonResponse|StreamedResponse
    {
        $workflowRequest = WorkflowRequest::query()->findOrFail($id);

        $canView = $workflowRequest->requester_id === $request->user()->id
            || $this->canManageRequest($request->user(), $workflowRequest, 'requests.view')
            || $this->canManageRequest($request->user(), $workflowRequest, 'requests.upload_response')
            || $this->canManageRequest($request->user(), $workflowRequest, 'requests.update_status');

        abort_unless($canView, 403, 'Bu talep dosyasini indirme yetkiniz yok.');
        $request->attributes->set('audit.subject', $workflowRequest);
        $request->attributes->set('audit.event', 'requests.response_file.downloaded');
        $request->attributes->set('audit.description', 'requests.response_file.downloaded');
        $request->attributes->set('audit.properties', [
            'operation' => 'request_response_file_download',
            'request_id' => $workflowRequest->id,
            'response_file_path' => $workflowRequest->response_file_path,
            'request_status' => $workflowRequest->status,
            'project_id' => $workflowRequest->project_id,
        ]);

        return $this->streamResponseFile($workflowRequest);
    }
}
