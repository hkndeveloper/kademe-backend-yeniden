<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EurodeskProject;
use App\Models\Internship;
use App\Models\Mentor;
use App\Models\Participant;
use App\Models\Period;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\ProjectModuleEnrollment;
use App\Models\RewardAward;
use App\Models\RewardTier;
use App\Models\User;
use App\Services\ProjectFamilyAccessResolver;
use App\Support\ProjectSpecialModuleCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Project Family Modules
 */
class ProjectFamilyPanelController extends Controller
{
    public function __construct(
        private readonly ProjectFamilyAccessResolver $accessResolver
    ) {}

    public function show(Request $request, string $family): JsonResponse
    {
        $definition = $this->accessResolver->definition($family);
        abort_unless($definition !== null, 404, 'Proje ailesi bulunamadi.');

        $validated = $request->validate([
            'project_id' => 'nullable|integer|exists:projects,id',
            'period_id' => 'nullable|integer|exists:periods,id',
        ]);

        $user = $request->user();
        $projects = $this->accessResolver->accessibleProjects($user, $family);
        abort_if($projects->isEmpty(), 403, 'Bu proje ailesi icin yetkiniz bulunmuyor.');

        $projectId = isset($validated['project_id'])
            ? (int) $validated['project_id']
            : (int) $projects->first()->id;

        abort_unless(
            $projects->contains(fn (Project $project) => (int) $project->id === $projectId),
            403,
            'Bu proje ailesindeki secili projeye erisim yetkiniz bulunmuyor.'
        );

        $periodId = isset($validated['period_id']) ? (int) $validated['period_id'] : null;
        if ($periodId !== null) {
            abort_unless(
                Period::query()->where('id', $periodId)->where('project_id', $projectId)->exists(),
                422,
                'Secili donem bu projeye ait degil.'
            );
        }

        $selectedProject = $projects->first(fn (Project $project) => (int) $project->id === $projectId);
        $access = $this->accessResolver->accessMap($user, $family, $projectId);

        return response()->json([
            'family' => [
                'key' => $definition['key'],
                'label' => $definition['label'],
                'project_types' => $definition['project_types'],
                'special_modules' => $definition['special_modules'],
            ],
            'projects' => $projects->map(fn (Project $project) => $this->shapeProject($project))->values(),
            'selected_project' => $selectedProject ? $this->shapeProject($selectedProject) : null,
            'periods' => $this->periodsForProject($projectId),
            'access' => $access,
            'tabs' => $this->tabs($definition['tabs'] ?? [], $access),
            'summary' => $this->summary($family, $projectId, $periodId),
            'data' => $this->data($family, $projectId, $periodId, $access),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function shapeProject(Project $project): array
    {
        return [
            'id' => (int) $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            'type' => $project->type,
            'status' => $project->status,
            'applicable_modules' => ProjectSpecialModuleCatalog::forProject($project),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function periodsForProject(int $projectId): array
    {
        return Period::query()
            ->where('project_id', $projectId)
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get(['id', 'project_id', 'name', 'status', 'start_date', 'end_date'])
            ->map(fn (Period $period) => [
                'id' => (int) $period->id,
                'project_id' => (int) $period->project_id,
                'name' => $period->name,
                'status' => $period->status,
                'start_date' => $period->start_date,
                'end_date' => $period->end_date,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $tabs
     * @param  array<string, bool>  $access
     * @return list<array<string, mixed>>
     */
    private function tabs(array $tabs, array $access): array
    {
        return collect($tabs)
            ->map(function (array $tab) use ($access) {
                $permissions = $tab['permissions'] ?? [];

                return [
                    'id' => $tab['id'],
                    'label' => $tab['label'],
                    'permissions' => $permissions,
                    'visible' => collect($permissions)->contains(fn (string $permission) => $access[$permission] ?? false),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function summary(string $family, int $projectId, ?int $periodId): array
    {
        $participants = Participant::query()
            ->where('project_id', $projectId)
            ->when($periodId, fn ($query) => $query->where('period_id', $periodId));

        return match ($family) {
            'diplomasi360' => [
                ['id' => 'participants', 'label' => 'Katilimci', 'value' => (clone $participants)->count()],
                ['id' => 'internships', 'label' => 'Staj', 'value' => $this->internshipQuery($projectId, $periodId)->count()],
            ],
            'pergel' => [
                ['id' => 'participants', 'label' => 'Katilimci', 'value' => (clone $participants)->count()],
                ['id' => 'mentors', 'label' => 'Mentor', 'value' => Mentor::query()->where('project_id', $projectId)->count()],
            ],
            'eurodesk' => [
                ['id' => 'projects', 'label' => 'Hibe projesi', 'value' => $this->eurodeskSummaryForProject($projectId, $periodId)['total_projects']],
                ['id' => 'approved', 'label' => 'Onaylanan', 'value' => $this->eurodeskSummaryForProject($projectId, $periodId)['approved_projects']],
                ['id' => 'partnerships', 'label' => 'Ortaklik', 'value' => $this->eurodeskSummaryForProject($projectId, $periodId)['partnership_count']],
                ['id' => 'grant', 'label' => 'Onayli hibe', 'value' => $this->eurodeskSummaryForProject($projectId, $periodId)['approved_grant_amount']],
            ],
            'kademe-plus', 'zirve-kademe' => [
                ['id' => 'participants', 'label' => 'Katilimci', 'value' => (clone $participants)->count()],
                ['id' => 'tiers', 'label' => 'Odul kademesi', 'value' => $this->rewardTierQuery($projectId)->count()],
                ['id' => 'awards', 'label' => 'Hediye', 'value' => $this->rewardAwardQuery($projectId, $periodId)->count()],
                ['id' => 'modules', 'label' => 'Modul', 'value' => $this->projectModuleQuery($projectId, $periodId)->count()],
            ],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function data(string $family, int $projectId, ?int $periodId, array $access): array
    {
        return match ($family) {
            'diplomasi360' => [
                'internships' => $this->internshipQuery($projectId, $periodId)
                    ->with('participant.user:id,name,surname,email')
                    ->latest()
                    ->get()
                    ->map(fn (Internship $item) => [
                        'id' => (int) $item->id,
                        'participant_id' => $item->participant_id ? (int) $item->participant_id : null,
                        'company_name' => $item->company_name,
                        'position' => $item->position,
                        'participant_name' => trim(($item->participant?->user?->name ?? '').' '.($item->participant?->user?->surname ?? '')),
                        'participant_email' => $item->participant?->user?->email,
                        'start_date' => $item->start_date,
                        'end_date' => $item->end_date,
                        'description' => $item->description,
                        'document_path' => $item->document_path,
                    ])
                    ->values(),
                'participants' => $this->participantsForProject($projectId, $periodId),
            ],
            'pergel' => [
                'mentors' => Mentor::query()
                    ->where('project_id', $projectId)
                    ->latest()
                    ->get(['id', 'project_id', 'name', 'expertise', 'bio', 'photo_path', 'created_at'])
                    ->map(function (Mentor $mentor) use ($periodId) {
                        $assignedParticipants = $this->assignedParticipantsForMentor($mentor, $periodId);

                        return [
                            'id' => (int) $mentor->id,
                            'name' => $mentor->name,
                            'expertise' => $mentor->expertise,
                            'bio' => $mentor->bio,
                            'photo_path' => $mentor->photo_path,
                            'participants_count' => count($assignedParticipants),
                            'assigned_participants' => $assignedParticipants,
                        ];
                    })
                    ->values(),
                'participants' => $this->participantsForProject($projectId, $periodId),
            ],
            'eurodesk' => [
                'eurodesk_summary' => $this->eurodeskSummaryForProject($projectId, $periodId),
                'eurodesk_projects' => $this->eurodeskProjectQuery($projectId, $periodId)
                    ->with(['partnerships', 'period:id,name,status'])
                    ->latest()
                    ->get()
                    ->map(fn (EurodeskProject $item) => [
                        'id' => (int) $item->id,
                        'period_id' => $item->period_id ? (int) $item->period_id : null,
                        'period' => $item->period?->only(['id', 'name', 'status']),
                        'title' => $item->title,
                        'partner_organizations' => $item->partner_organizations ?? [],
                        'grant_status' => $item->grant_status,
                        'grant_amount' => $item->grant_amount,
                        'start_date' => $item->start_date,
                        'end_date' => $item->end_date,
                        'partnerships' => $item->partnerships
                            ->map(fn ($partnership) => [
                                'id' => (int) $partnership->id,
                                'organization_name' => $partnership->organization_name,
                                'country' => $partnership->country,
                                'contact_info' => $partnership->contact_info,
                            ])
                            ->values(),
                    ])
                    ->values(),
            ],
            'kademe-plus', 'zirve-kademe' => [
                'participants' => $this->participantsForProject($projectId, $periodId),
                'reward_tiers' => $this->rewardTierQuery($projectId)
                    ->latest()
                    ->get()
                    ->map(fn (RewardTier $tier) => [
                        'id' => (int) $tier->id,
                        'project_id' => $tier->project_id ? (int) $tier->project_id : null,
                        'name' => $tier->name,
                        'description' => $tier->description,
                        'min_badges' => (int) $tier->min_badges,
                        'min_credits' => (int) $tier->min_credits,
                        'reward_description' => $tier->reward_description,
                    ])
                    ->values(),
                'reward_eligible_participants' => $this->rewardEligibleParticipants($projectId, $periodId),
                'reward_awards' => $this->rewardAwardQuery($projectId, $periodId)
                    ->latest('awarded_at')
                    ->get()
                    ->map(fn (RewardAward $award) => $this->rewardAwardPayload($award))
                    ->values(),
                'kademe_modules' => $this->kademeModulesPayload($projectId, $access, $periodId),
                'pending_enrollments_count' => ProjectModuleEnrollment::query()
                    ->where('status', 'pending')
                    ->whereHas('module', fn ($query) => $query
                        ->where('project_id', $projectId)
                        ->when($periodId, fn ($builder) => $builder->where(fn ($inner) => $inner->whereNull('period_id')->orWhere('period_id', $periodId)))
                    )
                    ->count(),
            ],
            default => [],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function participantsForProject(int $projectId, ?int $periodId): array
    {
        return Participant::query()
            ->with('user:id,name,surname,email')
            ->where('project_id', $projectId)
            ->when($periodId, fn ($query) => $query->where('period_id', $periodId))
            ->orderByDesc('created_at')
            ->get(['id', 'user_id', 'project_id', 'period_id', 'status', 'graduation_status', 'credit', 'created_at'])
            ->map(fn (Participant $participant) => [
                'id' => (int) $participant->id,
                'name' => trim(($participant->user?->name ?? '').' '.($participant->user?->surname ?? '')),
                'email' => $participant->user?->email,
                'period_id' => $participant->period_id ? (int) $participant->period_id : null,
                'status' => $participant->status,
                'graduation_status' => $participant->graduation_status,
                'credit' => (int) $participant->credit,
            ])
            ->values()
            ->all();
    }
    /**
     * @return list<array<string, mixed>>
     */
    private function assignedParticipantsForMentor(Mentor $mentor, ?int $periodId): array
    {
        return Participant::query()
            ->select([
                'participants.id',
                'participants.user_id',
                'participants.project_id',
                'participants.period_id',
                'participants.status',
                'participant_mentor.period_id as mentor_period_id',
                'participant_mentor.note as mentor_note',
            ])
            ->join('participant_mentor', 'participants.id', '=', 'participant_mentor.participant_id')
            ->with('user:id,name,surname,email')
            ->where('participant_mentor.mentor_id', $mentor->id)
            ->when($periodId, fn ($query) => $query->where('participant_mentor.period_id', $periodId))
            ->orderByDesc('participant_mentor.created_at')
            ->get()
            ->map(fn (Participant $participant) => [
                'id' => (int) $participant->id,
                'name' => trim(($participant->user?->name ?? '').' '.($participant->user?->surname ?? '')),
                'email' => $participant->user?->email,
                'period_id' => $participant->getAttribute('mentor_period_id') ? (int) $participant->getAttribute('mentor_period_id') : null,
                'note' => $participant->getAttribute('mentor_note'),
            ])
            ->values()
            ->all();
    }
    /**
     * @return array<string, mixed>
     */
    private function eurodeskSummaryForProject(int $projectId, ?int $periodId): array
    {
        $rows = $this->eurodeskProjectQuery($projectId, $periodId)
            ->with('partnerships:id,eurodesk_project_id,country')
            ->get();

        $countries = $rows
            ->flatMap(fn (EurodeskProject $row) => $row->partnerships->pluck('country'))
            ->filter()
            ->map(fn ($country) => trim((string) $country))
            ->filter()
            ->unique()
            ->values();

        return [
            'total_projects' => $rows->count(),
            'applied_projects' => $rows->where('grant_status', 'applied')->count(),
            'approved_projects' => $rows->where('grant_status', 'approved')->count(),
            'completed_projects' => $rows->where('grant_status', 'completed')->count(),
            'rejected_projects' => $rows->where('grant_status', 'rejected')->count(),
            'total_grant_amount' => round((float) $rows->sum(fn (EurodeskProject $row) => (float) $row->grant_amount), 2),
            'approved_grant_amount' => round((float) $rows->where('grant_status', 'approved')->sum(fn (EurodeskProject $row) => (float) $row->grant_amount), 2),
            'partnership_count' => $rows->sum(fn (EurodeskProject $row) => $row->partnerships->count()),
            'country_count' => $countries->count(),
            'countries' => $countries->all(),
        ];
    }
    private function rewardTierQuery(int $projectId)
    {
        return RewardTier::query()->where(function ($query) use ($projectId) {
            $query->where('project_id', $projectId)->orWhereNull('project_id');
        });
    }

    private function rewardAwardQuery(int $projectId, ?int $periodId)
    {
        return RewardAward::query()
            ->with(['participant.user:id,name,surname,email', 'tier:id,name,reward_description', 'awarder:id,name,surname', 'deliverer:id,name,surname'])
            ->where('project_id', $projectId)
            ->when($periodId, fn ($query) => $query->whereHas('participant', fn ($inner) => $inner->where('period_id', $periodId)));
    }

    private function projectModuleQuery(int $projectId, ?int $periodId)
    {
        return ProjectModule::query()
            ->where('project_id', $projectId)
            ->when($periodId, fn ($query) => $query->where(fn ($inner) => $inner->whereNull('period_id')->orWhere('period_id', $periodId)))
            ->orderBy('sort_order');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function kademeModulesPayload(int $projectId, array $access, ?int $periodId): array
    {
        $query = $this->projectModuleQuery($projectId, $periodId);

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
            'id' => (int) $module->id,
            'title' => $module->title,
            'description' => $module->description,
            'period_id' => $module->period_id ? (int) $module->period_id : null,
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
                'id' => (int) $row->id,
                'user_id' => (int) $row->user_id,
                'participant_id' => $row->participant_id ? (int) $row->participant_id : null,
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

    private function rewardAwardPayload(RewardAward $award): array
    {
        return [
            'id' => (int) $award->id,
            'participant_id' => $award->participant_id ? (int) $award->participant_id : null,
            'reward_tier_id' => $award->reward_tier_id ? (int) $award->reward_tier_id : null,
            'name' => trim(($award->participant?->user?->name ?? '').' '.($award->participant?->user?->surname ?? '')),
            'email' => $award->participant?->user?->email,
            'reward_name' => $award->reward_name,
            'status' => $award->status,
            'awarded_at' => optional($award->awarded_at)?->toIso8601String(),
            'delivered_at' => optional($award->delivered_at)?->toIso8601String(),
            'note' => $award->note,
            'tier' => $award->tier?->only(['id', 'name', 'reward_description']),
            'awarder' => $award->awarder ? trim(($award->awarder->name ?? '').' '.($award->awarder->surname ?? '')) : null,
            'deliverer' => $award->deliverer ? trim(($award->deliverer->name ?? '').' '.($award->deliverer->surname ?? '')) : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rewardEligibleParticipants(int $projectId, ?int $periodId): array
    {
        $rewardTiers = $this->rewardTierQuery($projectId)->get();
        if ($rewardTiers->isEmpty()) {
            return [];
        }

        $participants = Participant::query()
            ->with('user:id,name,surname,email')
            ->where('project_id', $projectId)
            ->when($periodId, fn ($query) => $query->where('period_id', $periodId))
            ->get();
        $userIds = $participants->pluck('user_id')->unique()->values();
        $badgesByUser = User::query()
            ->whereIn('id', $userIds)
            ->with(['badges' => function ($query) use ($projectId) {
                $query->where(function ($inner) use ($projectId) {
                    $inner->whereNull('badges.project_id')->orWhere('badges.project_id', $projectId);
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
                    'participant_id' => (int) $participant->id,
                    'user_id' => (int) $participant->user_id,
                    'name' => trim(($participant->user?->name ?? '').' '.($participant->user?->surname ?? '')),
                    'email' => $participant->user?->email,
                    'badge_count' => $badgeCount,
                    'credit' => $credit,
                    'eligible_rewards' => $eligibleTiers->map(fn (RewardTier $tier) => [
                        'id' => (int) $tier->id,
                        'name' => $tier->name,
                        'reward_description' => $tier->reward_description,
                    ])->all(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
    private function internshipQuery(int $projectId, ?int $periodId)
    {
        return Internship::query()
            ->whereHas('participant', function ($query) use ($projectId, $periodId) {
                $query->where('project_id', $projectId)
                    ->when($periodId, fn ($builder) => $builder->where('period_id', $periodId));
            });
    }

    private function eurodeskProjectQuery(int $projectId, ?int $periodId)
    {
        return EurodeskProject::query()
            ->where('project_id', $projectId)
            ->when($periodId, fn ($query) => $query->where(function ($builder) use ($periodId) {
                $builder->whereNull('period_id')->orWhere('period_id', $periodId);
            }));
    }
}
