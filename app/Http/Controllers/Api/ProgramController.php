<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\CreditLog;
use App\Models\Feedback;
use App\Models\Participant;
use App\Models\Program;
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
     * Ogrencinin aktif projelerindeki programlari kendi yoklama ve kredi durumuyla getirir.
     */
    public function myPrograms(Request $request)
    {
        $user = $request->user();
        abort_unless($user->role === 'student', 403, 'Program takvimi yalnizca ogrenci paneli icin kullanilabilir.');

        $participations = $this->participationScope(Participant::where('user_id', $user->id), $user)
            ->with(['project:id,name,slug,type', 'period:id,name'])
            ->get(['id', 'user_id', 'project_id', 'period_id', 'credit']);

        if ($participations->isEmpty()) {
            return response()->json(['programs' => []]);
        }

        $projectIds = $participations->pluck('project_id')->filter()->unique()->values();
        $periodIds = $participations->pluck('period_id')->filter()->unique()->values();

        $programs = Program::query()
            ->with(['project:id,name,slug,type', 'period:id,name'])
            ->whereIn('project_id', $projectIds)
            ->whereIn('period_id', $periodIds)
            ->whereIn('status', ['scheduled', 'active', 'completed'])
            ->orderByDesc('start_at')
            ->get();

        $programIds = $programs->pluck('id')->all();
        $attendances = Attendance::query()
            ->where('user_id', $user->id)
            ->whereIn('program_id', $programIds)
            ->latest()
            ->get()
            ->keyBy('program_id');

        $creditLogs = CreditLog::query()
            ->where('user_id', $user->id)
            ->whereIn('program_id', $programIds)
            ->orderBy('created_at')
            ->get()
            ->groupBy('program_id');

        $feedbackTokensByProgram = $programs->mapWithKeys(fn (Program $program) => [
            $program->id => hash('sha256', sprintf('%s:%s:%s', $user->id, $program->id, config('app.key'))),
        ]);
        $submittedFeedbackTokens = Feedback::query()
            ->whereIn('program_id', $programIds)
            ->whereIn('anonymous_token', $feedbackTokensByProgram->values())
            ->pluck('anonymous_token')
            ->all();

        return response()->json([
            'programs' => $programs->map(function (Program $program) use ($attendances, $creditLogs, $participations, $feedbackTokensByProgram, $submittedFeedbackTokens) {
                $attendance = $attendances->get($program->id);
                $logs = $creditLogs->get($program->id, collect());
                $deduction = $logs->firstWhere('type', 'deduction');
                $restore = $logs->firstWhere('type', 'restore');
                $participant = $participations
                    ->where('project_id', $program->project_id)
                    ->where('period_id', $program->period_id)
                    ->first();
                $feedbackSubmitted = in_array($feedbackTokensByProgram->get($program->id), $submittedFeedbackTokens, true);

                return [
                    'id' => $program->id,
                    'title' => $program->title,
                    'description' => $program->description,
                    'location' => $program->location,
                    'start_at' => optional($program->start_at)?->toIso8601String(),
                    'end_at' => optional($program->end_at)?->toIso8601String(),
                    'status' => $program->status,
                    'credit_deduction' => $program->credit_deduction,
                    'project' => $program->project ? [
                        'id' => $program->project->id,
                        'name' => $program->project->name,
                        'slug' => $program->project->slug,
                        'type' => $program->project->type,
                    ] : null,
                    'period' => $program->period ? [
                        'id' => $program->period->id,
                        'name' => $program->period->name,
                    ] : null,
                    'participation' => $participant ? [
                        'id' => $participant->id,
                        'credit' => $participant->credit,
                    ] : null,
                    'attendance' => $attendance ? [
                        'id' => $attendance->id,
                        'method' => $attendance->method,
                        'is_valid' => (bool) $attendance->is_valid,
                        'recorded_at' => optional($attendance->created_at)?->toIso8601String(),
                    ] : null,
                    'attendance_status' => $attendance
                        ? ((bool) $attendance->is_valid ? 'present' : 'invalid')
                        : ($program->status === 'completed' ? 'absent' : 'pending'),
                    'credit' => [
                        'deducted' => (bool) $deduction,
                        'deduction_amount' => $deduction ? abs((int) $deduction->amount) : 0,
                        'deducted_at' => optional($deduction?->created_at)?->toIso8601String(),
                        'restored' => (bool) $restore,
                        'restore_amount' => $restore ? (int) $restore->amount : 0,
                        'restored_at' => optional($restore?->created_at)?->toIso8601String(),
                        'net_amount' => (int) $logs->sum('amount'),
                    ],
                    'feedback_submitted' => $feedbackSubmitted,
                ];
            })->values(),
        ]);
    }

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
        )->exists();

        abort_unless($canView, 403, 'Bu etkinligi goruntuleme yetkiniz bulunmuyor.');

        return response()->json([
            'program' => $program,
        ]);
    }
}
