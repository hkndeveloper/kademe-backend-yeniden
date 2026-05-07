<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\EurodeskProject;
use App\Models\Internship;
use App\Models\Mentor;
use App\Models\Participant;
use App\Models\Project;
use App\Models\RewardAward;
use App\Models\RewardTier;
use App\Models\User;
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

        $rewardTiers = ($access['projects.rewards.view'] || $access['projects.rewards.manage'])
            ? RewardTier::query()->where(function ($query) use ($project) {
                $query->where('project_id', $project->id)->orWhereNull('project_id');
            })->latest()->get()
            : collect();

        return response()->json([
            'project' => $project->only(['id', 'name', 'slug', 'type']),
            'access' => $access,
            'applicable_modules' => $this->projectModuleKeys($project),
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
                ? $rewardTiers
                : [],
            'reward_eligible_participants' => ($access['projects.rewards.view'] || $access['projects.rewards.manage'])
                ? $this->rewardEligibleParticipants($project, $participants, $rewardTiers)
                : [],
            'reward_awards' => ($access['projects.rewards.view'] || $access['projects.rewards.manage'])
                ? RewardAward::query()
                    ->with(['participant.user:id,name,surname,email', 'tier:id,name,reward_description', 'awarder:id,name,surname'])
                    ->where('project_id', $project->id)
                    ->latest('awarded_at')
                    ->get()
                    ->map(fn (RewardAward $award) => [
                        'id' => $award->id,
                        'participant_id' => $award->participant_id,
                        'name' => trim(($award->participant?->user?->name ?? '') . ' ' . ($award->participant?->user?->surname ?? '')),
                        'email' => $award->participant?->user?->email,
                        'reward_name' => $award->reward_name,
                        'status' => $award->status,
                        'awarded_at' => optional($award->awarded_at)?->toIso8601String(),
                        'note' => $award->note,
                        'tier' => $award->tier?->only(['id', 'name', 'reward_description']),
                        'awarder' => $award->awarder ? trim($award->awarder->name . ' ' . $award->awarder->surname) : null,
                    ])
                    ->values()
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

    public function storeRewardAward(Request $request, int $projectId): JsonResponse
    {
        $this->project($request, $projectId, 'projects.rewards.manage');
        $validated = $request->validate([
            'participant_id' => 'required|exists:participants,id',
            'reward_tier_id' => 'nullable|exists:reward_tiers,id',
            'reward_name' => 'required|string|max:255',
            'status' => ['nullable', Rule::in(['planned', 'given', 'cancelled'])],
            'awarded_at' => 'nullable|date',
            'note' => 'nullable|string|max:2000',
        ]);

        abort_unless(
            Participant::query()->where('id', $validated['participant_id'])->where('project_id', $projectId)->exists(),
            422,
            'Secilen katilimci bu projeye ait degil.'
        );

        if (! empty($validated['reward_tier_id'])) {
            abort_unless(
                RewardTier::query()
                    ->where('id', $validated['reward_tier_id'])
                    ->where(function ($query) use ($projectId) {
                        $query->where('project_id', $projectId)->orWhereNull('project_id');
                    })
                    ->exists(),
                422,
                'Secilen hediye kademesi bu proje icin uygun degil.'
            );
        }

        $award = RewardAward::query()->create([
            'project_id' => $projectId,
            'participant_id' => $validated['participant_id'],
            'reward_tier_id' => $validated['reward_tier_id'] ?? null,
            'reward_name' => $validated['reward_name'],
            'status' => $validated['status'] ?? 'given',
            'awarded_at' => $validated['awarded_at'] ?? now(),
            'note' => $validated['note'] ?? null,
            'awarded_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Hediye kaydi olusturuldu.',
            'reward_award' => $award->load(['participant.user:id,name,surname,email', 'tier:id,name,reward_description', 'awarder:id,name,surname']),
        ], 201);
    }

    public function destroyRewardAward(Request $request, int $projectId, int $id): JsonResponse
    {
        $this->project($request, $projectId, 'projects.rewards.manage');
        RewardAward::query()
            ->where('project_id', $projectId)
            ->findOrFail($id)
            ->delete();

        return response()->json(['message' => 'Hediye kaydi silindi.']);
    }

    private function projectModuleKeys(Project $project): array
    {
        $text = mb_strtolower(trim(implode(' ', array_filter([$project->type, $project->name, $project->slug]))));

        if (str_contains($text, 'diplomasi')) {
            return ['digital_bohca', 'internships', 'uploaded_files'];
        }

        if (str_contains($text, 'pergel')) {
            return ['digital_bohca', 'mentors', 'assignments'];
        }

        if (str_contains($text, 'kpd') || str_contains($text, 'psikolojik')) {
            return ['digital_bohca', 'kpd_appointments', 'kpd_reports'];
        }

        if (str_contains($text, 'kademe_plus') || str_contains($text, 'kademe plus') || str_contains($text, 'kademe+')) {
            return ['digital_bohca', 'badges', 'reward_tiers', 'participants_by_module'];
        }

        if (str_contains($text, 'eurodesk')) {
            return ['digital_bohca', 'eurodesk_projects'];
        }

        return ['digital_bohca'];
    }

    private function rewardEligibleParticipants(Project $project, $participants, $rewardTiers): array
    {
        if ($rewardTiers->isEmpty()) {
            return [];
        }

        $userIds = $participants->pluck('user_id')->unique()->values();
        $badgesByUser = User::query()
            ->whereIn('id', $userIds)
            ->with(['badges' => function ($query) use ($project) {
                $query->where(function ($inner) use ($project) {
                    $inner->whereNull('badges.project_id')->orWhere('badges.project_id', $project->id);
                });
            }])
            ->get()
            ->mapWithKeys(fn (User $user) => [$user->id => $user->badges->count()]);

        return $participants
            ->map(function (Participant $participant) use ($badgesByUser, $rewardTiers) {
                $badgeCount = (int) ($badgesByUser[$participant->user_id] ?? 0);
                $credit = (int) $participant->credit;
                $eligibleTiers = $rewardTiers
                    ->filter(fn (RewardTier $tier) => $badgeCount >= (int) $tier->min_badges && $credit >= (int) $tier->min_credits)
                    ->values();

                if ($eligibleTiers->isEmpty()) {
                    return null;
                }

                return [
                    'participant_id' => $participant->id,
                    'user_id' => $participant->user_id,
                    'name' => trim(($participant->user?->name ?? '') . ' ' . ($participant->user?->surname ?? '')),
                    'email' => $participant->user?->email,
                    'badge_count' => $badgeCount,
                    'credit' => $credit,
                    'eligible_rewards' => $eligibleTiers->map(fn (RewardTier $tier) => [
                        'id' => $tier->id,
                        'name' => $tier->name,
                        'reward_description' => $tier->reward_description,
                    ])->all(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
