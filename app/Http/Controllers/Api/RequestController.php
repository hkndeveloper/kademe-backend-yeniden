<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Http\Resources\RequestResource;
use App\Models\Project;
use App\Models\Request as WorkflowRequest;
use App\Models\User;
use App\Support\AdminExportResponder;
use App\Services\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class RequestController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {
    }

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

    private function canAccessRequestProject(User $user, string $permission, ?int $projectId): bool
    {
        if ($projectId === null) {
            return $this->permissionResolver->hasPermission($user, $permission);
        }

        return $this->permissionResolver->canAccessProject($user, $permission, $projectId);
    }

    private function canManageRequest(User $user, WorkflowRequest $workflowRequest, string $permission): bool
    {
        if (! $this->permissionResolver->hasPermission($user, $permission)) {
            return false;
        }

        if ($this->permissionResolver->hasGlobalScope($user, $permission) || $workflowRequest->target_user_id === $user->id) {
            return true;
        }

        return $this->canAccessRequestProject($user, $permission, $workflowRequest->project_id);
    }

    public function index(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'requests.view');
        $user = $request->user();

        $query = WorkflowRequest::query()
            ->with([
                'requester:id,name,surname,role',
                'targetUser:id,name,surname,role',
                'project:id,name,slug,type',
            ])
            ->orderByDesc('created_at');

        if (! $this->permissionResolver->hasGlobalScope($user, 'requests.view')) {
            $manageableProjectIds = $this->permissionResolver->projectIdsForPermission($user, 'requests.view');

            $query->where(function ($builder) use ($manageableProjectIds, $user) {
                $builder->where('requester_id', $user->id)
                    ->orWhere('target_user_id', $user->id);

                if (! empty($manageableProjectIds)) {
                    $builder->orWhereIn('project_id', $manageableProjectIds);
                }
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type')->toString());
        }

        if ($request->filled('project_id')) {
            $query->where('project_id', (int) $request->input('project_id'));
        }

        $requests = $query->get();

        $projectScopeIds = $this->permissionResolver->projectIdsForPermission($user, 'requests.create');
        $projects = Project::query()
            ->where('status', 'active')
            ->when(! $this->permissionResolver->hasGlobalScope($user, 'requests.create'), fn ($q) => $q->whereIn('id', $projectScopeIds))
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'type'])
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'type' => $project->type,
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

        $query = WorkflowRequest::query()
            ->with([
                'requester:id,name,surname,role',
                'targetUser:id,name,surname,role',
                'project:id,name,slug,type',
            ])
            ->orderByDesc('created_at');

        if (! $this->permissionResolver->hasGlobalScope($user, 'requests.export')) {
            $manageableProjectIds = $this->permissionResolver->projectIdsForPermission($user, 'requests.export');

            $query->where(function ($builder) use ($manageableProjectIds, $user) {
                $builder->where('requester_id', $user->id)
                    ->orWhere('target_user_id', $user->id);

                if (! empty($manageableProjectIds)) {
                    $builder->orWhereIn('project_id', $manageableProjectIds);
                }
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type')->toString());
        }

        if ($request->filled('project_id')) {
            $query->where('project_id', (int) $request->input('project_id'));
        }

        $requests = $query->get();

        $headings = ['ID', 'Tip', 'Hedef Birim', 'Durum', 'Talep Sahibi', 'Hedef Kisi', 'Proje', 'Aciklama', 'Olusturma Tarihi'];
        $rows = $requests->map(fn (WorkflowRequest $workflowRequest) => [
            $workflowRequest->id,
            $workflowRequest->type,
            $workflowRequest->target_unit ?? '-',
            $workflowRequest->status,
            $workflowRequest->requester ? trim($workflowRequest->requester->name . ' ' . $workflowRequest->requester->surname) : '-',
            $workflowRequest->targetUser ? trim($workflowRequest->targetUser->name . ' ' . $workflowRequest->targetUser->surname) : '-',
            $workflowRequest->project?->name ?? '-',
            $workflowRequest->description,
            $workflowRequest->created_at?->format('d.m.Y H:i') ?? '-',
        ])->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'talepler_' . now()->format('Ymd_His'),
            'Talep Kayitlari',
            $headings,
            $rows,
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'requests.create');
        $validated = $request->validate([
            'type' => 'required|in:' . implode(',', self::REQUEST_TYPES),
            'target_unit' => 'nullable|in:' . implode(',', self::TARGET_UNITS),
            'target_user_id' => 'nullable|exists:users,id',
            'description' => 'required|string|min:10|max:3000',
            'project_id' => 'nullable|exists:projects,id',
        ]);

        if (empty($validated['target_unit']) && empty($validated['target_user_id'])) {
            return response()->json([
                'message' => 'Talep icin hedef birim veya hedef kisi secmelisin.',
            ], 422);
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
        ])->load([
            'requester:id,name,surname,role',
            'targetUser:id,name,surname,role',
            'project:id,name,slug,type',
        ]);

        return response()->json([
            'message' => 'Talep basariyla olusturuldu.',
            'request_item' => new RequestResource($workflowRequest),
        ], 201);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'requests.update_status');
        $validated = $request->validate([
            'status' => 'required|in:' . implode(',', self::STATUS_OPTIONS),
        ]);

        $workflowRequest = WorkflowRequest::query()
            ->with([
                'requester:id,name,surname,role',
                'targetUser:id,name,surname,role',
                'project:id,name,slug,type',
            ])
            ->findOrFail($id);

        if (! $this->canManageRequest($request->user(), $workflowRequest, 'requests.update_status')) {
            return response()->json([
                'message' => 'Bu talebin durumunu guncelleme yetkin yok.',
            ], 403);
        }

        $workflowRequest->update([
            'status' => $validated['status'],
        ]);

        return response()->json([
            'message' => 'Talep durumu guncellendi.',
            'request_item' => new RequestResource($workflowRequest->fresh([
                'requester:id,name,surname,role',
                'targetUser:id,name,surname,role',
                'project:id,name,slug,type',
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
            ])
            ->findOrFail($id);

        if (! $this->canManageRequest($request->user(), $workflowRequest, 'requests.upload_response')) {
            return response()->json([
                'message' => 'Bu talebe dosya yukleme yetkin yok.',
            ], 403);
        }

        if ($workflowRequest->response_file_path) {
            Storage::disk('public')->delete($workflowRequest->response_file_path);
        }

        $path = $request->file('response_file')->store('requests/responses', 'public');

        $workflowRequest->update([
            'response_file_path' => $path,
            'status' => 'completed',
        ]);

        return response()->json([
            'message' => 'Dosya basariyla yuklendi ve talep tamamlandi.',
            'request_item' => new RequestResource($workflowRequest->fresh([
                'requester:id,name,surname,role',
                'targetUser:id,name,surname,role',
                'project:id,name,slug,type',
            ])),
        ]);
    }
}
