<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesProjectPeriodContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\KpdAppointmentResource;
use App\Models\DigitalBohca;
use App\Models\KpdAppointment;
use App\Models\KpdReport;
use App\Models\KpdRoom;
use App\Models\Participant;
use App\Models\User;
use App\Support\MediaStorage;
use App\Support\ProjectSpecialModuleCatalog;
use App\Services\NotificationService;
use App\Services\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentKpdController extends Controller
{
    use ResolvesProjectPeriodContext;

    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly PermissionResolver $permissionResolver,
    ) {
    }

    private function reportPayload(KpdReport $report): array
    {
        return [
            'id' => $report->id,
            'title' => $report->title,
            'created_at' => optional($report->created_at)?->toIso8601String(),
            'download_url' => $report->file_path ? "/kpd/reports/{$report->id}/download" : null,
            'counselor' => $report->relationLoaded('counselor') ? $report->counselor : null,
        ];
    }

    private function streamReport(KpdReport $report): JsonResponse|StreamedResponse
    {
        if (! $report->file_path) {
            return response()->json(['message' => 'Rapor dosyasi bulunamadi.'], 404);
        }

        if ($this->isUrl($report->file_path) || (MediaStorage::directDownloadsEnabled() && MediaStorage::publicUrlConfigured())) {
            return response()->json(['download_url' => MediaStorage::url($report->file_path)]);
        }

        if (! MediaStorage::exists($report->file_path)) {
            return response()->json(['message' => 'Rapor dosyasi storage uzerinde bulunamadi.'], 404);
        }

        $extension = pathinfo($report->file_path, PATHINFO_EXTENSION);
        $filename = 'kpd_raporu_' . $report->id;

        return MediaStorage::disk()->download(
            $report->file_path,
            $filename . ($extension ? ".{$extension}" : '')
        );
    }

    private function isUrl(string $path): bool
    {
        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://');
    }

    private function kpdParticipationFor(User $user): ?Participant
    {
        return Participant::query()
            ->where('user_id', $user->id)
            ->where(function ($query) {
                $query->where('status', 'active')
                    ->orWhere('graduation_status', 'graduated')
                    ->orWhereNotNull('graduated_at');
            })
            ->with('project:id,type,name,slug')
            ->latest()
            ->get()
            ->first(fn (Participant $participant) =>
                $participant->project !== null
                && in_array('kpd_appointments', ProjectSpecialModuleCatalog::forProject($participant->project), true)
            );
    }

    private function abortUnlessKpdParticipant(Request $request): Participant
    {
        $user = $request->user();

        $participation = $this->kpdParticipationFor($user);
        abort_unless($participation !== null, 403, 'KPD modulu yalnizca KPD projesi katilimcilari ve mezunlari icin kullanilabilir.');

        return $participation;
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $participation = $this->abortUnlessKpdParticipant($request);

        $appointments = KpdAppointment::with([
            'counselor:id,name,surname,role',
            'counselee:id,name,surname',
            'room:id,name,description',
        ])
            ->where('counselee_id', $user->id)
            ->when($participation->period_id, fn ($query) => $query->where(fn ($inner) => $inner->whereNull('period_id')->orWhere('period_id', $participation->period_id)))
            ->orderByDesc('start_at')
            ->get();

        $counselors = User::query()
            ->whereIn('role', ['super_admin', 'coordinator', 'staff'])
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'surname', 'role'])
            ->map(fn (User $counselor) => [
                'id' => $counselor->id,
                'name' => $counselor->name,
                'surname' => $counselor->surname,
                'role' => $counselor->role,
            ])
            ->values();

        $rooms = KpdRoom::query()
            ->orderBy('name')
            ->get(['id', 'name', 'description'])
            ->map(fn (KpdRoom $room) => [
                'id' => $room->id,
                'name' => $room->name,
                'description' => $room->description,
            ])
            ->values();

        return response()->json([
            'appointments' => KpdAppointmentResource::collection($appointments),
            'counselors' => $counselors,
            'rooms' => $rooms,
            'materials' => DigitalBohca::query()
                ->where('project_id', $participation->project_id)
                ->where('visible_to_student', true)
                ->where(function ($query) use ($participation) {
                    $query->whereNull('period_id');

                    if ($participation->period_id) {
                        $query->orWhere('period_id', $participation->period_id);
                    }
                })
                ->latest()
                ->get()
                ->map(fn (DigitalBohca $material) => [
                    'id' => $material->id,
                    'title' => $material->title,
                    'description' => $material->description,
                    'file_type' => $material->file_type,
                    'category' => $material->category,
                    'download_url' => "/digital-bohca/{$material->id}/download",
                    'created_at' => optional($material->created_at)?->toIso8601String(),
                ])
                ->values(),
            'reports' => KpdReport::query()
                ->with('counselor:id,name,surname,role')
                ->where('user_id', $user->id)
                ->when($participation->period_id, fn ($query) => $query->where(fn ($inner) => $inner->whereNull('period_id')->orWhere('period_id', $participation->period_id)))
                ->latest()
                ->get()
                ->map(fn (KpdReport $report) => $this->reportPayload($report))
                ->values(),
        ]);
    }

    public function downloadReport(Request $request, int $id): JsonResponse|StreamedResponse
    {
        $participation = $this->abortUnlessKpdParticipant($request);

        $report = KpdReport::query()
            ->where('user_id', $request->user()->id)
            ->when($participation->period_id, fn ($query) => $query->where(fn ($inner) => $inner->whereNull('period_id')->orWhere('period_id', $participation->period_id)))
            ->findOrFail($id);

        return $this->streamReport($report);
    }

    public function store(Request $request): JsonResponse
    {
        $participation = $this->abortUnlessKpdParticipant($request);
        $this->assertPeriodWritable($request, $participation->period_id);

        $validated = $request->validate([
            'counselor_id' => 'required|exists:users,id',
            'room_id' => 'required|exists:kpd_rooms,id',
            'start_at' => 'required|date|after:now',
            'end_at' => 'required|date|after:start_at',
            'notes' => 'nullable|string|max:2000',
        ]);

        $counselor = User::query()
            ->whereKey($validated['counselor_id'])
            ->whereIn('role', ['super_admin', 'coordinator', 'staff'])
            ->first();

        if (! $counselor) {
            return response()->json([
                'message' => 'Secilen danisman uygun degil.',
            ], 422);
        }

        $timeConflict = KpdAppointment::query()
            ->where('status', '!=', 'cancelled')
            ->where(function ($query) use ($validated, $request) {
                $query->where('room_id', $validated['room_id'])
                    ->orWhere('counselor_id', $validated['counselor_id'])
                    ->orWhere('counselee_id', $request->user()->id);
            })
            ->where('start_at', '<', $validated['end_at'])
            ->where('end_at', '>', $validated['start_at'])
            ->exists();

        if ($timeConflict) {
            return response()->json([
                'message' => 'Secilen zaman araliginda uygun bir randevu olusturulamadi. Farkli saat veya oda deneyin.',
            ], 422);
        }

        $appointment = KpdAppointment::create([
            'counselor_id' => $validated['counselor_id'],
            'counselee_id' => $request->user()->id,
            'period_id' => $participation->period_id,
            'room_id' => $validated['room_id'],
            'start_at' => $validated['start_at'],
            'end_at' => $validated['end_at'],
            'notes' => $validated['notes'] ?? null,
            'status' => 'scheduled',
        ])->load([
            'counselor:id,name,surname,email,role',
            'counselee:id,name,surname,email',
            'room:id,name,description',
        ]);

        $this->notifyAppointmentCreated($appointment);

        return response()->json([
            'message' => 'KPD randevu talebin olusturuldu.',
            'appointment' => new KpdAppointmentResource($appointment),
        ], 201);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $participation = $this->abortUnlessKpdParticipant($request);

        $appointment = KpdAppointment::query()
            ->where('counselee_id', $request->user()->id)
            ->when($participation->period_id, fn ($query) => $query->where(fn ($inner) => $inner->whereNull('period_id')->orWhere('period_id', $participation->period_id)))
            ->findOrFail($id);
        $this->assertPeriodWritable($request, $appointment->period_id);

        if ($appointment->status !== 'scheduled') {
            return response()->json([
                'message' => 'Yalnizca planlanmis randevular iptal edilebilir.',
            ], 422);
        }

        if ($appointment->start_at !== null && $appointment->start_at->isPast()) {
            return response()->json([
                'message' => 'Baslamis veya gecmis randevu iptal edilemez.',
            ], 422);
        }

        $appointment->update(['status' => 'cancelled']);
        $appointment->load([
            'counselor:id,name,surname,email,role',
            'counselee:id,name,surname,email',
            'room:id,name,description',
        ]);

        $this->notificationService->sendEmail(
            array_filter([$appointment->counselor?->email, $appointment->counselee?->email]),
            'KPD randevusu iptal edildi',
            'Randevu tarihi: '.(optional($appointment->start_at)?->format('d.m.Y H:i') ?? '-')."\nOda: ".($appointment->room?->name ?? '-'),
            null,
            $request->user()->id
        );

        return response()->json([
            'message' => 'Randevu iptal edildi.',
            'appointment' => new KpdAppointmentResource($appointment),
        ]);
    }

    private function notifyAppointmentCreated(KpdAppointment $appointment): void
    {
        $this->notificationService->sendEmail(
            array_filter([$appointment->counselor?->email, $appointment->counselee?->email]),
            'KPD randevu talebi olusturuldu',
            'Randevu tarihi: '.(optional($appointment->start_at)?->format('d.m.Y H:i') ?? '-').
            "\nOda: ".($appointment->room?->name ?? '-').
            "\nDanisan: ".trim(($appointment->counselee?->name ?? '').' '.($appointment->counselee?->surname ?? '')),
            null,
            $appointment->counselee_id
        );
    }
}
