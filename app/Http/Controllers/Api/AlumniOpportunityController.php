<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\AlumniOpportunity;
use App\Models\Participant;
use App\Services\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlumniOpportunityController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {}

    private function assertProjectOpportunityScope(Request $request, ?int $projectId, string $permission): void
    {
        if ($projectId === null) {
            return;
        }

        abort_unless(
            $this->permissionResolver->canAccessProject($request->user(), $permission, $projectId),
            403,
            'Bu proje kapsaminda islem yapamazsiniz.'
        );
    }

    private function abortUnlessOpportunityAccessible(Request $request, AlumniOpportunity $opportunity, string $permission): void
    {
        $this->abortUnlessAllowed($request, $permission);
        $user = $request->user();
        if ($this->permissionResolver->hasGlobalScope($user, $permission)) {
            return;
        }
        if ($opportunity->project_id !== null) {
            abort_unless(
                $this->permissionResolver->canAccessProject($user, $permission, (int) $opportunity->project_id),
                403,
                'Bu firsat kaydi icin yetkiniz bulunmuyor.'
            );

            return;
        }

        abort_unless(
            (int) $opportunity->created_by === (int) $user->id,
            403,
            'Bu firsat kaydi icin yetkiniz bulunmuyor.'
        );
    }

    private function scopeManageableOpportunities(Request $request, $query, string $permission)
    {
        $user = $request->user();

        if ($this->permissionResolver->hasGlobalScope($user, $permission)) {
            return $query;
        }

        $manageableProjectIds = $this->permissionResolver->projectIdsForPermission($user, $permission);

        if ($manageableProjectIds === []) {
            return $query->where('created_by', $user->id);
        }

        return $query->where(function ($builder) use ($user, $manageableProjectIds) {
            $builder
                ->whereIn('project_id', $manageableProjectIds)
                ->orWhere('created_by', $user->id);
        });
    }

    public function recipientIndex(Request $request): JsonResponse
    {
        $user = $request->user();
        $participantProjectIds = Participant::query()
            ->where('user_id', $user->id)
            ->pluck('project_id')
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $role = (string) ($user->role ?? '');

        $query = AlumniOpportunity::query()
            ->with(['project:id,name'])
            ->where(function ($q) use ($participantProjectIds) {
                $q->whereNull('project_id')->orWhereIn('project_id', $participantProjectIds);
            })
            ->where(function ($q) {
                $q->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', now());
            })
            ->where(function ($q) use ($role) {
                $q->whereNull('target_audience')
                    ->orWhereJsonLength('target_audience', 0)
                    ->orWhereJsonContains('target_audience', $role);
            })
            ->latest('published_at');

        return response()->json([
            'opportunities' => $query->limit(100)->get(),
        ]);
    }

    public function panelIndex(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'announcements.view');
        $query = AlumniOpportunity::with(['project:id,name', 'creator:id,name,surname'])->latest();
        $query = $this->scopeManageableOpportunities($request, $query, 'announcements.view');

        if ($request->filled('project_id')) {
            $this->assertProjectOpportunityScope($request, (int) $request->project_id, 'announcements.view');
            $query->where('project_id', (int) $request->project_id);
        }

        return response()->json(['opportunities' => $query->paginate(20)]);
    }

    public function panelShow(Request $request, int $id): JsonResponse
    {
        $opportunity = AlumniOpportunity::with(['project:id,name', 'creator:id,name,surname'])->findOrFail($id);
        $this->abortUnlessOpportunityAccessible($request, $opportunity, 'announcements.view');

        return response()->json(['opportunity' => $opportunity]);
    }

    public function panelStore(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'announcements.create');
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'kind' => 'required|in:internship,network,event,other',
            'summary' => 'nullable|string|max:4000',
            'body' => 'nullable|string|max:20000',
            'link_url' => 'nullable|string|max:2048',
            'project_id' => 'nullable|exists:projects,id',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date',
            'published_at' => 'nullable|date',
            'expires_at' => 'nullable|date',
            'target_audience' => 'nullable|array',
            'target_audience.*' => 'in:student,alumni',
        ]);

        if (! empty($validated['project_id'])) {
            $this->assertProjectOpportunityScope($request, (int) $validated['project_id'], 'announcements.create');
        }

        $opportunity = AlumniOpportunity::create([
            'title' => $validated['title'],
            'kind' => $validated['kind'],
            'summary' => $validated['summary'] ?? null,
            'body' => $validated['body'] ?? null,
            'link_url' => $validated['link_url'] ?? null,
            'project_id' => $validated['project_id'] ?? null,
            'starts_at' => $validated['starts_at'] ?? null,
            'ends_at' => $validated['ends_at'] ?? null,
            'published_at' => $validated['published_at'] ?? now(),
            'expires_at' => $validated['expires_at'] ?? null,
            'target_audience' => $validated['target_audience'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Firsat kaydi olusturuldu.',
            'opportunity' => $opportunity->fresh(['project:id,name', 'creator:id,name,surname']),
        ], 201);
    }

    public function panelUpdate(Request $request, int $id): JsonResponse
    {
        $opportunity = AlumniOpportunity::findOrFail($id);
        $this->abortUnlessOpportunityAccessible($request, $opportunity, 'announcements.update');

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'kind' => 'sometimes|in:internship,network,event,other',
            'summary' => 'nullable|string|max:4000',
            'body' => 'nullable|string|max:20000',
            'link_url' => 'nullable|string|max:2048',
            'project_id' => 'nullable|exists:projects,id',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date',
            'published_at' => 'nullable|date',
            'expires_at' => 'nullable|date',
            'target_audience' => 'nullable|array',
            'target_audience.*' => 'in:student,alumni',
        ]);

        if (array_key_exists('project_id', $validated)) {
            $newProjectId = $validated['project_id'];
            if ($newProjectId !== null) {
                $this->assertProjectOpportunityScope($request, (int) $newProjectId, 'announcements.update');
            } elseif (! $this->permissionResolver->hasGlobalScope($request->user(), 'announcements.update')) {
                abort(403, 'Proje baglantisi kaldirma yalnizca ust admin icin yapilabilir.');
            }
        }

        $opportunity->update($validated);

        return response()->json([
            'message' => 'Firsat kaydi guncellendi.',
            'opportunity' => $opportunity->fresh(['project:id,name', 'creator:id,name,surname']),
        ]);
    }

    public function panelDestroy(Request $request, int $id): JsonResponse
    {
        $opportunity = AlumniOpportunity::findOrFail($id);
        $this->abortUnlessOpportunityAccessible($request, $opportunity, 'announcements.delete');
        $opportunity->delete();

        return response()->json(['message' => 'Firsat kaydi silindi.']);
    }
}
