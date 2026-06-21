<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesProjectPeriodContext;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\CreditLog;
use App\Models\Feedback;
use App\Models\Participant;
use App\Models\Program;
use App\Services\CreditService;
use App\Services\PermissionResolver;
use App\Support\FeedbackFormResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FeedbackController extends Controller
{
    use ResolvesProjectPeriodContext;

    public function __construct(
        private readonly CreditService $creditService,
        private readonly PermissionResolver $permissionResolver,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $attendedPrograms = Program::query()
            ->with(['project:id,name,slug,type'])
            ->whereHas('attendances', function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->where('is_valid', true);
            })
            ->where('status', 'completed')
            ->where(function ($query) use ($user) {
                if ($user->role === 'alumni') {
                    $query->where('status', 'completed')
                        ->orWhereJsonContains('target_audience', 'alumni');

                    return;
                }

                $query->whereNull('target_audience')
                    ->orWhereJsonContains('target_audience', 'student');
            })
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
            $questions = FeedbackFormResolver::forProgram($program);

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
                'questions' => $questions,
                'credit_restored' => (bool) $rewardLog,
                'feedback_deadline_at' => optional($deadline)?->toIso8601String(),
                'feedback_open' => ! $submittedFeedback && ($deadline === null || now()->lt($deadline)),
            ];
        })->values();

        return response()->json([
            'questions' => FeedbackFormResolver::defaultQuestions(),
            'programs' => $programs,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'program_id' => 'required|exists:programs,id',
            'responses' => 'required|array',
        ]);

        $user = $request->user();
        $program = Program::query()->findOrFail($validated['program_id']);
        $this->assertPeriodWritable($request, $program->period_id);
        $questions = FeedbackFormResolver::forProgram($program);
        $responseRules = [];

        foreach ($questions as $question) {
            $key = 'responses.'.$question['id'];
            $required = ($question['required'] ?? true) ? 'required' : 'nullable';

            if (($question['type'] ?? null) === 'rating') {
                $responseRules[$key] = [
                    $required,
                    'integer',
                    'min:'.($question['min'] ?? 1),
                    'max:'.($question['max'] ?? 5),
                ];
            } elseif (($question['type'] ?? null) === 'choice') {
                $responseRules[$key] = [
                    $required,
                    'string',
                    Rule::in(array_values($question['options'] ?? [])),
                ];
            } else {
                $responseRules[$key] = [$required, 'string', 'max:2000'];
            }
        }

        Validator::make($request->all(), $responseRules)->validate();

        if ($program->status !== 'completed') {
            return response()->json([
                'message' => 'Degerlendirme formu etkinlik tamamlandiktan sonra acilir.',
            ], 422);
        }

        if (! $program->isTargetedTo($user->role) && ! ($user->role === 'alumni' && $program->status === 'completed')) {
            return response()->json([
                'message' => 'Bu degerlendirme panel turunuz icin acik degil.',
            ], 403);
        }

        $deadline = $this->feedbackDeadline($program);
        if ($deadline !== null && now()->greaterThanOrEqualTo($deadline)) {
            return response()->json([
                'message' => 'Bu oturum icin degerlendirme suresi doldu. Degerlendirme bir sonraki etkinlik bitmeden once gonderilmelidir.',
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
            ->when(
                $program->period_id,
                fn ($query) => $query->where('period_id', $program->period_id),
                fn ($query) => $query->whereNull('period_id')
            )
            ->where(function ($query) {
                $query->where('status', 'active')
                    ->orWhere('graduation_status', 'graduated');
            })
            ->first();

        if (! $participant) {
            return response()->json([
                'message' => 'Bu programa ait aktif veya mezun katilim kaydin bulunamadi.',
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

        $savedFeedback = DB::transaction(function () use ($validated, $program, $anonymousToken, $participant, $user) {
            $created = Feedback::create([
                'program_id' => $program->id,
                'anonymous_token' => $anonymousToken,
                'responses' => $validated['responses'],
                'submitted_at' => now(),
            ]);

            if ($user->role === 'student' && $participant->status === 'active') {
                $this->creditService->restoreOnceForFeedback($participant, $program);
            }

            return $created;
        });

        $participant->refresh();

        return response()->json([
            'message' => $user->role === 'alumni'
                ? 'Degerlendirmen alindi. Mezun etkinliklerinde kredi islemi uygulanmaz.'
                : 'Degerlendirmen alindi ve oturuma ait kredi iadesi uygulandi.',
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
            ->first(['start_at', 'end_at']);

        return $nextProgram?->end_at ?? $nextProgram?->start_at;
    }
}
