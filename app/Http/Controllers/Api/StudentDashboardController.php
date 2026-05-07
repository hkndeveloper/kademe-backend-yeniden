<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Participant;
use App\Models\CreditLog;
use App\Models\Badge;
use App\Models\Certificate;
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
}
