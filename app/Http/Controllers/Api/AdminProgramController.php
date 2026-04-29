<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
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

        $programs = Program::query()
            ->with(['project:id,name', 'period:id,name'])
            ->where('project_id', $project->id)
            ->orderBy('start_at', 'desc')
            ->get();

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
        $expiresAt = now()->addSeconds($program->qr_rotation_seconds ?? 30);

        $program->update([
            'status' => 'active',
            'qr_token' => $qrToken,
            'qr_expires_at' => $expiresAt,
        ]);

        return response()->json([
            'qr_token' => $qrToken,
            'expires_at' => $expiresAt,
            'refresh_in_seconds' => $program->qr_rotation_seconds ?? 30,
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
}
