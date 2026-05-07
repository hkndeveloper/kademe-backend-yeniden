<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Http\Resources\VolunteerOpportunityResource;
use App\Models\VolunteerApplication;
use App\Models\VolunteerOpportunity;
use App\Services\NotificationService;
use App\Services\PermissionResolver;
use App\Support\AdminExportResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VolunteerController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver,
        private readonly NotificationService $notificationService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $opportunities = VolunteerOpportunity::query()
            ->with([
                'project:id,name,slug,type',
                'applications' => fn ($query) => $query
                    ->where('user_id', $user->id)
                    ->select([
                        'id',
                        'volunteer_opportunity_id',
                        'status',
                        'motivation_text',
                        'notes',
                        'evaluation_note',
                        'created_at',
                    ]),
            ])
            ->where('status', 'open')
            ->orderBy('start_at')
            ->get();

        $myApplications = VolunteerApplication::query()
            ->with(['opportunity.project:id,name,slug,type'])
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(function (VolunteerApplication $application) {
                return [
                    'id' => $application->id,
                    'status' => $application->status,
                    'motivation_text' => $application->motivation_text,
                    'notes' => $application->notes,
                    'evaluation_note' => $application->evaluation_note,
                    'created_at' => optional($application->created_at)?->toIso8601String(),
                    'opportunity' => [
                        'id' => $application->opportunity?->id,
                        'title' => $application->opportunity?->title,
                        'project' => $application->opportunity?->project ? [
                            'id' => $application->opportunity->project->id,
                            'name' => $application->opportunity->project->name,
                            'slug' => $application->opportunity->project->slug,
                            'type' => $application->opportunity->project->type,
                        ] : null,
                    ],
                ];
            })
            ->values();

        return response()->json([
            'opportunities' => VolunteerOpportunityResource::collection($opportunities),
            'my_applications' => $myApplications,
        ]);
    }

    public function apply(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'motivation_text' => 'required|string|min:20|max:4000',
            'notes' => 'nullable|string|max:2000',
        ]);

        $opportunity = VolunteerOpportunity::query()
            ->with('project:id,name,slug,type')
            ->where('status', 'open')
            ->findOrFail($id);

        $existingApplication = VolunteerApplication::query()
            ->where('volunteer_opportunity_id', $opportunity->id)
            ->where('user_id', $request->user()->id)
            ->first();

        if ($existingApplication) {
            return response()->json([
                'message' => 'Bu gonullu ilanina daha once basvurdun.',
            ], 422);
        }

        $acceptedCount = VolunteerApplication::query()
            ->where('volunteer_opportunity_id', $opportunity->id)
            ->where('status', 'accepted')
            ->count();

        if ($opportunity->quota !== null && $acceptedCount >= $opportunity->quota) {
            return response()->json([
                'message' => 'Bu gonullu ilani icin kontenjan dolu.',
            ], 422);
        }

        $application = VolunteerApplication::create([
            'volunteer_opportunity_id' => $opportunity->id,
            'user_id' => $request->user()->id,
            'motivation_text' => $validated['motivation_text'],
            'notes' => $validated['notes'] ?? null,
            'status' => 'pending',
        ]);

        try {
            $this->notificationService->sendEmail(
                array_filter([$request->user()->email]),
                'Gonullu basvurunuz alindi',
                "Ilan: {$opportunity->title}\nBasvurunuz alinmistir. Degerlendirme sonrasi bilgilendirileceksiniz.",
                $opportunity->project_id,
                $request->user()->id
            );
        } catch (\Throwable $exception) {
            Log::warning('volunteer.application_notification_failed', [
                'application_id' => $application->id,
                'opportunity_id' => $opportunity->id,
                'user_id' => $request->user()->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Gonullu basvurun alindi.',
            'application' => [
                'id' => $application->id,
                'status' => $application->status,
                'motivation_text' => $application->motivation_text,
                'notes' => $application->notes,
                'evaluation_note' => $application->evaluation_note,
                'created_at' => optional($application->created_at)?->toIso8601String(),
                'opportunity' => [
                    'id' => $opportunity->id,
                    'title' => $opportunity->title,
                    'project' => $opportunity->project ? [
                        'id' => $opportunity->project->id,
                        'name' => $opportunity->project->name,
                        'slug' => $opportunity->project->slug,
                        'type' => $opportunity->project->type,
                    ] : null,
                ],
            ],
        ], 201);
    }

    public function panelIndex(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'volunteer.view');
        $validated = $request->validate([
            'project_id' => 'nullable|exists:projects,id',
            'status' => 'nullable|string|max:50',
        ]);

        $projectIds = $this->permissionResolver->projectIdsForPermission($request->user(), 'volunteer.view');
        $query = VolunteerOpportunity::query()
            ->with([
                'project:id,name',
                'creator:id,name,surname',
                'applications.user:id,name,surname,email,phone',
            ])
            ->withCount('applications')
            ->whereIn('project_id', $projectIds)
            ->orderByDesc('created_at');

        if (! empty($validated['project_id'])) {
            $this->abortUnlessProjectAllowed($request, 'volunteer.view', (int) $validated['project_id']);
            $query->where('project_id', (int) $validated['project_id']);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        return response()->json([
            'opportunities' => $query->paginate(20),
        ]);
    }

    public function panelStore(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'volunteer.manage');
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:4000',
            'location' => 'nullable|string|max:255',
            'start_at' => 'nullable|date',
            'end_at' => 'nullable|date|after_or_equal:start_at',
            'quota' => 'nullable|integer|min:1',
            'status' => 'required|in:open,closed,archived',
        ]);

        $this->abortUnlessProjectAllowed($request, 'volunteer.manage', (int) $validated['project_id']);

        $opportunity = VolunteerOpportunity::query()->create([
            ...$validated,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Gonullu ilani olusturuldu.',
            'opportunity' => $opportunity->load(['project:id,name', 'creator:id,name,surname']),
        ], 201);
    }

    public function panelExport(Request $request)
    {
        $this->abortUnlessAllowed($request, 'volunteer.view');
        $user = $request->user();
        $projectIds = $this->permissionResolver->projectIdsForPermission($user, 'volunteer.view');

        $query = VolunteerOpportunity::query()
            ->with(['project:id,name', 'creator:id,name,surname', 'applications.user:id,name,surname,email'])
            ->withCount('applications')
            ->orderByDesc('created_at');

        if (! $this->permissionResolver->hasGlobalScope($user, 'volunteer.view')) {
            $query->whereIn('project_id', $projectIds);
        }

        if ($request->filled('project_id')) {
            $projectId = $request->integer('project_id');
            $this->abortUnlessProjectAllowed($request, 'volunteer.view', $projectId);
            $query->where('project_id', $projectId);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        $opportunities = $query->get();

        $headings = [
            'Ilan ID', 'Ilan Basligi', 'Proje', 'Durum', 'Kontenjan', 'Baslangic', 'Bitis',
            'Basvuru Sayisi', 'Olusturan', 'Olusturma Tarihi', 'Basvuranlar',
        ];
        $rows = $opportunities->map(function (VolunteerOpportunity $opportunity) {
            $applicantSummary = $opportunity->applications
                ->map(fn (VolunteerApplication $application) => trim(($application->user?->name ?? '-') . ' ' . ($application->user?->surname ?? '')) . " ({$application->status})")
                ->implode('; ');

            return [
                $opportunity->id,
                $opportunity->title,
                $opportunity->project?->name ?? '-',
                $opportunity->status,
                $opportunity->quota ?? '-',
                $opportunity->start_at?->format('d.m.Y H:i') ?? '-',
                $opportunity->end_at?->format('d.m.Y H:i') ?? '-',
                $opportunity->applications_count,
                $opportunity->creator ? trim($opportunity->creator->name . ' ' . $opportunity->creator->surname) : '-',
                $opportunity->created_at?->format('d.m.Y H:i') ?? '-',
                $applicantSummary !== '' ? $applicantSummary : '-',
            ];
        })->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'gonullu_ilanlari_' . now()->format('Ymd_His'),
            'Gonullu Ilanlari',
            $headings,
            $rows,
        );
    }

    public function panelUpdateApplication(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'volunteer.manage');
        $validated = $request->validate([
            'status' => 'required|in:pending,accepted,waitlisted,rejected',
            'evaluation_note' => 'nullable|string|max:3000',
        ]);

        $application = VolunteerApplication::query()
            ->with('opportunity:id,project_id,title')
            ->findOrFail($id);

        $this->abortUnlessProjectAllowed($request, 'volunteer.manage', (int) $application->opportunity->project_id);

        $application->update($validated);

        $application->loadMissing(['user:id,email,name', 'opportunity:id,title,project_id']);
        try {
            $this->notificationService->sendEmail(
                array_filter([$application->user?->email]),
                'Gonullu basvuru durumunuz guncellendi',
                "Ilan: {$application->opportunity?->title}\nYeni durum: {$application->status}",
                $application->opportunity?->project_id,
                $request->user()->id
            );
        } catch (\Throwable $exception) {
            Log::warning('volunteer.application_status_notification_failed', [
                'application_id' => $application->id,
                'user_id' => $application->user_id,
                'error' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Gonullu basvurusu guncellendi.',
            'application' => $application->fresh(['user:id,name,surname,email,phone']),
        ]);
    }

    public function panelDestroy(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'volunteer.manage');
        $opportunity = VolunteerOpportunity::query()->findOrFail($id);
        $this->abortUnlessProjectAllowed($request, 'volunteer.manage', (int) $opportunity->project_id);
        $opportunity->delete();

        return response()->json(['message' => 'Gonullu ilani silindi.']);
    }
}
