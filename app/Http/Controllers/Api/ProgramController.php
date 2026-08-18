<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\CreditLog;
use App\Models\Feedback;
use App\Models\Participant;
use App\Models\Program;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * @group Programs & Attendance
 */
class ProgramController extends Controller
{
    private function shouldIncludeGraduatedParticipations($user): bool
    {
        return $user->role === 'alumni';
    }

    private function participationScope($query, $user)
    {
        return $query->where(function ($builder) use ($user) {
            $builder->where('status', 'active');

            if ($this->shouldIncludeGraduatedParticipations($user)) {
                $builder->orWhere('graduation_status', 'graduated')
                    ->orWhereNotNull('graduated_at');
            }
        });
    }

    /**
     * List participant programs.
     *
     * Requires permission: `participant.programs.view`. Lists programs for the current user active/graduated participations with attendance, credit, and feedback state.
     *
     * @group Programs & Attendance
     *
     * @authenticated
     *
     * @response 200 {"programs":[{"id":1,"title":"Liderlik Atolyesi","status":"scheduled","attendance_status":"pending","credit":{"deducted":false,"net_amount":0},"feedback_submitted":false,"project":{"id":1,"name":"KADEME"}}]}
     * @response 401 {"message":"Unauthenticated."}
     * @response 403 {"message":"This action is unauthorized."}
     */
    public function myPrograms(Request $request)
    {
        $user = $request->user();

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
            ->where(function ($query) use ($user) {
                if ($user->role === 'alumni') {
                    $query->whereJsonContains('target_audience', 'alumni');

                    return;
                }

                $query->whereNull('target_audience')
                    ->orWhereJsonContains('target_audience', 'student');
            })
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
                $participant = $participations
                    ->where('project_id', $program->project_id)
                    ->where('period_id', $program->period_id)
                    ->first();
                $feedbackSubmitted = in_array($feedbackTokensByProgram->get($program->id), $submittedFeedbackTokens, true);

                return $this->participantProgramPayload(
                    $program,
                    $participant,
                    $attendance,
                    $logs,
                    $feedbackSubmitted
                );
            })->values(),
        ]);
    }

    /**
     * Get participant program detail.
     *
     * Requires permission: `participant.programs.view`. The program must belong to one of the current user participations and must target the user role.
     *
     * @group Programs & Attendance
     *
     * @authenticated
     *
     * @urlParam id integer required Program id. Example: 1
     *
     * @response 200 {"program":{"id":1,"title":"Liderlik Atolyesi","status":"active","location":"Istanbul","project":{"id":1,"name":"KADEME"}}}
     * @response 403 {"message":"Bu etkinligi goruntuleme yetkiniz bulunmuyor."}
     * @response 404 {"message":"No query results for model [App\\Models\\Program]."}
     */
    public function show($id, Request $request)
    {
        $program = Program::with(['project:id,name,slug,type', 'period:id,name'])->findOrFail($id);
        $user = $request->user();

        $participant = $this->participationScope(
            Participant::query()
                ->where('user_id', $user->id)
                ->where('project_id', $program->project_id)
                ->where('period_id', $program->period_id),
            $user
        )->first(['id', 'user_id', 'project_id', 'period_id', 'credit']);

        abort_unless(
            $program->isTargetedTo($user->role),
            403,
            'Bu etkinligi goruntuleme yetkiniz bulunmuyor.'
        );

        abort_unless($participant, 403, 'Bu etkinligi goruntuleme yetkiniz bulunmuyor.');

        $attendance = Attendance::query()
            ->where('user_id', $user->id)
            ->where('program_id', $program->id)
            ->latest()
            ->first();
        $creditLogs = CreditLog::query()
            ->where('user_id', $user->id)
            ->where('program_id', $program->id)
            ->orderBy('created_at')
            ->get();
        $feedbackToken = hash('sha256', sprintf('%s:%s:%s', $user->id, $program->id, config('app.key')));
        $feedbackSubmitted = Feedback::query()
            ->where('program_id', $program->id)
            ->where('anonymous_token', $feedbackToken)
            ->exists();

        return response()->json([
            'program' => $this->participantProgramPayload(
                $program,
                $participant,
                $attendance,
                $creditLogs,
                $feedbackSubmitted
            ),
        ]);
    }

    private function participantProgramPayload(
        Program $program,
        ?Participant $participant,
        ?Attendance $attendance,
        Collection $logs,
        bool $feedbackSubmitted
    ): array {
        $deduction = $logs->firstWhere('type', 'deduction');
        $restoreAmount = (int) $logs->where('amount', '>', 0)->sum('amount');
        $netAmount = (int) $logs->sum('amount');
        $creditRestored = (bool) $deduction && $netAmount >= 0;

        return [
            'id' => $program->id,
            'title' => $program->title,
            'description' => $program->description,
            'location' => $program->location,
            'location_place_name' => $program->location_place_name,
            'location_place_address' => $program->location_place_address,
            'location_place_id' => $program->location_place_id,
            'location_place_provider' => $program->location_place_provider,
            'latitude' => $program->latitude,
            'longitude' => $program->longitude,
            'radius_meters' => $program->radius_meters,
            'start_at' => optional($program->start_at)?->toIso8601String(),
            'end_at' => optional($program->end_at)?->toIso8601String(),
            'status' => $program->status,
            'credit_deduction' => $program->credit_deduction,
            'target_audience' => $program->targetAudience(),
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
                'restored' => $creditRestored,
                'restore_amount' => $creditRestored ? $restoreAmount : 0,
                'restored_at' => optional($logs->where('amount', '>', 0)->last()?->created_at)?->toIso8601String(),
                'net_amount' => $netAmount,
            ],
            'feedback_submitted' => $feedbackSubmitted,
        ];
    }
}
