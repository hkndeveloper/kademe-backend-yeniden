<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Participant;
use App\Models\CreditLog;
use App\Models\Badge;
use App\Models\Certificate;
use App\Models\EurodeskProject;
use App\Models\Internship;
use App\Models\Mentor;
use App\Models\RewardTier;
use Illuminate\Http\Request;

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

        $kademePlusProjectIds = $participations
            ->filter(fn (Participant $participation) => $participation->project !== null)
            ->filter(function (Participant $participation) {
                $type = mb_strtolower((string) ($participation->project?->type ?? ''));
                $name = mb_strtolower((string) ($participation->project?->name ?? ''));

                return str_contains($type, 'kademe_plus')
                    || str_contains($type, 'kademe+')
                    || str_contains($name, 'kademe plus')
                    || str_contains($name, 'kademe+');
            })
            ->pluck('project_id')
            ->unique()
            ->values();

        // KADEME+ disindaki rozetler ogrenci dashboard'inda gosterilmez.
        $badges = $user->badges()
            ->when(
                $kademePlusProjectIds->isNotEmpty(),
                fn ($query) => $query->where(function ($inner) use ($kademePlusProjectIds) {
                    $inner->whereNull('badges.project_id')->orWhereIn('badges.project_id', $kademePlusProjectIds->all());
                }),
                fn ($query) => $query->whereNull('badges.project_id')
            )
            ->get();

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
            'total_score' => $participations->sum('credit')
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
                'full_name' => trim(($user->name ?? '') . ' ' . ($user->surname ?? '')),
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

        return response()->json([
            'projects' => $participations->map(function (Participant $participation) use (
                $internshipsByParticipant,
                $mentorsByProject,
                $eurodeskByProject,
                $rewardTiersByProject,
                $badgeCountsByProject
            ) {
                $project = $participation->project;
                $moduleKeys = $this->projectModuleKeys($project?->type, $project?->name, $project?->slug);
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
                ];
            })->values(),
        ]);
    }

    private function projectModuleKeys(?string $type, ?string $name, ?string $slug): array
    {
        $text = mb_strtolower(trim(implode(' ', array_filter([$type, $name, $slug]))));

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
}
