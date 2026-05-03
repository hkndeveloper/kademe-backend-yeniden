<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\Participant;
use Illuminate\Http\Request;

class ProgramController extends Controller
{
    private function participationScope($query, $user)
    {
        return $query->where(function ($builder) use ($user) {
            $builder->where('status', 'active');

            if ($user->role === 'alumni') {
                $builder->orWhere('graduation_status', 'graduated')
                    ->orWhereNotNull('graduated_at');
            }
        });
    }

    /**
     * Öğrencinin aktif olarak katıldığı projelerdeki yaklaşan/aktif programları getirir
     */
    public function myPrograms(Request $request)
    {
        $user = $request->user();
        abort_unless($user->role === 'student', 403, 'Program takvimi yalnizca ogrenci paneli icin kullanilabilir.');

        // Öğrencinin katıldığı (kabul edildiği) aktif proje dönemlerini bul
        $participations = $this->participationScope(Participant::where('user_id', $user->id), $user)
            ->get(['project_id', 'period_id']);

        $programs = Program::whereIn('project_id', $participations->pluck('project_id'))
            ->whereIn('period_id', $participations->pluck('period_id'))
            ->whereIn('status', ['scheduled', 'active'])
            ->orderBy('start_at', 'asc')
            ->get();

        return response()->json([
            'programs' => $programs
        ]);
    }

    /**
     * Etkinlik detayı
     */
    public function show($id, Request $request)
    {
        $program = Program::with(['project'])->findOrFail($id);
        $user = $request->user();
        abort_unless($user->role === 'student', 403, 'Program takvimi yalnizca ogrenci paneli icin kullanilabilir.');

        $canView = $this->participationScope(
            Participant::query()
                ->where('user_id', $user->id)
                ->where('project_id', $program->project_id)
                ->where('period_id', $program->period_id),
            $user
        )
            ->exists();

        abort_unless($canView, 403, 'Bu etkinligi goruntuleme yetkiniz bulunmuyor.');

        return response()->json([
            'program' => $program
        ]);
    }
}
