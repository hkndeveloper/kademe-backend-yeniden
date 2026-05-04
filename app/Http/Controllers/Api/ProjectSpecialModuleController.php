<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\EurodeskProject;
use App\Models\Internship;
use App\Models\Mentor;
use App\Models\Participant;
use App\Models\Project;
use App\Models\RewardTier;
use App\Services\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectSpecialModuleController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {
    }

    private function project(Request $request, int $projectId, string $permission): Project
    {
        $project = Project::query()->findOrFail($projectId);
        $this->abortUnlessAllowedForProject($request, $permission, $project);

        return $project;
    }

    public function index(Request $request, int $projectId): JsonResponse
    {
        $project = Project::query()->findOrFail($projectId);
        $permissions = [
            'projects.internships.view',
            'projects.internships.manage',
            'projects.mentors.view',
            'projects.mentors.manage',
            'projects.eurodesk.view',
            'projects.eurodesk.manage',
            'projects.rewards.view',
            'projects.rewards.manage',
        ];

        $access = [];
        foreach ($permissions as $permission) {
            $access[$permission] = $this->permissionResolver->canAccessProject($request->user(), $permission, $project->id);
        }

        abort_unless(in_array(true, $access, true), 403, 'Bu proje modulleri icin yetkiniz bulunmuyor.');

        $participants = Participant::query()
            ->with('user:id,name,surname,email')
            ->where('project_id', $project->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'project' => $project->only(['id', 'name', 'slug', 'type']),
            'access' => $access,
            'participants' => $participants->map(fn (Participant $participant) => [
                'id' => $participant->id,
                'name' => trim(($participant->user?->name ?? '') . ' ' . ($participant->user?->surname ?? '')),
                'email' => $participant->user?->email,
                'status' => $participant->status,
                'graduation_status' => $participant->graduation_status,
            ])->values(),
            'internships' => ($access['projects.internships.view'] || $access['projects.internships.manage'])
                ? Internship::query()
                    ->with('participant.user:id,name,surname,email')
                    ->whereHas('participant', fn ($query) => $query->where('project_id', $project->id))
                    ->latest()
                    ->get()
                : [],
            'mentors' => ($access['projects.mentors.view'] || $access['projects.mentors.manage'])
                ? Mentor::query()->where('project_id', $project->id)->latest()->get()
                : [],
            'eurodesk_projects' => ($access['projects.eurodesk.view'] || $access['projects.eurodesk.manage'])
                ? EurodeskProject::query()->with('partnerships')->where('project_id', $project->id)->latest()->get()
                : [],
            'reward_tiers' => ($access['projects.rewards.view'] || $access['projects.rewards.manage'])
                ? RewardTier::query()->where(function ($query) use ($project) {
                    $query->where('project_id', $project->id)->orWhereNull('project_id');
                })->latest()->get()
                : [],
        ]);
    }

    public function storeInternship(Request $request, int $projectId): JsonResponse
    {
        $this->project($request, $projectId, 'projects.internships.manage');
        $validated = $request->validate([
            'participant_id' => 'required|exists:participants,id',
            'company_name' => 'required|string|max:255',
            'position' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'description' => 'nullable|string|max:5000',
            'document_path' => 'nullable|string|max:2048',
        ]);

        abort_unless(
            Participant::query()->where('id', $validated['participant_id'])->where('project_id', $projectId)->exists(),
            422,
            'Secilen katilimci bu projeye ait degil.'
        );

        return response()->json([
            'message' => 'Staj bilgisi kaydedildi.',
            'internship' => Internship::create($validated)->load('participant.user:id,name,surname,email'),
        ], 201);
    }

    public function updateInternship(Request $request, int $projectId, int $id): JsonResponse
    {
        $this->project($request, $projectId, 'projects.internships.manage');
        $internship = Internship::query()
            ->whereHas('participant', fn ($query) => $query->where('project_id', $projectId))
            ->findOrFail($id);
        $validated = $request->validate([
            'company_name' => 'required|string|max:255',
            'position' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'description' => 'nullable|string|max:5000',
            'document_path' => 'nullable|string|max:2048',
        ]);

        $internship->update($validated);

        return response()->json(['message' => 'Staj bilgisi guncellendi.', 'internship' => $internship->fresh('participant.user:id,name,surname,email')]);
    }

    public function destroyInternship(Request $request, int $projectId, int $id): JsonResponse
    {
        $this->project($request, $projectId, 'projects.internships.manage');
        Internship::query()
            ->whereHas('participant', fn ($query) => $query->where('project_id', $projectId))
            ->findOrFail($id)
            ->delete();

        return response()->json(['message' => 'Staj bilgisi silindi.']);
    }

    public function storeMentor(Request $request, int $projectId): JsonResponse
    {
        $this->project($request, $projectId, 'projects.mentors.manage');
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'bio' => 'nullable|string|max:5000',
            'expertise' => 'nullable|string|max:255',
            'photo_path' => 'nullable|string|max:2048',
        ]);

        return response()->json([
            'message' => 'Mentor kaydedildi.',
            'mentor' => Mentor::create($validated + ['project_id' => $projectId]),
        ], 201);
    }

    public function updateMentor(Request $request, int $projectId, int $id): JsonResponse
    {
        $this->project($request, $projectId, 'projects.mentors.manage');
        $mentor = Mentor::query()->where('project_id', $projectId)->findOrFail($id);
        $mentor->update($request->validate([
            'name' => 'required|string|max:255',
            'bio' => 'nullable|string|max:5000',
            'expertise' => 'nullable|string|max:255',
            'photo_path' => 'nullable|string|max:2048',
        ]));

        return response()->json(['message' => 'Mentor guncellendi.', 'mentor' => $mentor->fresh()]);
    }

    public function destroyMentor(Request $request, int $projectId, int $id): JsonResponse
    {
        $this->project($request, $projectId, 'projects.mentors.manage');
        Mentor::query()->where('project_id', $projectId)->findOrFail($id)->delete();

        return response()->json(['message' => 'Mentor silindi.']);
    }

    public function storeEurodeskProject(Request $request, int $projectId): JsonResponse
    {
        $this->project($request, $projectId, 'projects.eurodesk.manage');
        $validated = $this->validateEurodesk($request);

        return response()->json([
            'message' => 'Eurodesk proje bilgisi kaydedildi.',
            'eurodesk_project' => EurodeskProject::create($validated + ['project_id' => $projectId])->load('partnerships'),
        ], 201);
    }

    public function updateEurodeskProject(Request $request, int $projectId, int $id): JsonResponse
    {
        $this->project($request, $projectId, 'projects.eurodesk.manage');
        $eurodeskProject = EurodeskProject::query()->where('project_id', $projectId)->findOrFail($id);
        $eurodeskProject->update($this->validateEurodesk($request));

        return response()->json(['message' => 'Eurodesk proje bilgisi guncellendi.', 'eurodesk_project' => $eurodeskProject->fresh('partnerships')]);
    }

    public function destroyEurodeskProject(Request $request, int $projectId, int $id): JsonResponse
    {
        $this->project($request, $projectId, 'projects.eurodesk.manage');
        EurodeskProject::query()->where('project_id', $projectId)->findOrFail($id)->delete();

        return response()->json(['message' => 'Eurodesk proje bilgisi silindi.']);
    }

    private function validateEurodesk(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:255',
            'partner_organizations' => 'nullable|array',
            'partner_organizations.*' => 'nullable|string|max:255',
            'grant_amount' => 'nullable|numeric|min:0',
            'grant_status' => ['required', Rule::in(['applied', 'approved', 'rejected', 'completed'])],
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);
    }

    public function storeRewardTier(Request $request, int $projectId): JsonResponse
    {
        $this->project($request, $projectId, 'projects.rewards.manage');
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'min_badges' => 'required|integer|min:0',
            'min_credits' => 'required|integer|min:0',
            'reward_description' => 'required|string|max:255',
        ]);

        return response()->json([
            'message' => 'Hediye kademesi kaydedildi.',
            'reward_tier' => RewardTier::create($validated + ['project_id' => $projectId]),
        ], 201);
    }

    public function updateRewardTier(Request $request, int $projectId, int $id): JsonResponse
    {
        $this->project($request, $projectId, 'projects.rewards.manage');
        $tier = RewardTier::query()->where('project_id', $projectId)->findOrFail($id);
        $tier->update($request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'min_badges' => 'required|integer|min:0',
            'min_credits' => 'required|integer|min:0',
            'reward_description' => 'required|string|max:255',
        ]));

        return response()->json(['message' => 'Hediye kademesi guncellendi.', 'reward_tier' => $tier->fresh()]);
    }

    public function destroyRewardTier(Request $request, int $projectId, int $id): JsonResponse
    {
        $this->project($request, $projectId, 'projects.rewards.manage');
        RewardTier::query()->where('project_id', $projectId)->findOrFail($id)->delete();

        return response()->json(['message' => 'Hediye kademesi silindi.']);
    }
}
