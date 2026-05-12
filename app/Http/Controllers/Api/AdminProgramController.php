<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\CreditLog;
use App\Models\Feedback;
use App\Models\Participant;
use App\Models\Program;
use App\Models\Project;
use App\Support\AdminExportResponder;
use App\Models\User;
use App\Services\CreditService;
use App\Services\GoogleCalendarService;
use App\Services\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminProgramController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver,
        private readonly CreditService $creditService
    ) {
    }

    private function canManageProject(User $user, Project $project, string $permission): bool
    {
        return $this->permissionResolver->canAccessProject($user, $permission, $project->id);
    }

    private function abortIfUnauthorized(User $user, Project $project, string $permission): void
    {
        abort_unless($this->canManageProject($user, $project, $permission), 403, 'Bu projeyi yonetme yetkiniz yok.');
    }

    private function assertPeriodBelongsToProject(Project $project, int $periodId): void
    {
        if (! $project->periods()->whereKey($periodId)->exists()) {
            throw ValidationException::withMessages([
                'period_id' => ['Secilen donem bu projeye ait degil.'],
            ]);
        }
    }

    private function assertNoProgramTimeConflict(string $startAt, string $endAt, ?int $ignoreProgramId = null): void
    {
        $start = Carbon::parse($startAt);
        $end = Carbon::parse($endAt);

        $conflictingProgram = Program::query()
            ->with('project:id,name')
            ->where('status', '!=', 'cancelled')
            ->when($ignoreProgramId, fn ($query) => $query->whereKeyNot($ignoreProgramId))
            ->where('start_at', '<', $end)
            ->where('end_at', '>', $start)
            ->orderBy('start_at')
            ->first();

        if (! $conflictingProgram) {
            return;
        }

        $projectName = $conflictingProgram->project?->name ?? 'Baska proje';
        $start = optional($conflictingProgram->start_at)?->format('d.m.Y H:i');
        $end = optional($conflictingProgram->end_at)?->format('d.m.Y H:i');

        throw ValidationException::withMessages([
            'start_at' => ["Secilen saat araligi {$projectName} / {$conflictingProgram->title} programiyla cakisiyor ({$start} - {$end})."],
        ]);
    }

    /**
     * Projeye ait etkinlikleri listele.
     */
    public function index(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'programs.view');
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
        ]);

        $project = Project::findOrFail($validated['project_id']);
        $this->abortIfUnauthorized($request->user(), $project, 'programs.view');

        $query = Program::query()
            ->with(['project:id,name', 'period:id,name'])
            ->where('project_id', $project->id)
            ->orderBy('start_at', 'desc');

        if ($this->permissionResolver->hasPermission($request->user(), 'programs.attendance.view')) {
            $query->withCount([
                'attendances as attendance_count',
                'feedbacks as feedback_count',
            ]);
        }

        $programs = $query->get();

        return response()->json(['programs' => $programs]);
    }

    public function export(Request $request)
    {
        $this->abortUnlessAllowed($request, 'programs.export');
        $query = Program::query()->with(['project:id,name', 'period:id,name']);

        if ($request->filled('project_id')) {
            $project = Project::findOrFail($request->integer('project_id'));
            $this->abortIfUnauthorized($request->user(), $project, 'programs.export');
            $query->where('project_id', $project->id);
        } elseif (! $this->permissionResolver->hasGlobalScope($request->user(), 'programs.export')) {
            $manageableProjectIds = $this->permissionResolver->projectIdsForPermission($request->user(), 'programs.export');
            $query->whereIn('project_id', $manageableProjectIds);
        }

        $programs = $query->orderByDesc('start_at')->get();
        $headings = ['ID', 'Proje', 'Donem', 'Baslik', 'Konum', 'Baslangic', 'Bitis', 'Durum', 'Yoklama Capi', 'Kredi Dusumu', 'Basvuru Kontenjani'];
        $rows = $programs->map(fn (Program $program) => [
            $program->id,
            $program->project?->name ?? '-',
            $program->period?->name ?? '-',
            $program->title,
            $program->location ?? '-',
            optional($program->start_at)?->format('d.m.Y H:i') ?? '-',
            optional($program->end_at)?->format('d.m.Y H:i') ?? '-',
            $program->status ?? '-',
            $program->radius_meters ?? '-',
            $program->credit_deduction ?? '-',
            $program->application_quota ?? '-',
        ])->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'programlar_' . now()->format('Ymd_His'),
            'Programlar',
            $headings,
            $rows,
        );
    }

    /**
     * Yeni etkinlik olustur.
     */
    public function store(Request $request, GoogleCalendarService $googleCalendar): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'programs.create');
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'period_id' => 'required|exists:periods,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'location' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'radius_meters' => 'required|integer|min:10',
            'guest_info' => 'nullable|array',
            'start_at' => 'required|date',
            'end_at' => 'required|date|after:start_at',
            'credit_deduction' => 'required|integer|min:0',
            'application_quota' => 'nullable|integer|min:1',
        ]);

        $project = Project::findOrFail($validated['project_id']);
        $this->abortIfUnauthorized($request->user(), $project, 'programs.create');
        $this->assertPeriodBelongsToProject($project, (int) $validated['period_id']);
        $this->assertNoProgramTimeConflict($validated['start_at'], $validated['end_at']);

        $program = Program::create(array_merge($validated, [
            'created_by' => $request->user()->id,
            'status' => 'scheduled',
        ]));

        try {
            $googleCalendar->syncProgram($program->fresh(['project:id,name', 'period:id,name']));
        } catch (\Throwable $throwable) {
            Log::warning('Program Google Calendar senkronizasyonu store sirasinda basarisiz oldu.', [
                'program_id' => $program->id,
                'message' => $throwable->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Etkinlik basariyla planlandi.',
            'program' => $program->fresh(['project:id,name', 'period:id,name']),
        ], 201);
    }

    /**
     * Etkinlik guncelle.
     */
    public function update(Request $request, int $id, GoogleCalendarService $googleCalendar): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'programs.update');
        $program = Program::with('project')->findOrFail($id);
        $this->abortIfUnauthorized($request->user(), $program->project, 'programs.update');

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'location' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'radius_meters' => 'required|integer|min:10',
            'guest_info' => 'nullable|array',
            'start_at' => 'required|date',
            'end_at' => 'required|date|after:start_at',
            'credit_deduction' => 'required|integer|min:0',
            'application_quota' => 'nullable|integer|min:1',
            'status' => 'required|in:scheduled,active,completed,cancelled',
        ]);

        if (in_array($validated['status'], ['completed', 'cancelled'], true)) {
            $validated['qr_token'] = null;
            $validated['qr_expires_at'] = null;
        }

        if ($validated['status'] !== 'cancelled') {
            $this->assertNoProgramTimeConflict($validated['start_at'], $validated['end_at'], $program->id);
        }

        $program->update($validated);

        try {
            $googleCalendar->syncProgram($program->fresh(['project:id,name', 'period:id,name']));
        } catch (\Throwable $throwable) {
            Log::warning('Program Google Calendar senkronizasyonu update sirasinda basarisiz oldu.', [
                'program_id' => $program->id,
                'message' => $throwable->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Program guncellendi.',
            'program' => $program->fresh(['project:id,name', 'period:id,name']),
        ]);
    }

    /**
     * Dinamik QR kod uret.
     */
    public function generateQr(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'programs.qr.manage');
        $program = Program::with('project')->findOrFail($id);
        $this->abortIfUnauthorized($request->user(), $program->project, 'programs.qr.manage');
        abort_if(
            in_array($program->status, ['completed', 'cancelled'], true),
            422,
            'Tamamlanan veya iptal edilen program icin QR yoklama baslatilamaz.'
        );

        $validated = $request->validate([
            'rotation_seconds' => 'nullable|integer|min:10|max:120',
        ]);

        $qrToken = 'prg_' . $program->id . '_' . Str::random(12);
        $rotationSeconds = (int) ($validated['rotation_seconds'] ?? 30);
        $expiresAt = now()->addSeconds($rotationSeconds);

        $program->update([
            'status' => 'active',
            'qr_token' => $qrToken,
            'qr_expires_at' => $expiresAt,
            'qr_rotation_seconds' => $rotationSeconds,
        ]);

        return response()->json([
            'qr_token' => $qrToken,
            'expires_at' => $expiresAt,
            'refresh_in_seconds' => $rotationSeconds,
        ]);
    }

    /**
     * Etkinligi tamamla.
     */
    public function complete(Request $request, int $id, GoogleCalendarService $googleCalendar): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'programs.complete');
        $program = Program::with('project')->findOrFail($id);
        $this->abortIfUnauthorized($request->user(), $program->project, 'programs.complete');

        $creditDeduction = max((int) ($program->credit_deduction ?? 0), 0);
        $deductedCount = 0;

        DB::transaction(function () use ($program, $request, $creditDeduction, &$deductedCount) {
            $program->update([
                'status' => 'completed',
                'qr_token' => null,
                'qr_expires_at' => null,
            ]);

            if ($creditDeduction === 0) {
                return;
            }

            // Etkinlik tamamlandığında TÜM aktif katılımcılardan kredi düşümü yapılır.
            // Yoklamaya gelip değerlendirme formunu dolduranlar krediyi geri kazanır (restore).
            // Yoklamaya gelmeyenler krediyi kaybeder ama sonraki etkinliklere katılabilir.
            $attendedUserIds = \App\Models\Attendance::query()
                ->where('program_id', $program->id)
                ->where('is_valid', true)
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $participants = Participant::query()
                ->where('project_id', $program->project_id)
                ->where('status', 'active')
                ->when(
                    $program->period_id,
                    fn ($query) => $query->where('period_id', $program->period_id),
                    fn ($query) => $query->whereNull('period_id')
                )
                ->get();

            foreach ($participants as $participant) {
                $attended = in_array((int) $participant->user_id, $attendedUserIds, true);
                $reason = $attended
                    ? 'Etkinlik tamamlandi, degerlendirme bekleniyor'
                    : 'Etkinlige katilim saglanmadi, kredi dusumu uygulandi';

                $log = $this->creditService->deductOnceForProgram(
                    $participant,
                    $program,
                    $request->user()->id,
                    $reason
                );

                if ($log !== null) {
                    $deductedCount++;
                }
            }
        });

        try {
            $googleCalendar->syncProgram($program->fresh(['project:id,name', 'period:id,name']));
        } catch (\Throwable $throwable) {
            Log::warning('Program Google Calendar senkronizasyonu complete sirasinda basarisiz oldu.', [
                'program_id' => $program->id,
                'message' => $throwable->getMessage(),
            ]);
        }

        $request->attributes->set('audit.subject', $program);
        $request->attributes->set('audit.event', 'program.completed');
        $request->attributes->set('audit.description', 'program.completed');
        $request->attributes->set('audit.properties', [
            'operation' => 'program_complete',
            'project_id' => $program->project_id,
            'period_id' => $program->period_id,
            'program_id' => $program->id,
            'program_title' => $program->title,
            'credit_deduction' => $creditDeduction,
            'deducted_participant_count' => $deductedCount,
            'status_after' => 'completed',
        ]);

        return response()->json([
            'message' => 'Etkinlik ve yoklama alimi basariyla sonlandirildi.',
            'deducted_participant_count' => $deductedCount,
        ]);
    }

    public function attendanceDetails(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'programs.attendance.view');
        $program = Program::with(['project:id,name', 'period:id,name'])->findOrFail($id);
        $this->abortIfUnauthorized($request->user(), $program->project, 'programs.attendance.view');

        $attendances = Attendance::query()
            ->with(['user:id,name,surname,email', 'program:id,title'])
            ->where('program_id', $program->id)
            ->orderByDesc('created_at')
            ->get();
        $participants = Participant::query()
            ->with('user:id,name,surname,email')
            ->where('project_id', $program->project_id)
            ->when(
                $program->period_id,
                fn ($query) => $query->where('period_id', $program->period_id),
                fn ($query) => $query->whereNull('period_id')
            )
            ->where('status', 'active')
            ->orderBy('id')
            ->get();
        $attendanceByUserId = $attendances->keyBy('user_id');

        $creditLogsByUserId = CreditLog::query()
            ->where('program_id', $program->id)
            ->get()
            ->groupBy('user_id');
        $deductedUserIds = $creditLogsByUserId
            ->filter(fn ($logs) => $logs->firstWhere('type', 'deduction') !== null)
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
        $restoredUserIds = $creditLogsByUserId
            ->filter(fn ($logs) => $logs->firstWhere('type', 'deduction') !== null && (int) $logs->sum('amount') >= 0)
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
        $feedbackCount = Feedback::query()->where('program_id', $program->id)->count();

        return response()->json([
            'program' => [
                'id' => $program->id,
                'title' => $program->title,
                'project' => $program->project?->name,
                'period' => $program->period?->name,
                'start_at' => optional($program->start_at)?->toIso8601String(),
                'status' => $program->status,
            ],
            'summary' => [
                'attendance_count' => $attendances->count(),
                'participant_count' => $participants->count(),
                'absent_count' => max($participants->count() - $attendances->where('is_valid', true)->count(), 0),
                'feedback_count' => $feedbackCount,
                'deduction_count' => count($deductedUserIds),
                'restore_count' => count($restoredUserIds),
            ],
            'records' => $participants->map(function (Participant $participant) use ($attendanceByUserId, $restoredUserIds, $deductedUserIds) {
                $uid = (int) $participant->user_id;
                $attendance = $attendanceByUserId->get($uid);
                $isValid = (bool) ($attendance?->is_valid ?? false);
                $creditDeducted = in_array($uid, $deductedUserIds, true);
                $creditRestored = in_array($uid, $restoredUserIds, true);

                return [
                    'id' => $attendance?->id,
                    'participant_id' => $participant->id,
                    'student' => $participant->user ? trim($participant->user->name . ' ' . $participant->user->surname) : 'Silinmis kullanici',
                    'email' => $participant->user?->email,
                    'method' => $attendance?->method,
                    'is_valid' => $isValid,
                    'attendance_status' => $isValid ? 'present' : 'absent',
                    'latitude' => $attendance?->latitude,
                    'longitude' => $attendance?->longitude,
                    'feedback_submitted' => $creditRestored,
                    'credit_deducted' => $creditDeducted,
                    'credit_restored' => $creditRestored,
                    'recorded_at' => optional($attendance?->created_at)?->toIso8601String(),
                ];
            })->values(),
        ]);
    }

    public function markManualAttendance(Request $request, int $id, int $participantId): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'programs.attendance.manage');
        $program = Program::with(['project:id,name', 'period:id,name'])->findOrFail($id);
        $this->abortIfUnauthorized($request->user(), $program->project, 'programs.attendance.manage');

        $validated = $request->validate([
            'is_valid' => 'required|boolean',
            'manual_note' => 'nullable|string|max:1000',
        ]);

        $participant = Participant::query()
            ->where('id', $participantId)
            ->where('project_id', $program->project_id)
            ->when(
                $program->period_id,
                fn ($query) => $query->where('period_id', $program->period_id),
                fn ($query) => $query->whereNull('period_id')
            )
            ->where('status', 'active')
            ->firstOrFail();

        $attendance = DB::transaction(function () use ($program, $participant, $validated, $request) {
            $attendance = Attendance::query()->updateOrCreate(
                [
                    'program_id' => $program->id,
                    'user_id' => $participant->user_id,
                ],
                [
                    'method' => 'manual',
                    'is_valid' => (bool) $validated['is_valid'],
                    'manual_note' => $validated['manual_note'] ?? null,
                    'recorded_by' => $request->user()->id,
                    'latitude' => null,
                    'longitude' => null,
                ]
            );

            $this->creditService->reconcileCompletedProgramAttendance(
                $program,
                $participant,
                (bool) $validated['is_valid'],
                $request->user()->id
            );

            return $attendance;
        });

        $request->attributes->set('audit.subject', $program);
        $request->attributes->set('audit.event', 'attendance.manual_updated');
        $request->attributes->set('audit.description', 'attendance.manual_updated');
        $request->attributes->set('audit.properties', [
            'operation' => 'manual_attendance_update',
            'project_id' => $program->project_id,
            'period_id' => $program->period_id,
            'program_id' => $program->id,
            'program_title' => $program->title,
            'participant_id' => $participant->id,
            'student_user_id' => $participant->user_id,
            'attendance_id' => $attendance->id,
            'is_valid' => (bool) $validated['is_valid'],
            'manual_note_present' => ! empty($validated['manual_note']),
            'program_status' => $program->status,
        ]);

        return response()->json([
            'message' => $attendance->is_valid ? 'Yoklama katildi olarak isaretlendi.' : 'Yoklama gelmedi olarak isaretlendi.',
            'attendance' => $attendance,
        ]);
    }

    public function exportAttendanceDetails(Request $request, int $id)
    {
        $this->abortUnlessAllowed($request, 'programs.attendance.export');
        $program = Program::with(['project:id,name', 'period:id,name'])->findOrFail($id);
        $this->abortIfUnauthorized($request->user(), $program->project, 'programs.attendance.export');

        $attendances = Attendance::query()
            ->with('user:id,name,surname,email')
            ->where('program_id', $program->id)
            ->orderByDesc('created_at')
            ->get();
        $participants = Participant::query()
            ->with('user:id,name,surname,email')
            ->where('project_id', $program->project_id)
            ->when(
                $program->period_id,
                fn ($query) => $query->where('period_id', $program->period_id),
                fn ($query) => $query->whereNull('period_id')
            )
            ->where('status', 'active')
            ->orderBy('id')
            ->get();
        $attendanceByUserId = $attendances->keyBy('user_id');

        $creditLogsByUserId = CreditLog::query()
            ->where('program_id', $program->id)
            ->get()
            ->groupBy('user_id');
        $deductedUserIds = $creditLogsByUserId
            ->filter(fn ($logs) => $logs->firstWhere('type', 'deduction') !== null)
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
        $restoredUserIds = $creditLogsByUserId
            ->filter(fn ($logs) => $logs->firstWhere('type', 'deduction') !== null && (int) $logs->sum('amount') >= 0)
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $headings = ['Yoklama ID', 'Ogrenci', 'E-posta', 'Durum', 'Yontem', 'Konum Dogrulamasi', 'Feedback', 'Kredi Kesildi', 'Kredi Iade', 'Enlem', 'Boylam', 'Kayit Zamani'];
        $rows = $participants->map(function (Participant $participant) use ($attendanceByUserId, $restoredUserIds, $deductedUserIds) {
            $uid = (int) $participant->user_id;
            $attendance = $attendanceByUserId->get($uid);

            return [
                $attendance?->id ?? '-',
                $participant->user ? trim($participant->user->name . ' ' . $participant->user->surname) : 'Silinmis kullanici',
                $participant->user?->email ?? '-',
                $attendance?->is_valid ? 'geldi' : 'gelmedi',
                $attendance?->method ?? '-',
                $attendance ? ($attendance->is_valid ? 'dogrulandi' : 'alan disi') : '-',
                in_array($uid, $restoredUserIds, true) ? 'gonderildi' : 'bekliyor',
                in_array($uid, $deductedUserIds, true) ? 'evet' : 'hayir',
                in_array($uid, $restoredUserIds, true) ? 'evet' : 'hayir',
                $attendance?->latitude ?? '-',
                $attendance?->longitude ?? '-',
                optional($attendance?->created_at)?->format('d.m.Y H:i:s') ?? '-',
            ];
        })->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'program_' . $program->id . '_yoklama_' . now()->format('Ymd_His'),
            'Program Yoklama Detaylari',
            $headings,
            $rows,
        );
    }

    /**
     * GET /panel/programs/{id}/feedback-stats
     * Etkinlik değerlendirme istatistikleri — soru bazlı ortalamalar + bireysel yanıtlar.
     */
    public function feedbackStats(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'programs.view');
        $program = Program::with(['project:id,name', 'period:id,name'])->findOrFail($id);
        $this->abortIfUnauthorized($request->user(), $program->project, 'programs.view');

        $feedbacks = Feedback::query()
            ->where('program_id', $program->id)
            ->orderByDesc('submitted_at')
            ->get();

        $totalCount = $feedbacks->count();

        // Soru bazlı istatistikler
        $ratingKeys = ['content_quality', 'speaker_quality', 'organization_quality'];
        $questionStats = [];

        foreach ($ratingKeys as $key) {
            $values = $feedbacks
                ->map(fn (Feedback $f) => $f->responses[$key] ?? null)
                ->filter(fn ($v) => $v !== null)
                ->values();

            $distribution = [];
            for ($i = 1; $i <= 5; $i++) {
                $distribution[$i] = $values->filter(fn ($v) => (int) $v === $i)->count();
            }

            $questionStats[$key] = [
                'label' => match ($key) {
                    'content_quality' => 'Icerik Kalitesi',
                    'speaker_quality' => 'Konusmaci Kalitesi',
                    'organization_quality' => 'Organizasyon Kalitesi',
                    default => $key,
                },
                'count' => $values->count(),
                'average' => $values->count() > 0 ? round($values->avg(), 2) : null,
                'min' => $values->count() > 0 ? (int) $values->min() : null,
                'max' => $values->count() > 0 ? (int) $values->max() : null,
                'distribution' => $distribution,
            ];
        }

        // Genel ortalama
        $allRatings = $feedbacks->flatMap(function (Feedback $f) use ($ratingKeys) {
            return collect($ratingKeys)->map(fn ($k) => $f->responses[$k] ?? null)->filter();
        });
        $overallAverage = $allRatings->count() > 0 ? round($allRatings->avg(), 2) : null;

        // Bireysel yanıtlar (anonim — sadece puan + yorum)
        $responses = $feedbacks->map(fn (Feedback $f) => [
            'id' => $f->id,
            'content_quality' => $f->responses['content_quality'] ?? null,
            'speaker_quality' => $f->responses['speaker_quality'] ?? null,
            'organization_quality' => $f->responses['organization_quality'] ?? null,
            'comment' => $f->responses['comment'] ?? null,
            'submitted_at' => optional($f->submitted_at)?->toIso8601String(),
        ])->values();

        // Yorumlu değerlendirme sayısı
        $withCommentCount = $feedbacks->filter(fn (Feedback $f) => ! empty($f->responses['comment']))->count();

        return response()->json([
            'program' => [
                'id' => $program->id,
                'title' => $program->title,
                'project' => $program->project?->name,
                'period' => $program->period?->name,
                'start_at' => optional($program->start_at)?->toIso8601String(),
                'status' => $program->status,
            ],
            'summary' => [
                'total_feedback' => $totalCount,
                'with_comment' => $withCommentCount,
                'overall_average' => $overallAverage,
            ],
            'question_stats' => $questionStats,
            'responses' => $responses,
        ]);
    }

    /**
     * GET /panel/programs/{id}/feedback-stats/export
     * Feedback verilerini dışa aktar.
     */
    public function exportFeedback(Request $request, int $id)
    {
        $this->abortUnlessAllowed($request, 'programs.view');
        $program = Program::with(['project:id,name'])->findOrFail($id);
        $this->abortIfUnauthorized($request->user(), $program->project, 'programs.view');

        $feedbacks = Feedback::query()
            ->where('program_id', $program->id)
            ->orderByDesc('submitted_at')
            ->get();

        $headings = ['#', 'Icerik Kalitesi', 'Konusmaci Kalitesi', 'Organizasyon Kalitesi', 'Yorum', 'Gonderim Tarihi'];
        $rows = $feedbacks->map(fn (Feedback $f, int $index) => [
            $index + 1,
            $f->responses['content_quality'] ?? '-',
            $f->responses['speaker_quality'] ?? '-',
            $f->responses['organization_quality'] ?? '-',
            $f->responses['comment'] ?? '-',
            $f->submitted_at?->format('d.m.Y H:i') ?? '-',
        ])->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'program_' . $program->id . '_degerlendirmeler_' . now()->format('Ymd_His'),
            'Program Degerlendirmeleri',
            $headings,
            $rows,
        );
    }
}
