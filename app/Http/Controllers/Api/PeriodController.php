<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Assignment;
use App\Models\Certificate;
use App\Models\DigitalBohca;
use App\Models\FinancialTransaction;
use App\Models\KpdAppointment;
use App\Models\KpdReport;
use App\Models\Period;
use App\Models\PeriodArchive;
use App\Models\Program;
use App\Models\ProjectModule;
use App\Models\VolunteerOpportunity;
use App\Services\PermissionResolver;
use App\Support\AdminExportResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PeriodController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {
    }

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

        $period = Period::with('project:id,name')->findOrFail($id);

        $this->abortUnlessProjectAllowed($request, $permission, (int) $period->project_id);

        return $period;
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
                'student' => $participant->user ? trim($participant->user->name . ' ' . $participant->user->surname) : 'Silinmis kullanici',
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

    private function integrityHash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function formatArchive(?PeriodArchive $archive): ?array
    {
        if (! $archive) {
            return null;
        }

        return [
            'id' => $archive->id,
            'period_id' => $archive->period_id,
            'project_id' => $archive->project_id,
            'closed_by' => $archive->closed_by,
            'closed_at' => optional($archive->closed_at)?->toIso8601String(),
            'archive_version' => $archive->archive_version,
            'summary' => $archive->summary_json,
            'warnings' => $archive->warnings_json,
            'counts' => $archive->counts_json,
            'integrity_hash' => $archive->integrity_hash,
            'notes' => $archive->notes,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'periods.view');

        $query = Period::with('project:id,name')->orderByDesc('created_at');
        $query = $this->scopeManageablePeriods($request, $query, 'periods.view');

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json([
            'periods' => $query->get(),
        ]);
    }

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
            'donemler_' . now()->format('Ymd_His'),
            'Donemler',
            $headings,
            $rows,
        );
    }

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
            'status' => 'required|in:active,passive,completed',
        ]);

        $this->abortUnlessProjectAllowed($request, 'periods.create', (int) $validated['project_id']);

        if ($validated['status'] === 'active') {
            Period::where('project_id', $validated['project_id'])
                ->where('status', 'active')
                ->update(['status' => 'passive']);
        }

        $period = Period::create($validated)->load('project');

        return response()->json([
            'message' => 'Donem olusturuldu.',
            'period' => $period,
        ], 201);
    }

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
            'status' => 'required|in:active,passive,completed',
        ]);

        if ($validated['status'] === 'active') {
            Period::where('project_id', $period->project_id)
                ->where('id', '!=', $period->id)
                ->where('status', 'active')
                ->update(['status' => 'passive']);
        }

        $period->update($validated);

        return response()->json([
            'message' => 'Donem guncellendi.',
            'period' => $period->fresh('project'),
        ]);
    }

    public function closureSummary(Request $request, int $id): JsonResponse
    {
        $period = $this->resolvePeriodForAction($request, $id, 'periods.view')->load('latestArchive');
        $payload = $this->buildClosurePayload($period);

        return response()->json([
            'period' => $period,
            'summary' => $payload['summary'],
            'warnings' => $payload['warnings'],
            'latest_archive' => $this->formatArchive($period->latestArchive),
        ]);
    }

    public function complete(Request $request, int $id): JsonResponse
    {
        $period = $this->resolvePeriodForAction($request, $id, 'periods.update');
        $validated = $request->validate([
            'notes' => 'nullable|string|max:5000',
        ]);

        $archive = DB::transaction(function () use ($period, $request, $validated) {
            $payload = $this->buildClosurePayload($period);
            $closedAt = now();
            $archiveVersion = ((int) PeriodArchive::query()
                ->where('period_id', $period->id)
                ->max('archive_version')) + 1;
            $hashPayload = [
                'period_id' => $period->id,
                'project_id' => $period->project_id,
                'closed_at' => $closedAt->toIso8601String(),
                'archive_version' => $archiveVersion,
                'summary' => $payload['summary'],
                'warnings' => $payload['warnings'],
                'counts' => $payload['counts'],
            ];

            $archive = PeriodArchive::query()->create([
                'period_id' => $period->id,
                'project_id' => $period->project_id,
                'closed_by' => $request->user()?->id,
                'closed_at' => $closedAt,
                'archive_version' => $archiveVersion,
                'summary_json' => $payload['summary'],
                'warnings_json' => $payload['warnings'],
                'counts_json' => $payload['counts'],
                'integrity_hash' => $this->integrityHash($hashPayload),
                'notes' => $validated['notes'] ?? null,
            ]);

            $period->update(['status' => 'completed']);

            return $archive;
        });

        return response()->json([
            'message' => 'Donem tamamlandi ve gecmis donem olarak arsivlendi.',
            'period' => $period->fresh('project'),
            'archive' => $this->formatArchive($archive),
        ]);
    }

    public function reopen(Request $request, int $id): JsonResponse
    {
        $period = $this->resolvePeriodForAction($request, $id, 'periods.update');

        $validated = $request->validate([
            'status' => 'nullable|in:active,passive',
        ]);

        $nextStatus = $validated['status'] ?? 'passive';

        DB::transaction(function () use ($period, $nextStatus) {
            if ($nextStatus === 'active') {
                Period::where('project_id', $period->project_id)
                    ->where('id', '!=', $period->id)
                    ->where('status', 'active')
                    ->update(['status' => 'passive']);
            }

            $period->update(['status' => $nextStatus]);
        });

        return response()->json([
            'message' => $nextStatus === 'active' ? 'Donem yeniden aktif edildi.' : 'Donem yeniden pasife alindi.',
            'period' => $period->fresh('project'),
        ]);
    }
}
