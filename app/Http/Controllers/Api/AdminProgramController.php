<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\CreditLog;
use App\Models\Feedback;
use App\Models\Program;
use App\Models\Project;
use App\Support\AdminExportResponder;
use App\Models\User;
use App\Services\GoogleCalendarService;
use App\Services\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AdminProgramController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
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
        } elseif ($request->user()->role !== 'super_admin') {
            $manageableProjectIds = $this->permissionResolver->projectIdsForPermission($request->user(), 'programs.export');
            $query->whereIn('project_id', $manageableProjectIds);
        }

        $programs = $query->orderByDesc('start_at')->get();
        $headings = ['ID', 'Proje', 'Donem', 'Baslik', 'Konum', 'Baslangic', 'Bitis', 'Durum', 'Yoklama Capi', 'Kredi Dusumu'];
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
        ]);

        $project = Project::findOrFail($validated['project_id']);
        $this->abortIfUnauthorized($request->user(), $project, 'programs.create');

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
            'status' => 'required|in:scheduled,active,completed,cancelled',
        ]);

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

        $qrToken = 'prg_' . $program->id . '_' . Str::random(12);
        $rotationSeconds = 30;
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

        $program->update([
            'status' => 'completed',
            'qr_token' => null,
            'qr_expires_at' => null,
        ]);

        try {
            $googleCalendar->syncProgram($program->fresh(['project:id,name', 'period:id,name']));
        } catch (\Throwable $throwable) {
            Log::warning('Program Google Calendar senkronizasyonu complete sirasinda basarisiz oldu.', [
                'program_id' => $program->id,
                'message' => $throwable->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Etkinlik ve yoklama alimi basariyla sonlandirildi.',
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

        $feedbackByUserId = [];
        $feedbackCount = Feedback::query()->where('program_id', $program->id)->count();
        if ($feedbackCount > 0) {
            $participantIds = $attendances->pluck('user_id')->filter()->values()->all();
            if ($participantIds !== []) {
                $logs = CreditLog::query()
                    ->where('program_id', $program->id)
                    ->where('type', 'restore')
                    ->whereIn('user_id', $participantIds)
                    ->get();
                $feedbackByUserId = $logs->groupBy('user_id')->map(fn ($group) => $group->count())->all();
            }
        }

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
                'feedback_count' => $feedbackCount,
            ],
            'records' => $attendances->map(function (Attendance $attendance) use ($feedbackByUserId) {
                $uid = (int) $attendance->user_id;
                return [
                    'id' => $attendance->id,
                    'student' => $attendance->user ? trim($attendance->user->name . ' ' . $attendance->user->surname) : 'Silinmis kullanici',
                    'email' => $attendance->user?->email,
                    'method' => $attendance->method,
                    'is_valid' => (bool) $attendance->is_valid,
                    'latitude' => $attendance->latitude,
                    'longitude' => $attendance->longitude,
                    'feedback_submitted' => ($feedbackByUserId[$uid] ?? 0) > 0,
                    'recorded_at' => optional($attendance->created_at)?->toIso8601String(),
                ];
            })->values(),
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

        $feedbackUserIds = CreditLog::query()
            ->where('program_id', $program->id)
            ->where('type', 'restore')
            ->pluck('user_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $headings = ['Yoklama ID', 'Ogrenci', 'E-posta', 'Yontem', 'Konum Dogrulamasi', 'Feedback', 'Enlem', 'Boylam', 'Kayit Zamani'];
        $rows = $attendances->map(function (Attendance $attendance) use ($feedbackUserIds) {
            return [
                $attendance->id,
                $attendance->user ? trim($attendance->user->name . ' ' . $attendance->user->surname) : 'Silinmis kullanici',
                $attendance->user?->email ?? '-',
                $attendance->method,
                $attendance->is_valid ? 'dogrulandi' : 'alan disi',
                in_array((int) $attendance->user_id, $feedbackUserIds, true) ? 'gonderildi' : 'bekliyor',
                $attendance->latitude ?? '-',
                $attendance->longitude ?? '-',
                optional($attendance->created_at)?->format('d.m.Y H:i:s') ?? '-',
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
}
