<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\CreditLog;
use App\Models\Feedback;
use App\Models\Participant;
use App\Models\Program;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FeedbackController extends Controller
{
    private function questions(): array
    {
        return [
            [
                'id' => 'content_quality',
                'label' => 'Oturumun icerik kalitesini nasil degerlendiriyorsun?',
                'type' => 'rating',
                'min' => 1,
                'max' => 5,
            ],
            [
                'id' => 'speaker_quality',
                'label' => 'Konusmaci veya yuruten ekip faydali miydi?',
                'type' => 'rating',
                'min' => 1,
                'max' => 5,
            ],
            [
                'id' => 'organization_quality',
                'label' => 'Oturum organizasyonu ve akisindan memnun kaldin mi?',
                'type' => 'rating',
                'min' => 1,
                'max' => 5,
            ],
            [
                'id' => 'comment',
                'label' => 'Eklemek istedigin gorus veya oneriler',
                'type' => 'text',
                'required' => false,
            ],
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role === 'student', 403, 'Degerlendirme yalnizca ogrenci paneli icin kullanilabilir.');

        $attendedPrograms = Program::query()
            ->with(['project:id,name,slug,type'])
            ->whereHas('attendances', function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->where('is_valid', true);
            })
            ->where('status', 'completed')
            ->orderByDesc('start_at')
            ->get();

        $programs = $attendedPrograms->map(function (Program $program) use ($user) {
            $token = hash('sha256', sprintf('%s:%s:%s', $user->id, $program->id, config('app.key')));
            $submittedFeedback = Feedback::query()
                ->where('program_id', $program->id)
                ->where('anonymous_token', $token)
                ->first();

            $rewardLog = CreditLog::query()
                ->where('user_id', $user->id)
                ->where('program_id', $program->id)
                ->where('type', 'restore')
                ->latest()
                ->first();
            $deadline = $this->feedbackDeadline($program);

            return [
                'id' => $program->id,
                'title' => $program->title,
                'start_at' => optional($program->start_at)?->toIso8601String(),
                'status' => $program->status,
                'credit_deduction' => $program->credit_deduction,
                'project' => $program->project ? [
                    'id' => $program->project->id,
                    'name' => $program->project->name,
                    'slug' => $program->project->slug,
                    'type' => $program->project->type,
                ] : null,
                'feedback_submitted' => (bool) $submittedFeedback,
                'submitted_at' => optional($submittedFeedback?->submitted_at)?->toIso8601String(),
                'anonymous_feedback_id' => Feedback::usesPublicIdColumn() ? $submittedFeedback?->public_id : null,
                'credit_restored' => (bool) $rewardLog,
                'feedback_deadline_at' => optional($deadline)?->toIso8601String(),
                'feedback_open' => ! $submittedFeedback && ($deadline === null || now()->lt($deadline)),
            ];
        })->values();

        return response()->json([
            'questions' => $this->questions(),
            'programs' => $programs,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'program_id' => 'required|exists:programs,id',
            'responses' => 'required|array',
            'responses.content_quality' => 'required|integer|min:1|max:5',
            'responses.speaker_quality' => 'required|integer|min:1|max:5',
            'responses.organization_quality' => 'required|integer|min:1|max:5',
            'responses.comment' => 'nullable|string|max:2000',
        ]);

        $user = $request->user();
        abort_unless($user->role === 'student', 403, 'Degerlendirme yalnizca ogrenci paneli icin kullanilabilir.');
        $program = Program::query()->findOrFail($validated['program_id']);

        if ($program->status !== 'completed') {
            return response()->json([
                'message' => 'Degerlendirme formu etkinlik tamamlandiktan sonra acilir.',
            ], 422);
        }

        $deadline = $this->feedbackDeadline($program);
        if ($deadline !== null && now()->greaterThanOrEqualTo($deadline)) {
            return response()->json([
                'message' => 'Bu oturum icin degerlendirme suresi doldu. Degerlendirme bir sonraki etkinlik baslamadan once gonderilmelidir.',
            ], 422);
        }

        $attendance = Attendance::query()
            ->where('program_id', $program->id)
            ->where('user_id', $user->id)
            ->where('is_valid', true)
            ->first();

        if (! $attendance) {
            return response()->json([
                'message' => 'Sadece gecerli yoklamasi alinmis oturumlar icin degerlendirme gonderebilirsin.',
            ], 422);
        }

        $participant = Participant::query()
            ->where('user_id', $user->id)
            ->where('project_id', $program->project_id)
            ->where('period_id', $program->period_id)
            ->where('status', 'active')
            ->first();

        if (! $participant) {
            return response()->json([
                'message' => 'Bu programa ait aktif katilim kaydin bulunamadi.',
            ], 422);
        }

        $anonymousToken = hash('sha256', sprintf('%s:%s:%s', $user->id, $program->id, config('app.key')));

        $existingFeedback = Feedback::query()
            ->where('program_id', $program->id)
            ->where('anonymous_token', $anonymousToken)
            ->first();

        if ($existingFeedback) {
            return response()->json([
                'message' => 'Bu oturum icin degerlendirme zaten gonderilmis.',
            ], 422);
        }

        $creditAmount = max((int) ($program->credit_deduction ?? 10), 0);

        $savedFeedback = DB::transaction(function () use ($validated, $program, $anonymousToken, $participant, $user, $creditAmount) {
            $created = Feedback::create([
                'program_id' => $program->id,
                'anonymous_token' => $anonymousToken,
                'responses' => $validated['responses'],
                'submitted_at' => now(),
            ]);

            $rewardExists = CreditLog::query()
                ->where('user_id', $user->id)
                ->where('program_id', $program->id)
                ->where('type', 'restore')
                ->exists();

            $deductionExists = CreditLog::query()
                ->where('participant_id', $participant->id)
                ->where('user_id', $user->id)
                ->where('program_id', $program->id)
                ->where('type', 'deduction')
                ->exists();

            if (! $rewardExists && $deductionExists && $creditAmount > 0) {
                CreditLog::create([
                    'participant_id' => $participant->id,
                    'user_id' => $user->id,
                    'project_id' => $program->project_id,
                    'period_id' => $program->period_id,
                    'program_id' => $program->id,
                    'amount' => $creditAmount,
                    'type' => 'restore',
                    'reason' => 'Oturum degerlendirmesi tamamlandi',
                ]);

                $participant->increment('credit', $creditAmount);
            }

            return $created;
        });

        $participant->refresh();

        return response()->json([
            'message' => 'Degerlendirmen alindi ve oturuma ait kredi iadesi uygulandi.',
            'current_credit' => $participant->credit,
            'anonymous_feedback_id' => Feedback::usesPublicIdColumn() ? $savedFeedback->public_id : null,
        ], 201);
    }

    private function feedbackDeadline(Program $program): ?\Illuminate\Support\Carbon
    {
        if (! $program->start_at) {
            return null;
        }

        $nextProgram = Program::query()
            ->where('project_id', $program->project_id)
            ->when(
                $program->period_id,
                fn ($query) => $query->where('period_id', $program->period_id),
                fn ($query) => $query->whereNull('period_id')
            )
            ->where('id', '!=', $program->id)
            ->where('start_at', '>', $program->start_at)
            ->orderBy('start_at')
            ->first(['start_at']);

        return $nextProgram?->start_at;
    }
}
