<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\Participant;
use Illuminate\Http\Request;

class ProgramController extends Controller
{
    /**
     * Öğrencinin aktif olarak katıldığı projelerdeki yaklaşan/aktif programları getirir
     */
    public function myPrograms(Request $request)
    {
        $user = $request->user();

        // Öğrencinin katıldığı (kabul edildiği) aktif proje dönemlerini bul
        $participantIds = Participant::where('user_id', $user->id)
            ->where('status', 'active')
            ->pluck('project_id');

        $programs = Program::whereIn('project_id', $participantIds)
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

        return response()->json([
            'program' => $program
        ]);
    }
}
