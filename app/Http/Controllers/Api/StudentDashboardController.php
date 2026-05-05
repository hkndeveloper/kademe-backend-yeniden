<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Participant;
use App\Models\CreditLog;
use App\Models\Badge;
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
}
