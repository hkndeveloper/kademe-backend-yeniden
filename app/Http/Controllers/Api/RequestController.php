<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Concerns\ResolvesProjectPeriodContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\RequestResource;
use App\Models\Period;
use App\Models\Project;
use App\Models\Request as WorkflowRequest;
use App\Models\SystemNotification;
use App\Models\User;
use App\Services\PermissionResolver;
use App\Support\AdminExportResponder;
use App\Support\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RequestController extends Controller
{
    use AuthorizesGranularPermissions;
    use ResolvesProjectPeriodContext;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
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

    private function resolveWorkflowRequestPeriod(Request $request, array &$validated, string $permission): ?int
    {
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
        return $this->permissionResolver->canAccessTargetUnit($user, $permission, $workflowRequest->target_unit);
    }

    private function canManageRequest(User $user, WorkflowRequest $workflowRequest, string $permission): bool
    {
        if (! $this->permissionResolver->hasPermission($user, $permission)) {
            return false;
        }

        if ($this->permissionResolver->hasGlobalScope($user, $permission) || $workflowRequest->target_user_id === $user->id) {
            return true;
        }

        if ($this->canAccessRequestUnit($user, $permission, $workflowRequest)) {
            return true;
        }

        return $this->canAccessRequestProject($user, $permission, $workflowRequest->project_id);
    }

    private function requestVisibilityFilter($builder, User $user, string $permission): void
    {
        $manageableProjectIds = $this->permissionResolver->projectIdsForPermission($user, $permission);

        $builder->where('requester_id', $user->id)
            ->orWhere('target_user_id', $user->id);

        if (! empty($manageableProjectIds)) {
            $builder->orWhereIn('project_id', $manageableProjectIds);
        }

        $targetUnits = $this->permissionResolver->targetUnitsForUser($user, self::TARGET_UNITS);
        if (! empty($targetUnits)) {
            $builder->orWhereIn('target_unit', $targetUnits);
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

                return $this->permissionResolver->canAccessTargetUnit($user, 'requests.view', $workflowRequest->target_unit);
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

        $projectScopeIds = collect([
            ...$this->permissionResolver->projectIdsForPermission($user, 'requests.view'),
            ...$this->permissionResolver->projectIdsForPermission($user, 'requests.create'),
        ])->map(fn ($id) => (int) $id)->unique()->values()->all();
        $projects = Project::query()
            ->with(['periods' => fn ($query) => $query->orderByDesc('start_date')])
            ->where('status', 'active')
            ->when(
                ! $this->permissionResolver->hasGlobalScope($user, 'requests.view')
                    && ! $this->permissionResolver->hasGlobalScope($user, 'requests.create'),
                fn ($q) => $q->whereIn('id', $projectScopeIds === [] ? [-1] : $projectScopeIds)
            )
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'type'])
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'type' => $project->type,
                'active_period' => optional($project->periods->firstWhere('status', 'active'))?->only(['id', 'name', 'status', 'start_date', 'end_date']),
                'periods' => $project->periods->map->only(['id', 'name', 'status', 'start_date', 'end_date'])->values(),
            ])
            ->values();

        $targetUserQuery = User::query()
            ->with('staffProfile')
            ->whereIn('role', ['super_admin', 'coordinator', 'staff'])
            ->where('status', 'active')
            ->orderBy('name');

        if (! $this->permissionResolver->hasGlobalScope($user, 'requests.create')) {
            $unit = $user->staffProfile?->unit;
            $targetUserQuery->where(function ($builder) use ($unit) {
                $builder->where('role', 'super_admin');

                if ($unit) {
                    $builder->orWhereHas('staffProfile', fn ($q) => $q->where('unit', $unit));
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
        ]);
    }

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

    public function store(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'requests.create');
        $validated = $request->validate([
            'type' => 'required|in:'.implode(',', self::REQUEST_TYPES),
            'target_unit' => 'nullable|in:'.implode(',', self::TARGET_UNITS),
            'target_user_id' => 'nullable|exists:users,id',
            'description' => 'required|string|min:10|max:3000',
            'project_id' => 'nullable|exists:projects,id',
            'period_id' => 'nullable|exists:periods,id',
        ]);
        $periodId = $this->resolveWorkflowRequestPeriod($request, $validated, 'requests.create');

        if (empty($validated['target_unit']) && empty($validated['target_user_id'])) {
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

        if (! empty($validated['project_id'])) {
            abort_unless(
                $this->canAccessRequestProject($request->user(), 'requests.create', (int) $validated['project_id']),
                403,
                'Bu proje icin talep olusturma yetkiniz bulunmuyor.'
            );
        }

        if (! empty($validated['target_user_id']) && ! $this->permissionResolver->hasGlobalScope($request->user(), 'requests.create')) {
            $targetUser = User::query()->with('staffProfile')->findOrFail((int) $validated['target_user_id']);
            $actorUnit = $request->user()->staffProfile?->unit;
            $sameUnit = $actorUnit && $targetUser->staffProfile?->unit === $actorUnit;

            abort_unless($targetUser->role === 'super_admin' || $sameUnit, 403, 'Bu kisiye talep gonderme yetkiniz bulunmuyor.');
        }

        $workflowRequest = WorkflowRequest::create([
            'requester_id' => $request->user()->id,
            'type' => $validated['type'],
            'target_unit' => $validated['target_unit'] ?? null,
            'target_user_id' => $validated['target_user_id'] ?? null,
            'description' => $validated['description'],
            'status' => 'pending',
            'project_id' => $validated['project_id'] ?? null,
            'period_id' => $periodId,
        ])->load([
            'requester:id,name,surname,role',
            'targetUser:id,name,surname,role',
            'project:id,name,slug,type',
            'period:id,name,status,start_date,end_date',
        ]);

        $this->notifyRequestTargets($workflowRequest);

        return response()->json([
            'message' => 'Talep basariyla olusturuldu.',
            'request_item' => new RequestResource($workflowRequest),
        ], 201);
    }

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

        if (! $this->canManageRequest($request->user(), $workflowRequest, 'requests.update_status')) {
            return response()->json([
                'message' => 'Bu talebin durumunu guncelleme yetkin yok.',
            ], 403);
        }

        $before = [
            'status' => $workflowRequest->status,
        ];
        $workflowRequest->update([
            'status' => $validated['status'],
        ]);
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
            'request_item' => new RequestResource($workflowRequest->fresh([
                'requester:id,name,surname,role',
                'targetUser:id,name,surname,role',
                'project:id,name,slug,type',
                'period:id,name,status,start_date,end_date',
            ])),
        ]);
    }

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

        if (! $this->canManageRequest($request->user(), $workflowRequest, 'requests.upload_response')) {
            return response()->json([
                'message' => 'Bu talebe dosya yukleme yetkin yok.',
            ], 403);
        }

        $oldPath = $workflowRequest->response_file_path;
        $oldStatus = $workflowRequest->status;
        $path = MediaStorage::putFile('requests/responses', $request->file('response_file'));

        $workflowRequest->update([
            'response_file_path' => $path,
            'status' => 'completed',
        ]);

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
            'request_item' => new RequestResource($workflowRequest->fresh([
                'requester:id,name,surname,role',
                'targetUser:id,name,surname,role',
                'project:id,name,slug,type',
                'period:id,name,status,start_date,end_date',
            ])),
        ]);
    }

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
