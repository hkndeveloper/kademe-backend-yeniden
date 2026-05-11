<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Badge;
use App\Models\Certificate;
use App\Models\CreditLog;
use App\Models\EurodeskProject;
use App\Models\Internship;
use App\Models\Mentor;
use App\Models\Participant;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\ProjectModuleEnrollment;
use App\Models\RewardTier;
use App\Models\User;
use App\Support\ProjectSpecialModuleCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class StudentDashboardController extends Controller
{
    private function participationQueryFor($user)
    {
        return Participant::where('user_id', $user->id)
            ->where(function ($query) use ($user) {
                $query->where('status', 'active');

                if ($user->role === 'alumni') {
                    $query->orWhere('graduation_status', 'graduated')
                        ->orWhereNotNull('graduated_at');
                }
            });
    }

    /**
     * Öğrencinin rozetleri, aktif kredi durumu ve kredi geçmişi
     */
    public function summary(Request $request)
    {
        $user = $request->user();

        // Öğrencinin kabul edildiği aktif proje dönemi bilgileri
        $participations = $this->participationQueryFor($user)
            ->with(['project', 'period'])
            ->get();

        // Kredi loglarını (Puan hareketleri) getir
        $creditHistory = CreditLog::where('user_id', $user->id)
            ->with(['project', 'program'])
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        $kademePlusProjectIds = $this->kademePlusProjectIdsFromParticipations($participations);

        // KADEME+ disindaki rozetler ogrenci dashboard'inda gosterilmez.
        $badges = $this->kademePlusBadgeBaseQuery($user, $kademePlusProjectIds)->get();
        $profileBadgeFrame = $this->resolveProfileBadgeFrame($user, $kademePlusProjectIds);

        $latestAwardedIds = $user->badges()
            ->whereNotNull('badges.title_label')
            ->whereIn('badges.title_label', ['Ayin Pergellisi', 'Ayin Konusmacisi'])
            ->orderByDesc('user_badges.awarded_at')
            ->pluck('badges.id');

        $monthlyTitles = Badge::query()
            ->whereIn('id', $latestAwardedIds)
            ->pluck('title_label')
            ->filter()
            ->unique()
            ->values();

        return response()->json([
            'participations' => $participations,
            'recent_credit_history' => $creditHistory,
            'earned_badges' => $badges,
            'monthly_titles' => $monthlyTitles,
            'total_score' => $participations->sum('credit'),
            'profile_badge_frame' => $profileBadgeFrame,
        ]);
    }

    public function projects(Request $request)
    {
        $user = $request->user();

        $participations = $this->participationQueryFor($user)
            ->with('project:id,name,slug,type')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'projects' => $participations
                ->filter(fn (Participant $participation) => $participation->project !== null)
                ->map(fn (Participant $participation) => [
                    'id' => $participation->project->id,
                    'name' => $participation->project->name,
                    'slug' => $participation->project->slug,
                    'type' => $participation->project->type,
                    'participation_status' => $participation->status,
                    'graduation_status' => $participation->graduation_status,
                    'graduated_at' => optional($participation->graduated_at)?->toIso8601String(),
                ])
                ->unique('id')
                ->values(),
        ]);
    }

    public function digitalCv(Request $request)
    {
        $user = $request->user()->loadMissing('profile');

        $participations = Participant::query()
            ->where('user_id', $user->id)
            ->where(function ($query) use ($user) {
                $query->whereIn('status', ['active', 'graduated'])
                    ->orWhereIn('graduation_status', ['completed', 'graduated'])
                    ->orWhereNotNull('graduated_at');

                if ($user->role === 'alumni') {
                    $query->orWhere('graduation_status', 'graduated');
                }
            })
            ->with(['project:id,name,slug,type,short_description,description', 'period:id,name'])
            ->orderByDesc('graduated_at')
            ->orderByDesc('created_at')
            ->get();

        $certificates = Certificate::query()
            ->where('user_id', $user->id)
            ->with(['project:id,name,slug', 'period:id,name'])
            ->orderByDesc('issued_at')
            ->get();

        $badges = $user->badges()
            ->with('project:id,name,slug')
            ->orderByDesc('user_badges.awarded_at')
            ->get();

        $creditHistory = CreditLog::query()
            ->where('user_id', $user->id)
            ->with(['project:id,name,slug', 'program:id,title'])
            ->orderByDesc('created_at')
            ->take(25)
            ->get();

        return response()->json([
            'profile' => [
                'full_name' => trim(($user->name ?? '').' '.($user->surname ?? '')),
                'email' => $user->email,
                'phone' => $user->phone,
                'location' => $user->hometown,
                'university' => $user->university,
                'department' => $user->department,
                'class_year' => $user->class_year,
                'summary' => $user->profile?->motivation_message,
                'linkedin_url' => $user->profile?->linkedin_url,
                'github_url' => $user->profile?->github_url,
                'instagram_url' => $user->profile?->instagram_url,
            ],
            'approved' => [
                'title' => 'KADEME Onayli Dijital CV',
                'generated_at' => now()->toIso8601String(),
                'total_credit' => (int) $participations->sum('credit'),
                'completed_project_count' => $participations
                    ->filter(fn (Participant $participation) => in_array($participation->graduation_status, ['completed', 'graduated'], true) || $participation->graduated_at !== null)
                    ->count(),
                'badge_count' => $badges->count(),
                'certificate_count' => $certificates->count(),
            ],
            'projects' => $participations
                ->filter(fn (Participant $participation) => $participation->project !== null)
                ->map(fn (Participant $participation) => [
                    'id' => $participation->project->id,
                    'name' => $participation->project->name,
                    'type' => $participation->project->type,
                    'description' => $participation->project->short_description ?: $participation->project->description,
                    'period' => $participation->period?->name,
                    'status' => $participation->status,
                    'graduation_status' => $participation->graduation_status,
                    'credit' => (int) $participation->credit,
                    'enrolled_at' => optional($participation->enrolled_at)?->toIso8601String(),
                    'graduated_at' => optional($participation->graduated_at)?->toIso8601String(),
                ])
                ->values(),
            'badges' => $badges->map(fn (Badge $badge) => [
                'id' => $badge->id,
                'name' => $badge->name,
                'description' => $badge->description,
                'tier' => $badge->tier,
                'title_label' => $badge->title_label,
                'project' => $badge->project?->name,
                'awarded_at' => optional($badge->pivot?->awarded_at)?->toIso8601String(),
            ])->values(),
            'certificates' => $certificates->map(fn (Certificate $certificate) => [
                'id' => $certificate->id,
                'type' => $certificate->type,
                'project' => $certificate->project?->name,
                'period' => $certificate->period?->name,
                'verification_code' => $certificate->verification_code,
                'issued_at' => optional($certificate->issued_at)?->toIso8601String(),
            ])->values(),
            'credit_history' => $creditHistory->map(fn (CreditLog $log) => [
                'amount' => (int) $log->amount,
                'type' => $log->type,
                'reason' => $log->reason,
                'project' => $log->project?->name,
                'program' => $log->program?->title,
                'created_at' => optional($log->created_at)?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function projectSpecials(Request $request)
    {
        $user = $request->user();

        $participations = $this->participationQueryFor($user)
            ->with(['project:id,name,slug,type', 'period:id,name'])
            ->get()
            ->filter(fn (Participant $participation) => $participation->project !== null)
            ->values();

        $projectIds = $participations->pluck('project_id')->unique()->values();
        $participantIds = $participations->pluck('id')->unique()->values();

        $internshipsByParticipant = Internship::query()
            ->whereIn('participant_id', $participantIds)
            ->orderByDesc('start_date')
            ->get()
            ->groupBy('participant_id');

        $mentorsByProject = Mentor::query()
            ->whereIn('project_id', $projectIds)
            ->orderBy('name')
            ->get()
            ->groupBy('project_id');

        $eurodeskByProject = EurodeskProject::query()
            ->whereIn('project_id', $projectIds)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('project_id');

        $rewardTiersByProject = RewardTier::query()
            ->where(function ($query) use ($projectIds) {
                $query->whereIn('project_id', $projectIds)->orWhereNull('project_id');
            })
            ->orderBy('min_badges')
            ->orderBy('min_credits')
            ->get()
            ->groupBy(fn (RewardTier $tier) => $tier->project_id ?: 0);

        $badgeCountsByProject = $user->badges()
            ->get()
            ->groupBy(fn (Badge $badge) => $badge->pivot?->project_id ?: $badge->project_id ?: 0)
            ->map(fn ($badges) => $badges->count());

        $moduleRows = ProjectModule::query()
            ->whereIn('project_id', $projectIds)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $modulesByProject = $moduleRows->groupBy('project_id');

        $enrollmentRows = ProjectModuleEnrollment::query()
            ->where('user_id', $user->id)
            ->whereIn('project_module_id', $moduleRows->pluck('id'))
            ->get()
            ->keyBy('project_module_id');

        return response()->json([
            'projects' => $participations->map(function (Participant $participation) use (
                $internshipsByParticipant,
                $mentorsByProject,
                $eurodeskByProject,
                $rewardTiersByProject,
                $badgeCountsByProject,
                $modulesByProject,
                $enrollmentRows
            ) {
                $project = $participation->project;
                $moduleKeys = ProjectSpecialModuleCatalog::moduleKeys($project?->type, $project?->name, $project?->slug);
                $projectRewardTiers = ($rewardTiersByProject->get($project->id) ?? collect())
                    ->concat($rewardTiersByProject->get(0) ?? collect())
                    ->values();
                $badgeCount = (int) (($badgeCountsByProject->get($project->id) ?? 0) + ($badgeCountsByProject->get(0) ?? 0));

                return [
                    'project' => [
                        'id' => $project->id,
                        'name' => $project->name,
                        'slug' => $project->slug,
                        'type' => $project->type,
                    ],
                    'period' => $participation->period?->only(['id', 'name']),
                    'participation' => [
                        'id' => $participation->id,
                        'status' => $participation->status,
                        'graduation_status' => $participation->graduation_status,
                        'credit' => (int) $participation->credit,
                    ],
                    'modules' => $moduleKeys,
                    'internships' => in_array('internships', $moduleKeys, true)
                        ? ($internshipsByParticipant->get($participation->id) ?? collect())->map(fn (Internship $internship) => [
                            'id' => $internship->id,
                            'company_name' => $internship->company_name,
                            'position' => $internship->position,
                            'start_date' => optional($internship->start_date)?->toDateString(),
                            'end_date' => optional($internship->end_date)?->toDateString(),
                            'description' => $internship->description,
                            'has_document' => ! empty($internship->document_path),
                        ])->values()
                        : [],
                    'mentors' => in_array('mentors', $moduleKeys, true)
                        ? ($mentorsByProject->get($project->id) ?? collect())->map(fn (Mentor $mentor) => [
                            'id' => $mentor->id,
                            'name' => $mentor->name,
                            'expertise' => $mentor->expertise,
                            'bio' => $mentor->bio,
                            'photo_path' => $mentor->photo_path,
                        ])->values()
                        : [],
                    'eurodesk_projects' => in_array('eurodesk_projects', $moduleKeys, true)
                        ? ($eurodeskByProject->get($project->id) ?? collect())->map(fn (EurodeskProject $eurodeskProject) => [
                            'id' => $eurodeskProject->id,
                            'title' => $eurodeskProject->title,
                            'partner_organizations' => $eurodeskProject->partner_organizations ?? [],
                            'grant_amount' => $eurodeskProject->grant_amount,
                            'grant_status' => $eurodeskProject->grant_status,
                            'start_date' => optional($eurodeskProject->start_date)?->toDateString(),
                            'end_date' => optional($eurodeskProject->end_date)?->toDateString(),
                        ])->values()
                        : [],
                    'reward_tiers' => in_array('reward_tiers', $moduleKeys, true)
                        ? $projectRewardTiers->map(fn (RewardTier $tier) => [
                            'id' => $tier->id,
                            'name' => $tier->name,
                            'description' => $tier->description,
                            'min_badges' => (int) $tier->min_badges,
                            'min_credits' => (int) $tier->min_credits,
                            'reward_description' => $tier->reward_description,
                            'eligible' => $badgeCount >= (int) $tier->min_badges && (int) $participation->credit >= (int) $tier->min_credits,
                        ])->values()
                        : [],
                    'reward_progress' => in_array('reward_tiers', $moduleKeys, true)
                        ? [
                            'badge_count' => $badgeCount,
                            'credit' => (int) $participation->credit,
                            'eligible_count' => $projectRewardTiers->filter(
                                fn (RewardTier $tier) => $badgeCount >= (int) $tier->min_badges && (int) $participation->credit >= (int) $tier->min_credits
                            )->count(),
                        ]
                        : null,
                    'kademe_modules' => in_array('participants_by_module', $moduleKeys, true)
                        ? ($modulesByProject->get($project->id) ?? collect())->map(function (ProjectModule $module) use ($enrollmentRows) {
                            $enrollment = $enrollmentRows->get($module->id);

                            return [
                                'id' => $module->id,
                                'title' => $module->title,
                                'description' => $module->description,
                                'outcomes' => $module->outcomes ?? [],
                                'instructors' => $module->instructors ?? [],
                                'faq_items' => $module->faq_items ?? [],
                                'warning_text' => $module->warning_text,
                                'requires_consent' => (bool) $module->requires_consent,
                                'consent_checkbox_label' => $module->consent_checkbox_label,
                                'application_open' => (bool) $module->application_open,
                                'requires_coordinator_approval' => (bool) $module->requires_coordinator_approval,
                                'enrollment' => $enrollment ? [
                                    'id' => $enrollment->id,
                                    'status' => $enrollment->status,
                                    'consented_at' => optional($enrollment->consented_at)?->toIso8601String(),
                                    'reviewed_at' => optional($enrollment->reviewed_at)?->toIso8601String(),
                                    'note' => $enrollment->note,
                                ] : null,
                            ];
                        })->values()
                        : [],
                ];
            })->values(),
        ]);
    }

    public function enrollKademeModule(Request $request, int $projectId, int $moduleId): JsonResponse
    {
        $user = $request->user();
        $project = Project::query()->findOrFail($projectId);
        abort_unless(ProjectSpecialModuleCatalog::supportsKademeModuleWorkflow($project), 404);

        $module = ProjectModule::query()
            ->where('project_id', $projectId)
            ->where('id', $moduleId)
            ->firstOrFail();

        abort_unless($module->is_active && $module->application_open, 422, 'Bu modul icin basvuru su an kapali.');

        $participant = $this->participationQueryFor($user)
            ->where('project_id', $projectId)
            ->first();

        abort_unless($participant !== null, 403, 'Bu projenin katilimcisi degilsiniz.');

        if (ProjectModuleEnrollment::query()->where('project_module_id', $module->id)->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['module' => 'Bu modul icin zaten kayit bulunuyor.']);
        }

        if ($module->requires_consent) {
            $request->validate([
                'accepted_terms' => 'required|accepted',
            ]);
        }

        $status = $module->requires_coordinator_approval ? 'pending' : 'approved';

        $enrollment = ProjectModuleEnrollment::query()->create([
            'project_module_id' => $module->id,
            'user_id' => $user->id,
            'participant_id' => $participant->id,
            'status' => $status,
            'consented_at' => now(),
        ]);

        return response()->json([
            'message' => $status === 'approved'
                ? 'Modul kaydiniz olusturuldu.'
                : 'Basvurunuz koordinator onayina iletildi.',
            'enrollment' => [
                'id' => $enrollment->id,
                'status' => $enrollment->status,
                'consented_at' => optional($enrollment->consented_at)?->toIso8601String(),
            ],
        ], 201);
    }

    public function badgeLeaderboard(Request $request, int $projectId): JsonResponse
    {
        $user = $request->user();
        $project = Project::query()->findOrFail($projectId);
        abort_unless(ProjectSpecialModuleCatalog::supportsKademeModuleWorkflow($project), 404);

        $viewerParticipant = $this->participationQueryFor($user)
            ->where('project_id', $projectId)
            ->first();

        abort_unless($viewerParticipant !== null, 403, 'Bu siralamayi gorme yetkiniz yok.');

        $projectScope = Collection::make([$projectId]);

        $participants = Participant::query()
            ->where('project_id', $projectId)
            ->where('status', 'active')
            ->with(['user:id,name,surname,profile_photo_path,university,department'])
            ->get();

        $rows = $participants
            ->filter(fn (Participant $p) => $p->user !== null)
            ->map(function (Participant $p) use ($projectScope) {
                $u = $p->user;
                $badgeCount = $this->kademePlusBadgeBaseQuery($u, $projectScope)->count();

                return [
                    'user_id' => $u->id,
                    'display_name' => trim(($u->name ?? '').' '.($u->surname ?? '')),
                    'university' => $u->university,
                    'department' => $u->department,
                    'profile_photo_path' => $u->profile_photo_path,
                    'badge_count' => $badgeCount,
                    'profile_badge_frame' => $this->resolveProfileBadgeFrame($u, $projectScope),
                ];
            })
            ->sort(function (array $a, array $b) {
                if ($a['badge_count'] === $b['badge_count']) {
                    return strcmp($a['display_name'], $b['display_name']);
                }

                return $b['badge_count'] <=> $a['badge_count'];
            })
            ->values();

        $ranked = $rows->map(function (array $row, int $index) {
            return array_merge($row, ['rank' => $index + 1]);
        });

        return response()->json([
            'leaderboard' => $ranked->take(50)->values()->all(),
            'me' => $ranked->firstWhere('user_id', $user->id),
        ]);
    }

    private function kademePlusProjectIdsFromParticipations(Collection $participations): Collection
    {
        return $participations
            ->filter(fn (Participant $participation) => $participation->project !== null)
            ->filter(fn (Participant $participation) => $participation->project && ProjectSpecialModuleCatalog::usesKademePlusStyleBadges($participation->project))
            ->pluck('project_id')
            ->unique()
            ->values();
    }

    private function kademePlusBadgeBaseQuery(User $user, Collection $kademePlusProjectIds)
    {
        return $user->badges()
            ->when(
                $kademePlusProjectIds->isNotEmpty(),
                fn ($query) => $query->where(function ($inner) use ($kademePlusProjectIds) {
                    $inner->whereNull('badges.project_id')->orWhereIn('badges.project_id', $kademePlusProjectIds->all());
                }),
                fn ($query) => $query->whereNull('badges.project_id')
            );
    }

    private function resolveProfileBadgeFrame(User $user, Collection $kademePlusProjectIds): ?string
    {
        $candidates = $this->kademePlusBadgeBaseQuery($user, $kademePlusProjectIds)
            ->whereNotNull('badges.frame_style')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $sorted = $candidates->sortByDesc(fn (Badge $badge) => $this->badgeTierWeight($badge->tier));

        return $sorted->first()?->frame_style;
    }

    private function badgeTierWeight(?string $tier): int
    {
        return match ($tier) {
            'platinum' => 4,
            'gold' => 3,
            'silver' => 2,
            default => 1,
        };
    }
}
