<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\KpdAppointment;
use App\Models\KpdReport;
use App\Support\MediaStorage;
use App\Services\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminKpdController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {
    }

    private function abortUnlessGlobalKpd(Request $request, string $permission): void
    {
        $this->abortUnlessAllowed($request, $permission);
        abort_unless(
            $this->permissionResolver->hasGlobalScope($request->user(), $permission),
            403,
            'KPD islemleri icin tum sistem kapsami gerekir.'
        );
    }

    private function reportPayload(KpdReport $report): array
    {
        return [
            'id' => $report->id,
            'user_id' => $report->user_id,
            'counselor_id' => $report->counselor_id,
            'title' => $report->title,
            'file_path' => $report->file_path,
            'download_url' => $report->file_path ? "/panel/kpd/reports/{$report->id}/download" : null,
            'created_at' => optional($report->created_at)?->toIso8601String(),
            'user' => $report->relationLoaded('user') ? $report->user : null,
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

    /**
     * Tüm KPD Randevularını Listele
     */
    public function index(Request $request)
    {
        $this->abortUnlessAllowed($request, 'kpd.appointments.view');
        abort_unless(
            $this->permissionResolver->hasGlobalScope($request->user(), 'kpd.appointments.view'),
            403,
            'KPD randevulari icin tum sistem kapsami gerekir.'
        );

        $appointments = KpdAppointment::with(['counselor:id,name,surname', 'counselee:id,name,surname', 'room'])
            ->orderBy('start_at', 'desc')
            ->paginate(15);

        return response()->json(['appointments' => $appointments]);
    }

    public function reports(Request $request): JsonResponse
    {
        $this->abortUnlessGlobalKpd($request, 'kpd.reports.view');

        $reports = KpdReport::query()
            ->with(['user:id,name,surname,email', 'counselor:id,name,surname,email'])
            ->latest()
            ->paginate(20)
            ->through(fn (KpdReport $report) => $this->reportPayload($report));

        return response()->json(['reports' => $reports]);
    }

    public function storeReport(Request $request): JsonResponse
    {
        $this->abortUnlessGlobalKpd($request, 'kpd.reports.create');

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'title' => 'required|string|max:255',
            'file' => 'required|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:20480',
        ]);

        $path = MediaStorage::putFile('kpd-reports', $request->file('file'));

        $report = KpdReport::query()->create([
            'user_id' => $validated['user_id'],
            'counselor_id' => $request->user()->id,
            'title' => $validated['title'],
            'file_path' => $path,
        ])->load(['user:id,name,surname,email', 'counselor:id,name,surname,email']);

        return response()->json([
            'message' => 'KPD raporu yuklendi.',
            'report' => $this->reportPayload($report),
        ], 201);
    }

    public function downloadReport(Request $request, int $id): JsonResponse|StreamedResponse
    {
        $this->abortUnlessGlobalKpd($request, 'kpd.reports.view');

        $report = KpdReport::query()->findOrFail($id);

        return $this->streamReport($report);
    }

    public function destroyReport(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessGlobalKpd($request, 'kpd.reports.delete');

        $report = KpdReport::query()->findOrFail($id);
        MediaStorage::delete($report->file_path);
        $report->delete();

        return response()->json(['message' => 'KPD raporu silindi.']);
    }

    /**
     * Yeni Randevu Oluştur
     */
    public function store(Request $request)
    {
        $this->abortUnlessAllowed($request, 'kpd.appointments.manage');
        abort_unless(
            $this->permissionResolver->hasGlobalScope($request->user(), 'kpd.appointments.manage'),
            403,
            'KPD randevulari icin tum sistem kapsami gerekir.'
        );

        $validated = $request->validate([
            'counselor_id' => 'required|exists:users,id',
            'counselee_id' => 'required|exists:users,id',
            'room_id' => 'required|exists:kpd_rooms,id',
            'start_at' => 'required|date',
            'end_at' => 'required|date|after:start_at',
            'notes' => 'nullable|string',
        ]);

        $exists = KpdAppointment::where('room_id', $validated['room_id'])
            ->where(function ($q) use ($validated) {
                $q->whereBetween('start_at', [$validated['start_at'], $validated['end_at']])
                    ->orWhereBetween('end_at', [$validated['start_at'], $validated['end_at']]);
            })->exists();

        if ($exists) {
            return response()->json(['message' => 'Seçilen oda bu saatlerde dolu.'], 400);
        }

        $appointment = KpdAppointment::create(array_merge($validated, ['status' => 'scheduled']));

        return response()->json([
            'message' => 'Randevu başarıyla oluşturuldu.',
            'appointment' => $appointment,
        ], 201);
    }
}
