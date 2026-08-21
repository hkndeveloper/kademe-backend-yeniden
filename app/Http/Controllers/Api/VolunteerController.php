<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Concerns\ResolvesProjectPeriodContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\VolunteerOpportunityResource;
use App\Models\VolunteerApplication;
use App\Models\VolunteerOpportunity;
use App\Services\NotificationService;
use App\Services\PermissionResolver;
use App\Support\AdminExportResponder;
use App\Support\IstanbulDateTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * @group Volunteer
 */
class VolunteerController extends Controller
{
    use AuthorizesGranularPermissions;
    use ResolvesProjectPeriodContext;

    public function __construct(
        private readonly PermissionResolver $permissionResolver,
        private readonly NotificationService $notificationService,
    ) {
    }

    /**
     * List volunteer opportunities and my applications.
     *
     * Requires permission: `participant.volunteer.apply`. Returns open volunteer opportunities and the authenticated user previous applications.
     *
     * @group Volunteer
     * @authenticated
     *
     * @response 200 {"opportunities":[{"id":1,"title":"Etkinlik Gonullusu","status":"open"}],"my_applications":[{"id":1,"status":"pending","opportunity":{"id":1,"title":"Etkinlik Gonullusu"}}]}
     * @response 401 {"message":"Unauthenticated."}
     * @response 403 {"message":"This action is unauthorized."}
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $opportunities = VolunteerOpportunity::query()
            ->with([
                'project:id,name,slug,type',
                'period:id,name,status',
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
            ->orderByDesc('created_at')
            ->orderByDesc('id')
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

    /**
     * Apply to a volunteer opportunity.
     *
     * Requires permission: `participant.volunteer.apply`. The opportunity must be open, quota must be available, and the current user must not have applied before.
     *
     * @group Volunteer
     * @authenticated
     *
     * @urlParam id integer required Volunteer opportunity id. Example: 1
     * @bodyParam motivation_text string required Motivation text, min 20 characters. Example: Bu etkinlikte gonullu olmak istiyorum.
     * @bodyParam notes string Optional additional note. Example: Hafta sonu uygunum.
     * @response 201 {"message":"Gonullu basvurun alindi.","application":{"id":1,"status":"pending","opportunity":{"id":1,"title":"Etkinlik Gonullusu"}}}
     * @response 422 {"message":"Bu gonullu ilanina daha once basvurdun."}
     * @response 422 {"message":"Bu gonullu ilani icin kontenjan dolu."}
     */
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
        $this->assertPeriodWritable($request, $opportunity->period_id);

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
        $validated = $request->validate([
            'project_id' => 'nullable|exists:projects,id',
            'period_id' => 'nullable|exists:periods,id',
            'status' => 'nullable|string|max:50',
        ]);

        $context = $this->resolveProjectPeriodContext(
            $request,
            'volunteer.view',
            ! empty($validated['project_id']) ? (int) $validated['project_id'] : null,
            ! empty($validated['period_id']) ? (int) $validated['period_id'] : null,
        );
        $query = VolunteerOpportunity::query()
            ->with([
                'project:id,name',
                'period:id,name,status',
                'creator:id,name,surname',
                'applications.user:id,name,surname,email,phone',
            ])
            ->withCount('applications')
            ->orderByDesc('created_at');
        $this->applyProjectPeriodContext($query, $context, includeNullPeriodRows: true);

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
            'period_id' => 'nullable|exists:periods,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:4000',
            'location' => 'nullable|string|max:255',
            'start_at' => 'nullable|date',
            'end_at' => 'nullable|date|after_or_equal:start_at',
            'quota' => 'nullable|integer|min:1',
            'status' => 'required|in:open,closed,archived',
        ]);
        $validated = IstanbulDateTime::normalizeFields($validated, ['start_at', 'end_at']);

        $this->abortUnlessProjectAllowed($request, 'volunteer.manage', (int) $validated['project_id']);

        if (! empty($validated['period_id'])) {
            abort_unless(
                \App\Models\Period::query()
                    ->whereKey((int) $validated['period_id'])
                    ->where('project_id', (int) $validated['project_id'])
                    ->exists(),
                422,
                'Secilen donem bu projeye ait degil.'
            );
            $this->assertPeriodWritable($request, (int) $validated['period_id']);
        }

        $opportunity = VolunteerOpportunity::query()->create([
            ...$validated,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Gonullu ilani olusturuldu.',
            'opportunity' => $opportunity->load(['project:id,name', 'creator:id,name,surname']),
        ], 201);
    }


    public function panelUpdate(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'volunteer.manage');

        $opportunity = VolunteerOpportunity::query()->findOrFail($id);
        $this->abortUnlessProjectAllowed($request, 'volunteer.manage', (int) $opportunity->project_id);
        $this->assertPeriodWritable($request, $opportunity->period_id);

        $validated = $request->validate([
            'project_id' => 'sometimes|required|exists:projects,id',
            'period_id' => 'nullable|exists:periods,id',
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:4000',
            'location' => 'nullable|string|max:255',
            'start_at' => 'nullable|date',
            'end_at' => 'nullable|date|after_or_equal:start_at',
            'quota' => 'nullable|integer|min:1',
            'status' => 'sometimes|required|in:open,closed,archived',
        ]);
        $validated = IstanbulDateTime::normalizeFields($validated, ['start_at', 'end_at']);

        $targetProjectId = (int) ($validated['project_id'] ?? $opportunity->project_id);
        $this->abortUnlessProjectAllowed($request, 'volunteer.manage', $targetProjectId);

        if (array_key_exists('period_id', $validated) && ! empty($validated['period_id'])) {
            abort_unless(
                \App\Models\Period::query()
                    ->whereKey((int) $validated['period_id'])
                    ->where('project_id', $targetProjectId)
                    ->exists(),
                422,
                'Secilen donem bu projeye ait degil.'
            );
            $this->assertPeriodWritable($request, (int) $validated['period_id']);
        }

        $opportunity->update($validated);

        return response()->json([
            'message' => 'Gonullu ilani guncellendi.',
            'opportunity' => $opportunity->fresh(['project:id,name', 'period:id,name,status', 'creator:id,name,surname']),
        ]);
    }
    public function panelExport(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'nullable|exists:projects,id',
            'period_id' => 'nullable|exists:periods,id',
            'status' => 'nullable|string|max:50',
            'format' => 'nullable|string|max:20',
        ]);
        $context = $this->resolveProjectPeriodContext(
            $request,
            'volunteer.view',
            ! empty($validated['project_id']) ? (int) $validated['project_id'] : null,
            ! empty($validated['period_id']) ? (int) $validated['period_id'] : null,
        );

        $query = VolunteerOpportunity::query()
            ->with(['project:id,name', 'period:id,name,status', 'creator:id,name,surname', 'applications.user:id,name,surname,email'])
            ->withCount('applications')
            ->orderByDesc('created_at');
        $this->applyProjectPeriodContext($query, $context, includeNullPeriodRows: true);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $opportunities = $query->get();

        $headings = [
            'Ilan ID', 'Ilan Basligi', 'Proje', 'Donem', 'Durum', 'Kontenjan', 'Baslangic', 'Bitis',
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
                $opportunity->period?->name ?? '-',
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
            ->with('opportunity:id,project_id,period_id,title')
            ->findOrFail($id);

        $this->abortUnlessProjectAllowed($request, 'volunteer.manage', (int) $application->opportunity->project_id);
        $this->assertPeriodResolvable($request, $application->opportunity->period_id);

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
        $this->assertPeriodWritable($request, $opportunity->period_id);
        $opportunity->delete();

        return response()->json(['message' => 'Gonullu ilani silindi.']);
    }
}
