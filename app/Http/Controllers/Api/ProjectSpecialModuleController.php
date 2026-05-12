<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\EurodeskPartnership;
use App\Models\EurodeskProject;
use App\Models\Internship;
use App\Models\Mentor;
use App\Models\Participant;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\ProjectModuleEnrollment;
use App\Models\RewardAward;
use App\Models\RewardTier;
use App\Models\User;
use App\Services\PermissionResolver;
use App\Support\ProjectSpecialModuleCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectSpecialModuleController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {}

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
            'applicable_modules' => ProjectSpecialModuleCatalog::forProject($project),
            'participants' => $participants->map(fn (Participant $participant) => [
                'id' => $participant->id,
                'name' => trim(($participant->user?->name ?? '').' '.($participant->user?->surname ?? '')),
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
                        'name' => trim(($award->participant?->user?->name ?? '').' '.($award->participant?->user?->surname ?? '')),
                        'email' => $award->participant?->user?->email,
                        'reward_name' => $award->reward_name,
                        'status' => $award->status,
                        'awarded_at' => optional($award->awarded_at)?->toIso8601String(),
                        'note' => $award->note,
                        'tier' => $award->tier?->only(['id', 'name', 'reward_description']),
                        'awarder' => $award->awarder ? trim($award->awarder->name.' '.$award->awarder->surname) : null,
                    ])
                    ->values()
                : [],
            'kademe_modules' => $this->kademeModulesPayload($project, $access),
        ]);
    }

    /**
     * @param  array<string, bool>  $access
     */
    private function kademeModulesPayload(Project $project, array $access): array
    {
        if (! ProjectSpecialModuleCatalog::supportsKademeModuleWorkflow($project)) {
            return [];
        }

        if (! ($access['projects.rewards.view'] ?? false) && ! ($access['projects.rewards.manage'] ?? false)) {
            return [];
        }

        $query = ProjectModule::query()->where('project_id', $project->id)->orderBy('sort_order');

        if ($access['projects.rewards.manage'] ?? false) {
            return $query
                ->with(['enrollments.user:id,name,surname,email'])
                ->get()
                ->map(fn (ProjectModule $module) => $this->serializeKademeModuleForPanel($module, true))
                ->values()
                ->all();
        }

        return $query
            ->withCount('enrollments')
            ->get()
            ->map(fn (ProjectModule $module) => $this->serializeKademeModuleForPanel($module, false))
            ->values()
            ->all();
    }

    private function serializeKademeModuleForPanel(ProjectModule $module, bool $includeEnrollments): array
    {
        $base = [
            'id' => $module->id,
            'title' => $module->title,
            'description' => $module->description,
            'sort_order' => (int) $module->sort_order,
            'is_active' => (bool) $module->is_active,
            'application_open' => (bool) $module->application_open,
            'requires_consent' => (bool) $module->requires_consent,
            'consent_checkbox_label' => $module->consent_checkbox_label,
            'warning_text' => $module->warning_text,
            'requires_coordinator_approval' => (bool) $module->requires_coordinator_approval,
            'outcomes' => $module->outcomes ?? [],
            'instructors' => $module->instructors ?? [],
            'faq_items' => $module->faq_items ?? [],
        ];

        if ($includeEnrollments) {
            $base['enrollments'] = $module->enrollments->map(fn (ProjectModuleEnrollment $row) => [
                'id' => $row->id,
                'user_id' => $row->user_id,
                'participant_id' => $row->participant_id,
                'status' => $row->status,
                'consented_at' => optional($row->consented_at)?->toIso8601String(),
                'reviewed_at' => optional($row->reviewed_at)?->toIso8601String(),
                'note' => $row->note,
                'user' => $row->user ? [
                    'name' => trim(($row->user->name ?? '').' '.($row->user->surname ?? '')),
                    'email' => $row->user->email,
                ] : null,
            ])->values()->all();
        } else {
            $base['enrollments_count'] = (int) ($module->enrollments_count ?? $module->enrollments()->count());
        }

        return $base;
    }

    public function storeKademeModule(Request $request, int $projectId): JsonResponse
    {
        $project = $this->project($request, $projectId, 'projects.rewards.manage');
        abort_unless(ProjectSpecialModuleCatalog::supportsKademeModuleWorkflow($project), 422, 'Bu proje turu KADEME+ modullerini desteklemiyor.');

        $validated = $this->validatedKademeModule($request, true);

        $module = ProjectModule::query()->create(array_merge([
            'sort_order' => 0,
            'is_active' => true,
            'application_open' => true,
            'requires_consent' => true,
            'requires_coordinator_approval' => false,
        ], $validated, ['project_id' => $projectId]));

        return response()->json([
            'message' => 'KADEME+ modulu kaydedildi.',
            'kademe_module' => $this->serializeKademeModuleForPanel($module->fresh(), true),
        ], 201);
    }

    public function updateKademeModule(Request $request, int $projectId, int $id): JsonResponse
    {
        $project = $this->project($request, $projectId, 'projects.rewards.manage');
        abort_unless(ProjectSpecialModuleCatalog::supportsKademeModuleWorkflow($project), 422, 'Bu proje turu KADEME+ modullerini desteklemiyor.');
        $module = ProjectModule::query()->where('project_id', $projectId)->findOrFail($id);
        $module->update($this->validatedKademeModule($request, false));

        return response()->json([
            'message' => 'KADEME+ modulu guncellendi.',
            'kademe_module' => $this->serializeKademeModuleForPanel($module->fresh(['enrollments.user:id,name,surname,email']), true),
        ]);
    }

    public function destroyKademeModule(Request $request, int $projectId, int $id): JsonResponse
    {
        $project = $this->project($request, $projectId, 'projects.rewards.manage');
        abort_unless(ProjectSpecialModuleCatalog::supportsKademeModuleWorkflow($project), 422, 'Bu proje turu KADEME+ modullerini desteklemiyor.');
        ProjectModule::query()->where('project_id', $projectId)->findOrFail($id)->delete();

        return response()->json(['message' => 'KADEME+ modulu silindi.']);
    }

    public function updateKademeModuleEnrollment(Request $request, int $projectId, int $enrollmentId): JsonResponse
    {
        $this->project($request, $projectId, 'projects.rewards.manage');
        $enrollment = ProjectModuleEnrollment::query()
            ->whereHas('module', fn ($q) => $q->where('project_id', $projectId))
            ->findOrFail($enrollmentId);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['pending', 'approved', 'rejected'])],
            'note' => 'nullable|string|max:2000',
        ]);

        $enrollment->update([
            'status' => $validated['status'],
            'note' => $validated['note'] ?? $enrollment->note,
            'reviewed_at' => now(),
            'reviewed_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Modul kaydi guncellendi.',
            'enrollment' => $enrollment->fresh(['user:id,name,surname,email']),
        ]);
    }

    private function validatedKademeModule(Request $request, bool $creating): array
    {
        $titleRule = $creating ? 'required' : 'sometimes|required';

        return $request->validate([
            'title' => $titleRule.'|string|max:255',
            'description' => 'nullable|string|max:20000',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'sometimes|boolean',
            'application_open' => 'sometimes|boolean',
            'requires_consent' => 'sometimes|boolean',
            'consent_checkbox_label' => 'nullable|string|max:2000',
            'warning_text' => 'nullable|string|max:20000',
            'requires_coordinator_approval' => 'sometimes|boolean',
            'outcomes' => 'nullable|array',
            'outcomes.*' => 'string|max:1000',
            'instructors' => 'nullable|array',
            'instructors.*.name' => 'required_with:instructors|string|max:255',
            'instructors.*.bio' => 'nullable|string|max:5000',
            'instructors.*.photo_path' => 'nullable|string|max:2048',
            'faq_items' => 'nullable|array',
            'faq_items.*.question' => 'required_with:faq_items|string|max:500',
            'faq_items.*.answer' => 'required_with:faq_items|string|max:5000',
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

    // -------------------------------------------------------
    // Mentor-Katilimci eslestirme (Pergel)
    // -------------------------------------------------------

    public function assignMentorToParticipant(Request $request, int $projectId, int $mentorId): JsonResponse
    {
        $this->project($request, $projectId, 'projects.mentors.manage');
        $mentor = Mentor::query()->where('project_id', $projectId)->findOrFail($mentorId);

        $validated = $request->validate([
            'participant_id' => 'required|integer|exists:participants,id',
            'period_id'      => 'nullable|integer|exists:periods,id',
            'note'           => 'nullable|string|max:1000',
        ]);

        $mentor->participants()->syncWithoutDetaching([
            $validated['participant_id'] => [
                'period_id'   => $validated['period_id'] ?? null,
                'assigned_by' => $request->user()->id,
                'note'        => $validated['note'] ?? null,
            ],
        ]);

        return response()->json(['message' => 'Katilimci mentor ile eslendi.']);
    }

    public function unassignMentorFromParticipant(Request $request, int $projectId, int $mentorId, int $participantId): JsonResponse
    {
        $this->project($request, $projectId, 'projects.mentors.manage');
        $mentor = Mentor::query()->where('project_id', $projectId)->findOrFail($mentorId);
        $mentor->participants()->detach($participantId);

        return response()->json(['message' => 'Katilimci mentor eslestirmesi kaldirildi.']);
    }

    public function mentorParticipants(Request $request, int $projectId, int $mentorId): JsonResponse
    {
        $this->project($request, $projectId, 'projects.mentors.view');
        $mentor = Mentor::query()->where('project_id', $projectId)->with([
            'participants.user:id,name,surname,email',
        ])->findOrFail($mentorId);

        return response()->json([
            'mentor'       => $mentor,
            'participants' => $mentor->participants,
        ]);
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

    // -------------------------------------------------------
    // Eurodesk Partnership CRUD
    // -------------------------------------------------------

    public function storeEurodeskPartnership(Request $request, int $projectId, int $eurodeskProjectId): JsonResponse
    {
        $this->project($request, $projectId, 'projects.eurodesk.manage');
        $eurodeskProject = EurodeskProject::query()->where('project_id', $projectId)->findOrFail($eurodeskProjectId);

        $validated = $request->validate([
            'organization_name' => 'required|string|max:255',
            'country'           => 'nullable|string|max:100',
            'contact_info'      => 'nullable|string|max:1000',
        ]);

        $partnership = $eurodeskProject->partnerships()->create($validated);

        return response()->json(['message' => 'Ortaklik kaydedildi.', 'partnership' => $partnership], 201);
    }

    public function updateEurodeskPartnership(Request $request, int $projectId, int $eurodeskProjectId, int $partnershipId): JsonResponse
    {
        $this->project($request, $projectId, 'projects.eurodesk.manage');
        $eurodeskProject = EurodeskProject::query()->where('project_id', $projectId)->findOrFail($eurodeskProjectId);

        $partnership = EurodeskPartnership::query()
            ->where('eurodesk_project_id', $eurodeskProject->id)
            ->findOrFail($partnershipId);

        $partnership->update($request->validate([
            'organization_name' => 'required|string|max:255',
            'country'           => 'nullable|string|max:100',
            'contact_info'      => 'nullable|string|max:1000',
        ]));

        return response()->json(['message' => 'Ortaklik guncellendi.', 'partnership' => $partnership->fresh()]);
    }

    public function destroyEurodeskPartnership(Request $request, int $projectId, int $eurodeskProjectId, int $partnershipId): JsonResponse
    {
        $this->project($request, $projectId, 'projects.eurodesk.manage');
        $eurodeskProject = EurodeskProject::query()->where('project_id', $projectId)->findOrFail($eurodeskProjectId);

        EurodeskPartnership::query()
            ->where('eurodesk_project_id', $eurodeskProject->id)
            ->findOrFail($partnershipId)
            ->delete();

        return response()->json(['message' => 'Ortaklik silindi.']);
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

    public function markRewardDelivered(Request $request, int $projectId, int $id): JsonResponse
    {
        $this->project($request, $projectId, 'projects.rewards.manage');
        $award = RewardAward::query()
            ->where('project_id', $projectId)
            ->findOrFail($id);

        $award->markDelivered($request->user()->id);

        return response()->json(['message' => 'Hediye teslim edildi olarak isaretlendi.', 'award' => $award->fresh()]);
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
                    'name' => trim(($participant->user?->name ?? '').' '.($participant->user?->surname ?? '')),
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
