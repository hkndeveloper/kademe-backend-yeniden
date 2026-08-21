<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Http\Resources\PeriodResource;
use App\Models\Application;
use App\Models\Assignment;
use App\Models\Certificate;
use App\Models\DigitalBohca;
use App\Models\FinancialTransaction;
use App\Models\KpdAppointment;
use App\Models\KpdReport;
use App\Models\Period;
use App\Models\PeriodArchive;
use App\Models\PeriodLifecycleEvent;
use App\Models\Program;
use App\Models\ProjectModule;
use App\Models\VolunteerOpportunity;
use App\Services\PeriodArchiveService;
use App\Services\PeriodClosureReadinessService;
use App\Services\PeriodLifecycleService;
use App\Services\PeriodLifecycleMonitor;
use App\Services\PermissionResolver;
use App\Support\AdminExportResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * @group Periods
 */
class PeriodController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver,
        private readonly PeriodLifecycleService $lifecycleService,
        private readonly PeriodClosureReadinessService $readinessService,
        private readonly PeriodArchiveService $archiveService,
        private readonly PeriodLifecycleMonitor $monitor,
    ) {}

    private function scopeManageablePeriods(Request $request, $query, string $permission)
    {
        $user = $request->user();

        if ($this->permissionResolver->hasGlobalScope($user, $permission)) {
            return $query;
        }

        $manageableProjectIds = $this->permissionResolver->projectIdsForPermission($user, $permission);

        if ($manageableProjectIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('project_id', $manageableProjectIds);
    }

    private function resolvePeriodForAction(Request $request, int $id, string $permission): Period
    {
        $this->abortUnlessAllowed($request, $permission);

        $period = Period::with(['project:id,name,current_period_id', 'latestArchive'])->findOrFail($id);

        $this->abortUnlessProjectAllowed($request, $permission, (int) $period->project_id);

        return $period;
    }

    private function periodPayload(Request $request, Period $period): array
    {
        $period->loadMissing(['project:id,name,current_period_id', 'latestArchive']);

        return (new PeriodResource($period))->toArray($request);
    }

    private function buildClosurePayload(Period $period): array
    {
        $participants = $period->participants();
        $applications = Application::query()->where('period_id', $period->id);
        $programs = Program::query()->where('period_id', $period->id);
        $assignments = Assignment::query()->where('period_id', $period->id);
        $certificates = Certificate::query()->where('period_id', $period->id);
        $materials = DigitalBohca::query()->where('period_id', $period->id);
        $volunteerOpportunities = VolunteerOpportunity::query()->where('period_id', $period->id);
        $financials = FinancialTransaction::query()->where('period_id', $period->id);
        $kpdAppointments = KpdAppointment::query()->where('period_id', $period->id);
        $kpdReports = KpdReport::query()->where('period_id', $period->id);
        $modules = ProjectModule::query()->where('period_id', $period->id);
        $creditParticipants = $period->participants()
            ->with('user:id,name,surname,email')
            ->orderBy('credit')
            ->get(['id', 'user_id', 'project_id', 'period_id', 'status', 'graduation_status', 'credit']);
        $creditThreshold = (int) ($period->credit_threshold ?? 75);
        $creditStartAmount = (int) ($period->credit_start_amount ?? 100);
        $creditValues = $creditParticipants->pluck('credit')->map(fn ($credit) => (int) $credit);
        $creditSnapshot = $creditParticipants->map(function ($participant) use ($creditThreshold) {
            $credit = (int) $participant->credit;

            return [
                'participant_id' => $participant->id,
                'user_id' => $participant->user_id,
                'student' => $participant->user ? trim($participant->user->name.' '.$participant->user->surname) : 'Silinmis kullanici',
                'email' => $participant->user?->email,
                'status' => $participant->status,
                'graduation_status' => $participant->graduation_status,
                'credit' => $credit,
                'threshold' => $creditThreshold,
                'risk_gap' => max($creditThreshold - $credit, 0),
                'below_threshold' => $credit < $creditThreshold,
            ];
        })->values();

        $summary = [
            'participants' => [
                'total' => (clone $participants)->count(),
                'active' => (clone $participants)->where('status', 'active')->count(),
                'completed' => (clone $participants)->where('graduation_status', 'completed')->count(),
                'graduated' => (clone $participants)->where('graduation_status', 'graduated')->count(),
                'not_completed' => (clone $participants)->where('graduation_status', 'not_completed')->count(),
            ],
            'applications' => [
                'total' => (clone $applications)->count(),
                'pending' => (clone $applications)->where('status', 'pending')->count(),
                'interview_planned' => (clone $applications)->where('status', 'interview_planned')->count(),
                'waitlisted' => (clone $applications)->where('status', 'waitlisted')->count(),
                'accepted' => (clone $applications)->where('status', 'accepted')->count(),
                'rejected' => (clone $applications)->where('status', 'rejected')->count(),
            ],
            'programs' => [
                'total' => (clone $programs)->count(),
                'open' => (clone $programs)->whereIn('status', ['scheduled', 'active'])->count(),
                'completed' => (clone $programs)->where('status', 'completed')->count(),
                'cancelled' => (clone $programs)->where('status', 'cancelled')->count(),
            ],
            'assignments' => [
                'total' => (clone $assignments)->count(),
                'open' => (clone $assignments)->where(function ($query) {
                    $query->whereNull('due_date')->orWhere('due_date', '>=', now());
                })->count(),
            ],
            'certificates' => [
                'total' => (clone $certificates)->count(),
            ],
            'materials' => [
                'digital_bohca' => (clone $materials)->count(),
                'volunteer_opportunities' => (clone $volunteerOpportunities)->count(),
                'kademe_modules' => (clone $modules)->count(),
            ],
            'kpd' => [
                'appointments' => (clone $kpdAppointments)->count(),
                'reports' => (clone $kpdReports)->count(),
            ],
            'financials' => [
                'total' => (clone $financials)->count(),
                'pending' => (clone $financials)->where('status', 'pending')->count(),
                'approved' => (clone $financials)->where('status', 'approved')->count(),
                'paid' => (clone $financials)->where('status', 'paid')->count(),
            ],
            'credit_snapshot' => [
                'start_amount' => $creditStartAmount,
                'threshold' => $creditThreshold,
                'participant_count' => $creditParticipants->count(),
                'total_credit' => (int) $creditValues->sum(),
                'average_credit' => $creditValues->isNotEmpty() ? round($creditValues->avg(), 1) : 0,
                'min_credit' => $creditValues->isNotEmpty() ? (int) $creditValues->min() : 0,
                'max_credit' => $creditValues->isNotEmpty() ? (int) $creditValues->max() : 0,
                'below_threshold_count' => $creditSnapshot->where('below_threshold', true)->count(),
                'zero_or_below_count' => $creditSnapshot->filter(fn ($item) => (int) $item['credit'] <= 0)->count(),
                'participants' => $creditSnapshot,
            ],
        ];

        $warnings = [
            'open_programs' => (clone $programs)->whereIn('status', ['scheduled', 'active'])->count(),
            'pending_applications' => (clone $applications)->whereIn('status', ['pending', 'interview_planned', 'waitlisted'])->count(),
            'pending_financials' => (clone $financials)->where('status', 'pending')->count(),
        ];

        return [
            'summary' => $summary,
            'warnings' => $warnings,
            'counts' => [
                'participants_total' => $summary['participants']['total'],
                'credit_below_threshold_total' => $summary['credit_snapshot']['below_threshold_count'],
                'credit_snapshot_total' => $summary['credit_snapshot']['total_credit'],
                'applications_total' => $summary['applications']['total'],
                'programs_total' => $summary['programs']['total'],
                'assignments_total' => $summary['assignments']['total'],
                'certificates_total' => $summary['certificates']['total'],
                'digital_bohca_total' => $summary['materials']['digital_bohca'],
                'volunteer_opportunities_total' => $summary['materials']['volunteer_opportunities'],
                'financials_total' => $summary['financials']['total'],
                'kpd_appointments_total' => $summary['kpd']['appointments'],
                'kpd_reports_total' => $summary['kpd']['reports'],
                'project_modules_total' => $summary['materials']['kademe_modules'],
            ],
        ];
    }

    private function formatArchive(?PeriodArchive $archive, bool $includeSnapshot = false): ?array
    {
        if (! $archive) {
            return null;
        }

        $payload = [
            'id' => $archive->id,
            'period_id' => $archive->period_id,
            'project_id' => $archive->project_id,
            'closed_by' => $archive->closed_by,
            'closed_at' => optional($archive->closed_at)?->toIso8601String(),
            'archive_version' => $archive->archive_version,
            'schema_version' => $archive->schema_version,
            'previous_archive_id' => $archive->previous_archive_id,
            'previous_hash' => $archive->previous_hash,
            'summary' => $archive->summary_json,
            'warnings' => $archive->warnings_json,
            'counts' => $archive->counts_json,
            'readiness' => $archive->readiness_json,
            'integrity_hash' => $archive->integrity_hash,
            'verification_status' => $archive->verification_status,
            'verified_at' => optional($archive->verified_at)?->toIso8601String(),
            'correction_reason' => $archive->correction_reason,
            'notes' => $archive->notes,
        ];

        if ($includeSnapshot) {
            $payload['snapshot'] = $archive->snapshot_json;
            $payload['manifest'] = $archive->manifest_json;
        }

        return $payload;
    }

    /**
     * List panel periods.
     *
     * Requires permission: `periods.view`. Global scope lists all periods; scoped users only see periods in projects allowed by `periods.view`. Exposed under `/api/admin/periods` and `/api/panel/periods` aliases.
     *
     * @authenticated
     *
     * @queryParam project_id integer Optional project filter. Must be inside the user scope. Example: 1
     * @queryParam status string Optional lifecycle filter: `planned`, `active`, `closing`, `completed`, `cancelled` or legacy `passive`. Example: active
     *
     * @response 200 {"periods":[{"id":3,"name":"2026 Bahar","status":"active","project":{"id":1,"name":"Diplomasi360"}}]}
     * @response 403 {"message":"Bu islem icin yetkiniz bulunmuyor."}
     */
    public function index(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'periods.view');

        $query = Period::with(['project:id,name,current_period_id', 'latestArchive'])->orderByDesc('created_at');
        $query = $this->scopeManageablePeriods($request, $query, 'periods.view');

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json([
            'periods' => PeriodResource::collection($query->get())->resolve($request),
        ]);
    }

    /**
     * Show a single period workspace payload.
     *
     * Requires `periods.view` in the period project. Includes the immutable
     * lifecycle timeline needed by the panel detail workspace.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $period = $this->resolvePeriodForAction($request, $id, 'periods.view')
            ->load([
                'lifecycleEvents.actor:id,name,surname',
                'latestArchive',
            ]);

        return response()->json([
            'period' => $this->periodPayload($request, $period),
        ]);
    }

    /**
     * Export panel periods.
     *
     * Requires permission: `periods.export`. Global scope exports all periods; scoped users export only periods in permitted projects. Returns a binary CSV/XLSX/PDF/DOCX file depending on `format`.
     *
     * @authenticated
     *
     * @queryParam project_id integer Optional project filter. Example: 1
     * @queryParam status string Optional lifecycle filter: `planned`, `active`, `closing`, `completed`, `cancelled` or legacy `passive`. Example: completed
     * @queryParam format string Optional export format: `csv`, `xlsx`, `pdf`, `docx`, `excel` or `word`. Defaults to csv. Example: pdf
     *
     * @response 200 binary Periods export file.
     * @response 403 {"message":"Bu islem icin yetkiniz bulunmuyor."}
     */
    public function export(Request $request)
    {
        $this->abortUnlessAllowed($request, 'periods.export');

        $query = Period::with('project:id,name')->orderByDesc('created_at');
        $query = $this->scopeManageablePeriods($request, $query, 'periods.export');

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $periods = $query->get();
        $headings = ['ID', 'Proje', 'Donem', 'Baslangic', 'Bitis', 'Baslangic Kredisi', 'Esik', 'Durum'];
        $rows = $periods->map(fn (Period $period) => [
            $period->id,
            $period->project?->name ?? '-',
            $period->name,
            optional($period->start_date)?->format('d.m.Y') ?? '-',
            optional($period->end_date)?->format('d.m.Y') ?? '-',
            $period->credit_start_amount,
            $period->credit_threshold,
            $period->status,
        ])->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'donemler_'.now()->format('Ymd_His'),
            'Donemler',
            $headings,
            $rows,
        );
    }

    /**
     * Create a project period.
     *
     * Requires permission: `periods.create` for the selected project. New periods are planned by default. Explicit active creation additionally requires `periods.activate` and fails when the project already has a current period.
     *
     * @authenticated
     *
     * @bodyParam project_id integer required Project ID. Example: 1
     * @bodyParam name string required Period name. Example: 2026 Bahar
     * @bodyParam start_date date required Start date. Example: 2026-03-01
     * @bodyParam end_date date required End date, after or equal to start date. Example: 2026-06-30
     * @bodyParam credit_start_amount integer required Starting credit amount. Example: 100
     * @bodyParam credit_threshold integer required Critical credit threshold. Example: 75
     * @bodyParam status string Optional compatibility value: `planned`, `passive` or `active`. Defaults to planned. Example: planned
     *
     * @response 201 {"message":"Donem olusturuldu.","period":{"id":3,"name":"2026 Bahar","status":"active"}}
     * @response 403 {"message":"Bu proje icin yetkiniz bulunmuyor."}
     * @response 422 {"message":"The given data was invalid."}
     */
    public function store(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'periods.create');

        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'name' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'credit_start_amount' => 'required|integer|min:0',
            'credit_threshold' => 'required|integer|min:0',
            'status' => 'sometimes|in:planned,passive,active',
        ]);

        $this->abortUnlessProjectAllowed($request, 'periods.create', (int) $validated['project_id']);
        $requestedStatus = $validated['status'] ?? PeriodLifecycleService::PLANNED;

        if ($requestedStatus === PeriodLifecycleService::ACTIVE) {
            $this->abortUnlessAllowed($request, 'periods.activate');
            $this->abortUnlessProjectAllowed($request, 'periods.activate', (int) $validated['project_id']);
        }

        unset($validated['status']);
        $period = DB::transaction(function () use ($validated, $request, $requestedStatus) {
            $period = $this->lifecycleService->createPlanned($validated, $request->user());

            // Gecis doneminde eski panelin acikca `active` gondermesini destekler;
            // yeni kaydin varsayilani her zaman planned'dir ve aktivasyon servis uzerinden yapilir.
            if ($requestedStatus === PeriodLifecycleService::ACTIVE) {
                return $this->lifecycleService->activate(
                    $period->id,
                    $request->user(),
                    'Donem olusturma akisi aktivasyon istedi.',
                );
            }

            return $period;
        });

        return response()->json([
            'message' => 'Donem olusturuldu.',
            'period' => $this->periodPayload($request, $period),
        ], 201);
    }

    /**
     * Update a project period.
     *
     * Requires permission: `periods.update` for the period project. Lifecycle status cannot be changed through this generic update endpoint.
     *
     * @authenticated
     *
     * @urlParam id integer required Period ID. Example: 3
     *
     * @bodyParam name string required Period name. Example: 2026 Bahar
     * @bodyParam start_date date required Start date. Example: 2026-03-01
     * @bodyParam end_date date required End date. Example: 2026-06-30
     * @bodyParam credit_start_amount integer required Starting credit amount. Example: 100
     * @bodyParam credit_threshold integer required Critical credit threshold. Example: 75
     * @bodyParam status string Optional current status for compatibility. A different value is rejected. Example: planned
     *
     * @response 200 {"message":"Donem guncellendi.","period":{"id":3,"status":"passive"}}
     * @response 403 {"message":"Bu proje icin yetkiniz bulunmuyor."}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'periods.update');

        $period = Period::findOrFail($id);

        $this->abortUnlessProjectAllowed($request, 'periods.update', (int) $period->project_id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'credit_start_amount' => 'required|integer|min:0',
            'credit_threshold' => 'required|integer|min:0',
            'status' => 'sometimes|in:planned,active,closing,completed,cancelled,passive',
        ]);

        if (isset($validated['status']) && $validated['status'] !== $period->status) {
            abort(422, 'Donem durumu genel duzenleme formundan degistirilemez. Uygun yasam dongusu islemini kullanin.');
        }

        unset($validated['status']);
        $period = $this->lifecycleService->updateDetails($period->id, $validated, $request->user());

        return response()->json([
            'message' => 'Donem guncellendi.',
            'period' => $this->periodPayload($request, $period),
        ]);
    }

    /**
     * Activate a planned period.
     *
     * Requires `periods.activate` in the period project. The project is locked and activation fails with 409 when another active/closing period exists.
     *
     * @authenticated
     *
     * @urlParam id integer required Period ID. Example: 3
     *
     * @bodyParam reason string Optional activation reason. Example: Yeni donem baslangici.
     *
     * @response 200 {"message":"Donem aktif edildi.","period":{"id":3,"status":"active","lifecycle":{"is_current":true}}}
     * @response 409 {"message":"Bu projenin zaten guncel bir donemi var. Once mevcut donemi kapatin."}
     */
    public function activate(Request $request, int $id): JsonResponse
    {
        $period = $this->resolvePeriodForAction($request, $id, 'periods.activate');
        $validated = $request->validate([
            'reason' => 'nullable|string|max:2000',
        ]);

        $period = $this->lifecycleService->activate(
            $period->id,
            $request->user(),
            $validated['reason'] ?? null,
        );

        return response()->json([
            'message' => 'Donem aktif edildi.',
            'period' => $this->periodPayload($request, $period),
        ]);
    }

    /**
     * Start period closing preparation.
     *
     * Requires `periods.closing.start` in the period project.
     *
     * @authenticated
     *
     * @urlParam id integer required Period ID. Example: 3
     *
     * @bodyParam reason string Optional closing preparation note. Example: Donem sonu kontrolleri baslatildi.
     *
     * @response 200 {"message":"Donem kapanis hazirligina alindi.","period":{"id":3,"status":"closing"}}
     */
    public function startClosing(Request $request, int $id): JsonResponse
    {
        $period = $this->resolvePeriodForAction($request, $id, 'periods.closing.start');
        $validated = $request->validate([
            'reason' => 'nullable|string|max:2000',
        ]);

        $period = $this->lifecycleService->startClosing(
            $period->id,
            $request->user(),
            $validated['reason'] ?? null,
        );

        return response()->json([
            'message' => 'Donem kapanis hazirligina alindi.',
            'period' => $this->periodPayload($request, $period),
        ]);
    }

    /**
     * Cancel closing preparation and return the period to active.
     *
     * Requires `periods.closing.cancel` in the period project.
     *
     * @authenticated
     *
     * @urlParam id integer required Period ID. Example: 3
     *
     * @bodyParam reason string required Cancellation reason. Example: Eksik basvuru kararlari tamamlanacak.
     *
     * @response 200 {"message":"Donem kapanis hazirligindan cikarilip yeniden aktif edildi.","period":{"id":3,"status":"active"}}
     */
    public function cancelClosing(Request $request, int $id): JsonResponse
    {
        $period = $this->resolvePeriodForAction($request, $id, 'periods.closing.cancel');
        $validated = $request->validate([
            'reason' => 'required|string|min:3|max:2000',
        ]);

        $period = $this->lifecycleService->cancelClosing(
            $period->id,
            $request->user(),
            $validated['reason'],
        );

        return response()->json([
            'message' => 'Donem kapanis hazirligindan cikarilip yeniden aktif edildi.',
            'period' => $this->periodPayload($request, $period),
        ]);
    }

    /**
     * Cancel a planned period.
     *
     * Requires `periods.cancel` in the period project.
     *
     * @authenticated
     *
     * @urlParam id integer required Period ID. Example: 3
     *
     * @bodyParam reason string required Cancellation reason. Example: Proje takvimi degisti.
     *
     * @response 200 {"message":"Planlanan donem iptal edildi.","period":{"id":3,"status":"cancelled"}}
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $period = $this->resolvePeriodForAction($request, $id, 'periods.cancel');
        $validated = $request->validate([
            'reason' => 'required|string|min:3|max:2000',
        ]);

        $period = $this->lifecycleService->cancel($period->id, $request->user(), $validated['reason']);

        return response()->json([
            'message' => 'Planlanan donem iptal edildi.',
            'period' => $this->periodPayload($request, $period),
        ]);
    }

    /**
     * Get period closure summary.
     *
     * Requires permission: `periods.view` for the period project. Returns the computed closure payload, warnings and the latest archive snapshot without changing period status.
     *
     * @authenticated
     *
     * @urlParam id integer required Period ID. Example: 3
     *
     * @response 200 {"period":{"id":3,"status":"active"},"summary":{"participants":{"total":50},"credit_snapshot":{"below_threshold_count":4}},"warnings":{"open_programs":1},"latest_archive":null}
     * @response 403 {"message":"Bu proje icin yetkiniz bulunmuyor."}
     */
    public function closureSummary(Request $request, int $id): JsonResponse
    {
        $period = $this->resolvePeriodForAction($request, $id, 'periods.view')->load('latestArchive');
        $payload = $this->buildClosurePayload($period);
        $readiness = $this->readinessService->evaluate($period);

        return response()->json([
            'period' => $this->periodPayload($request, $period),
            'summary' => $payload['summary'],
            'warnings' => $payload['warnings'],
            'readiness' => $readiness,
            'latest_archive' => $this->formatArchive($period->latestArchive),
        ]);
    }

    /**
     * Complete and archive a period.
     *
     * Requires permission: `periods.complete` for the period project. Creates an archive snapshot and completes the period inside the locked lifecycle transaction.
     *
     * @authenticated
     *
     * @urlParam id integer required Period ID. Example: 3
     *
     * @bodyParam notes string Optional archive notes. Example: Donem kapanisi tamamlandi.
     *
     * @response 200 {"message":"Donem tamamlandi ve gecmis donem olarak arsivlendi.","period":{"id":3,"status":"completed"},"archive":{"archive_version":1,"integrity_hash":"hash"}}
     * @response 403 {"message":"Bu proje icin yetkiniz bulunmuyor."}
     */
    public function complete(Request $request, int $id): JsonResponse
    {
        $period = $this->resolvePeriodForAction($request, $id, 'periods.complete');
        $validated = $request->validate([
            'notes' => 'nullable|string|max:5000',
        ]);

        $result = $this->lifecycleService->complete(
            $period->id,
            $request->user(),
            $validated['notes'] ?? null,
            function (Period $lockedPeriod) use ($request, $validated) {
                $payload = $this->buildClosurePayload($lockedPeriod);
                $readiness = $this->readinessService->evaluate($lockedPeriod);
                if (! $readiness['ready']) {
                    $this->monitor->closureBlocked($lockedPeriod, $request->user(), $readiness);
                    throw ValidationException::withMessages([
                        'period' => ['Donem tamamlanamadi. Kapanis engellerini sonuclandirin.'],
                        'blockers' => collect($readiness['blockers'])
                            ->map(fn (array $item) => $item['message'].' ('.$item['count'].')')
                            ->all(),
                    ])->status(422);
                }
                $previousArchiveExists = PeriodArchive::query()->where('period_id', $lockedPeriod->id)->exists();
                $correctionReason = $previousArchiveExists
                    ? PeriodLifecycleEvent::query()
                        ->where('period_id', $lockedPeriod->id)
                        ->where('event_type', 'reopened')
                        ->latest('id')
                        ->value('reason')
                    : null;

                return $this->archiveService->createVersion(
                    $lockedPeriod,
                    $payload,
                    $readiness,
                    $request->user(),
                    $validated['notes'] ?? null,
                    $correctionReason,
                );
            },
        );

        return response()->json([
            'message' => 'Donem tamamlandi ve gecmis donem olarak arsivlendi.',
            'period' => $this->periodPayload($request, $result['period']),
            'archive' => $this->formatArchive($result['archive']),
        ]);
    }

    public function archives(Request $request, int $id): JsonResponse
    {
        $period = $this->resolvePeriodForAction($request, $id, 'periods.view');
        $archives = PeriodArchive::query()
            ->where('period_id', $period->id)
            ->orderByDesc('archive_version')
            ->get()
            ->map(fn (PeriodArchive $archive) => $this->formatArchive($archive));

        return response()->json(['period' => $this->periodPayload($request, $period), 'archives' => $archives]);
    }

    public function archive(Request $request, int $id, int $archiveId): JsonResponse
    {
        $period = $this->resolvePeriodForAction($request, $id, 'periods.view');
        $archive = PeriodArchive::query()->where('period_id', $period->id)->findOrFail($archiveId);

        return response()->json(['archive' => $this->formatArchive($archive, true)]);
    }

    public function verifyArchive(Request $request, int $id, int $archiveId): JsonResponse
    {
        $period = $this->resolvePeriodForAction($request, $id, 'periods.archive.verify');
        $archive = PeriodArchive::query()
            ->with('previousArchive')
            ->where('period_id', $period->id)
            ->findOrFail($archiveId);

        return response()->json([
            'message' => 'Arsiv butunluk dogrulamasi tamamlandi.',
            'verification' => $this->archiveService->verify($archive, $request->user()),
        ]);
    }

    /**
     * Reopen a completed period.
     *
     * Requires restricted permission: `periods.reopen` for the period project. A mandatory reason is recorded in the lifecycle audit trail.
     *
     * @authenticated
     *
     * @urlParam id integer required Period ID. Example: 3
     *
     * @bodyParam target_status string Optional next status: `planned` or `active`. Defaults to planned. Example: planned
     * @bodyParam reason string required Reopen reason. Example: Arsivdeki ogrenci sonucu duzeltilecek.
     *
     * @response 200 {"message":"Donem yeniden aktif edildi.","period":{"id":3,"status":"active"}}
     * @response 403 {"message":"Bu proje icin yetkiniz bulunmuyor."}
     */
    public function reopen(Request $request, int $id): JsonResponse
    {
        $period = $this->resolvePeriodForAction($request, $id, 'periods.reopen');

        $validated = $request->validate([
            'target_status' => 'nullable|in:planned,active',
            'status' => 'nullable|in:active,passive',
            'reason' => 'required|string|min:10|max:5000',
        ]);

        $nextStatus = $validated['target_status']
            ?? (($validated['status'] ?? null) === 'active' ? 'active' : 'planned');

        $period = $this->lifecycleService->reopen(
            $period->id,
            $nextStatus,
            $request->user(),
            $validated['reason'],
        );

        return response()->json([
            'message' => $nextStatus === 'active' ? 'Donem yeniden aktif edildi.' : 'Donem planlanan duruma yeniden acildi.',
            'period' => $this->periodPayload($request, $period),
        ]);
    }
}
