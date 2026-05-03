<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Participant;
use App\Models\CreditLog;
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

        // Kazanılan rozetleri getir
        $badges = $user->badges()->get();

        return response()->json([
            'participations' => $participations,
            'recent_credit_history' => $creditHistory,
            'earned_badges' => $badges,
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
