<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Concerns\ResolvesProjectPeriodContext;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Attendance;
use App\Models\CalendarEvent;
use App\Models\Certificate;
use App\Models\CommunicationLog;
use App\Models\CreditLog;
use App\Models\Feedback;
use App\Models\FinancialTransaction;
use App\Models\Participant;
use App\Models\Period;
use App\Models\Program;
use App\Models\Project;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\PeriodLifecycleService;
use App\Services\PermissionResolver;
use App\Support\AdminExportResponder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

/**
 * @group Admin Dashboard
 */
class AdminDashboardController extends Controller
{
    use AuthorizesGranularPermissions;
    use ResolvesProjectPeriodContext;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {}

    /**
     * Dashboard Ã¶zetine eriÅŸim: en az bir operasyonel gÃ¶rÃ¼nÃ¼rlÃ¼k izni gerekir.
     */
    private function assertCanViewDashboard(Request $request): void
    {
        $user = $request->user();
        $any = $this->permissionResolver->hasPermission($user, 'programs.view')
            || $this->permissionResolver->hasPermission($user, 'programs.community_event.view')
            || $this->permissionResolver->hasPermission($user, 'programs.logistics.view')
            || $this->permissionResolver->hasPermission($user, 'applications.view')
            || $this->permissionResolver->hasPermission($user, 'financial.view')
            || $this->permissionResolver->hasPermission($user, 'support.view')
            || $this->permissionResolver->hasPermission($user, 'certificates.view')
            || $this->permissionResolver->hasPermission($user, 'dashboard.admin.view')
            || $this->permissionResolver->hasPermission($user, 'dashboard.coordinator.view')
            || $this->permissionResolver->hasPermission($user, 'dashboard.staff.view');

        abort_unless($any, 403, 'Dashboard verilerini goruntuleme yetkiniz bulunmuyor.');
    }

    /** @return list<string> */
    private function dashboardContextPermissions(): array
    {
        return [
            'dashboard.admin.view',
            'dashboard.coordinator.view',
            'dashboard.staff.view',
            'projects.view',
            'programs.view',
            'programs.community_event.view',
            'programs.logistics.view',
            'applications.view',
            'financial.view',
            'support.view',
            'certificates.view',
            'assignments.view',
            'projects.participants.view',
        ];
    }

    private function dashboardProgramMode(User $user): string
    {
        if ($this->permissionResolver->hasGlobalScope($user, 'programs.view')) {
            return 'all';
        }

        if ($this->permissionResolver->hasPermission($user, 'programs.view')) {
            $hasCoreOperation = collect([
                'programs.create',
                'programs.update',
                'programs.complete',
                'programs.attendance.view',
                'programs.attendance.manage',
                'programs.qr.manage',
                'programs.export',
            ])->contains(fn (string $permission) => $this->permissionResolver->hasPermission($user, $permission));

            return ! $hasCoreOperation && $this->permissionResolver->hasPermission($user, 'programs.media.upload')
                ? 'media'
                : 'core';
        }

        if ($this->permissionResolver->hasPermission($user, 'programs.community_event.view')) {
            return 'community_event';
        }

        return $this->permissionResolver->hasPermission($user, 'programs.logistics.view')
            ? 'logistics'
            : 'none';
    }

    private function dashboardProjectsPayload(Request $request): array
    {
        $user = $request->user();
        $permissions = $this->dashboardContextPermissions();
        $isGlobal = collect($permissions)->contains(
            fn (string $permission) => $this->permissionResolver->hasGlobalScope($user, $permission)
        );
        $projectIds = $isGlobal
            ? null
            : collect($permissions)
                ->flatMap(fn (string $permission) => $this->permissionResolver->projectIdsForPermission($user, $permission))
                ->filter(fn ($id) => is_numeric($id))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

        return Project::query()
            ->with([
                'periods' => fn ($query) => $query->orderByDesc('start_date'),
                'currentPeriod',
            ])
            ->when(! $isGlobal, fn ($query) => $query->whereIn('id', $projectIds === [] ? [-1] : $projectIds))
            ->orderBy('name')
            ->get(['id', 'current_period_id', 'name', 'slug', 'type'])
            ->map(function (Project $project) {
                $currentPeriod = $project->currentPeriodOrLegacy();
                $periodPayload = fn (Period $period) => [
                    ...$period->only(['id', 'name', 'status', 'start_date', 'end_date']),
                    'lifecycle' => [
                        'is_current' => (int) $project->current_period_id === (int) $period->id,
                        'is_archive_mode' => PeriodLifecycleService::isArchiveStatus($period->status),
                        'write_capabilities' => PeriodLifecycleService::writeCapabilitiesForStatus($period->status),
                    ],
                ];

                return [
                    'id' => $project->id,
                    'name' => $project->name,
                    'slug' => $project->slug,
                    'type' => $project->type,
                    'active_period' => $currentPeriod ? $periodPayload($currentPeriod) : null,
                    'current_period' => $currentPeriod ? $periodPayload($currentPeriod) : null,
                    'periods' => $project->periods->map($periodPayload)->values(),
                ];
            })
            ->values()
            ->all();
    }

    private function selectedPeriodAnalytics(?Period $period): ?array
    {
        if (! $period) {
            return null;
        }

        $programIds = Program::query()->where('period_id', $period->id)->pluck('id');
        $assignmentIds = Assignment::query()->where('period_id', $period->id)->pluck('id');
        $feedbacks = Feedback::query()
            ->whereIn('program_id', $programIds)
            ->get(['responses']);
        $numericValues = $feedbacks
            ->flatMap(function (Feedback $feedback) {
                return collect($feedback->responses ?? [])
                    ->map(fn ($value) => is_numeric($value) ? (float) $value : null)
                    ->filter(fn ($value) => $value !== null);
            })
            ->values();

        return [
            'period' => [
                'id' => $period->id,
                'name' => $period->name,
                'status' => $period->status,
                'start_date' => optional($period->start_date)?->toDateString(),
                'end_date' => optional($period->end_date)?->toDateString(),
            ],
            'participants_total' => Participant::query()->where('period_id', $period->id)->count(),
            'participants_active' => Participant::query()->where('period_id', $period->id)->where('status', 'active')->count(),
            'programs_total' => $programIds->count(),
            'attendance_present' => Attendance::query()->whereIn('program_id', $programIds)->where('is_valid', true)->count(),
            'applications_total' => Application::query()->where('period_id', $period->id)->count(),
            'credit_log_total' => CreditLog::query()->where('period_id', $period->id)->sum('amount'),
            'assignments_total' => $assignmentIds->count(),
            'assignment_submissions_total' => AssignmentSubmission::query()->whereIn('assignment_id', $assignmentIds)->count(),
            'feedback_count' => $feedbacks->count(),
            'feedback_numeric_average' => $numericValues->isNotEmpty() ? round($numericValues->avg(), 2) : null,
            'financial_total' => FinancialTransaction::query()->where('period_id', $period->id)->where('status', '!=', 'rejected')->sum('amount'),
            'certificates_total' => Certificate::query()->where('period_id', $period->id)->count(),
        ];
    }

    /**
     * Get admin dashboard statistics.
     *
     * Requires at least one dashboard/operational visibility permission: `dashboard.admin.view`, `dashboard.coordinator.view`, `dashboard.staff.view`, `programs.view`, `applications.view`, `financial.view`, `support.view` or `certificates.view`. Global scope returns all projects; scoped users only receive projects allowed by their action+scope permissions. Optional project/period filters are validated through PermissionResolver and completed periods are returned in archive mode.
     *
     * @authenticated
     *
     * @queryParam project_id integer Optional project filter. The user must be allowed to access this project for one of the dashboard permissions. Example: 1
     * @queryParam period_id integer Optional period filter. Must belong to the selected/allowed project. Example: 3
     *
     * @response 200 {"students":{"active":42},"programs":{"monthly_total":8,"monthly_completed":3,"monthly_upcoming":5},"financials":{"monthly_expense":12500,"expense_change_percent":12.5,"pending_count":2},"pending":{"applications":4,"support":1,"financials":2},"stats_scope":"projects","dashboard_context":{"project_id":1,"period_id":3,"archive_mode":false,"projects":[]}}
     * @response 403 {"message":"Dashboard verilerini goruntuleme yetkiniz bulunmuyor."}
     */
    public function stats(Request $request)
    {
        $this->assertCanViewDashboard($request);

        $user = $request->user();
        $validated = $request->validate([
            'project_id' => 'nullable|integer|exists:projects,id',
            'period_id' => 'nullable|integer|exists:periods,id',
        ]);
        $selectedProjectId = ! empty($validated['project_id']) ? (int) $validated['project_id'] : null;
        $selectedPeriodId = ! empty($validated['period_id']) ? (int) $validated['period_id'] : null;
        $selectedPeriod = null;

        if ($selectedProjectId !== null || $selectedPeriodId !== null) {
            $context = $this->resolveProjectPeriodContextForAnyPermission(
                $request,
                $this->dashboardContextPermissions(),
                $selectedProjectId,
                $selectedPeriodId
            );
            $selectedProjectId = $context->projectId;
            $selectedPeriodId = $context->periodId;
            $selectedPeriod = $selectedPeriodId ? Period::query()->find($selectedPeriodId) : null;
        }

        $isGlobal = $this->permissionResolver->hasGlobalScope($user, 'dashboard.admin.view')
            || $this->permissionResolver->hasGlobalScope($user, 'projects.view');
        $projectIdsByPermission = [
            'programs' => $isGlobal ? null : collect([
                'programs.view',
                'programs.community_event.view',
                'programs.logistics.view',
            ])->flatMap(fn (string $permission) => $this->permissionResolver->projectIdsForPermission($user, $permission))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all(),
            'applications' => $isGlobal ? null : $this->permissionResolver->projectIdsForPermission($user, 'applications.view'),
            'financial' => $isGlobal ? null : $this->permissionResolver->projectIdsForPermission($user, 'financial.view'),
            'support' => $isGlobal ? null : $this->permissionResolver->projectIdsForPermission($user, 'support.view'),
            'certificates' => $isGlobal ? null : $this->permissionResolver->projectIdsForPermission($user, 'certificates.view'),
            'projects' => $isGlobal ? null : $this->permissionResolver->projectIdsForPermission($user, 'projects.view'),
        ];

        $now = Carbon::now();
        $startOfMonth = $selectedPeriod?->start_date
            ? $selectedPeriod->start_date->copy()->startOfDay()
            : $now->copy()->startOfMonth();
        $endOfMonth = $selectedPeriod?->end_date
            ? $selectedPeriod->end_date->copy()->endOfDay()
            : $now->copy()->endOfMonth();

        $scopeParticipant = Participant::query()
            ->when(! $isGlobal, fn ($q) => $q->whereIn('project_id', $projectIdsByPermission['projects'] ?? [-1]))
            ->when($selectedProjectId, fn ($q) => $q->where('project_id', $selectedProjectId))
            ->when($selectedPeriodId, fn ($q) => $q->where('period_id', $selectedPeriodId));
        $programMode = $this->dashboardProgramMode($user);
        $scopeProgram = Program::query()
            ->when(! $isGlobal, fn ($q) => $q->whereIn('project_id', $projectIdsByPermission['programs'] ?? [-1]))
            ->when($programMode === 'core', fn ($q) => $q->where(fn ($builder) => $builder
                ->whereNull('program_kind')
                ->orWhere('program_kind', Program::KIND_CORE_PROGRAM)))
            ->when($programMode === 'community_event', fn ($q) => $q->where('program_kind', Program::KIND_COMMUNITY_EVENT))
            ->when($programMode === 'none', fn ($q) => $q->whereRaw('1 = 0'))
            ->when($selectedProjectId, fn ($q) => $q->where('project_id', $selectedProjectId))
            ->when($selectedPeriodId, fn ($q) => $q->where('period_id', $selectedPeriodId));
        $scopeFinancial = FinancialTransaction::query()
            ->when(! $isGlobal, fn ($q) => $q->whereIn('project_id', $projectIdsByPermission['financial'] ?? [-1]))
            ->when($selectedProjectId, fn ($q) => $q->where('project_id', $selectedProjectId))
            ->when($selectedPeriodId, fn ($q) => $q->where('period_id', $selectedPeriodId));
        $scopeApplication = Application::query()
            ->when(! $isGlobal, fn ($q) => $q->whereIn('project_id', $projectIdsByPermission['applications'] ?? [-1]))
            ->when($selectedProjectId, fn ($q) => $q->where('project_id', $selectedProjectId))
            ->when($selectedPeriodId, fn ($q) => $q->where('period_id', $selectedPeriodId));
        $scopeSupport = SupportTicket::query()
            ->when(! $isGlobal, fn ($q) => $q->whereIn('project_id', $projectIdsByPermission['support'] ?? [-1]))
            ->when($selectedProjectId, fn ($q) => $q->where('project_id', $selectedProjectId))
            ->when($selectedPeriodId, fn ($q) => $q->where('period_id', $selectedPeriodId));
        $scopeCommunication = CommunicationLog::query()
            ->when(! $isGlobal, fn ($q) => $q->whereIn('project_id', $projectIdsByPermission['projects'] ?? [-1]))
            ->when($selectedProjectId, fn ($q) => $q->where('project_id', $selectedProjectId));

        $activeStudentCount = $selectedPeriodId
            ? (clone $scopeParticipant)->count()
            : (clone $scopeParticipant)->where('status', 'active')->count();

        $monthlyPrograms = (clone $scopeProgram)->whereBetween('start_at', [$startOfMonth, $endOfMonth])->count();
        $monthlyCompleted = (clone $scopeProgram)->whereBetween('start_at', [$startOfMonth, $endOfMonth])
            ->where('status', 'completed')->count();
        $monthlyUpcoming = (clone $scopeProgram)->where('start_at', '>=', $now)
            ->where('start_at', '<=', $endOfMonth)->count();

        $monthlyExpense = (clone $scopeFinancial)->where('status', '!=', 'rejected')
            ->whereBetween('submitted_at', [$startOfMonth, $endOfMonth])
            ->sum('amount');

        $prevMonthExpense = (clone $scopeFinancial)->where('status', '!=', 'rejected')
            ->whereBetween('submitted_at', [
                $startOfMonth->copy()->subMonth(),
                $endOfMonth->copy()->subMonth(),
            ])->sum('amount');

        $expenseChangePercent = $prevMonthExpense > 0
            ? round((($monthlyExpense - $prevMonthExpense) / $prevMonthExpense) * 100, 1)
            : null;

        $pendingApplications = (clone $scopeApplication)->where('status', 'pending')->count();
        $pendingFinancials = (clone $scopeFinancial)->where('status', 'pending')->count();
        $pendingSupport = (clone $scopeSupport)->where('status', 'open')->count();

        $creditRiskQuery = Participant::query()
            ->with(['user:id,name,surname,email', 'project:id,name,slug', 'period:id,name,credit_threshold'])
            ->where('status', 'active')
            ->when(! $isGlobal, fn ($q) => $q->whereIn('project_id', $projectIdsByPermission['projects'] ?? [-1]))
            ->when($selectedProjectId, fn ($q) => $q->where('project_id', $selectedProjectId))
            ->when($selectedPeriodId, fn ($q) => $q->where('period_id', $selectedPeriodId))
            ->whereRaw('credit < COALESCE((SELECT credit_threshold FROM periods WHERE periods.id = participants.period_id), 75)');
        $creditRiskCount = (clone $creditRiskQuery)->count();
        $creditRiskParticipants = (clone $creditRiskQuery)
            ->orderBy('credit')
            ->take(5)
            ->get()
            ->map(fn (Participant $participant) => [
                'id' => $participant->id,
                'student' => $participant->user ? trim($participant->user->name.' '.$participant->user->surname) : 'Silinmis kullanici',
                'email' => $participant->user?->email,
                'project' => $participant->project ? [
                    'id' => $participant->project->id,
                    'name' => $participant->project->name,
                    'slug' => $participant->project->slug,
                ] : null,
                'period' => $participant->period ? [
                    'id' => $participant->period->id,
                    'name' => $participant->period->name,
                    'credit_threshold' => (int) $participant->period->credit_threshold,
                ] : null,
                'credit' => (int) $participant->credit,
                'threshold' => (int) ($participant->period?->credit_threshold ?? 75),
            ]);

        $projectsQuery = Project::query()
            ->when(! $isGlobal, fn ($q) => $q->whereIn('id', $projectIdsByPermission['projects'] ?? [-1]))
            ->when($selectedProjectId, fn ($q) => $q->where('id', $selectedProjectId));
        $periodAwareCount = fn ($query) => $query->when($selectedPeriodId, fn ($q) => $q->where('period_id', $selectedPeriodId));
        $projects = $projectsQuery->withCount([
            'participants as total_participants_count' => fn ($q) => $periodAwareCount($q),
            'participants as active_participants_count' => fn ($q) => $periodAwareCount($q)->where('status', 'active'),
            'participants as waitlist_participants_count' => fn ($q) => $periodAwareCount($q)->where('status', 'waitlist'),
            'participants as graduated_participants_count' => fn ($q) => $periodAwareCount($q)
                ->where(fn ($builder) => $builder->where('status', 'graduated')->orWhere('graduation_status', 'graduated')),
            'participants as not_completed_participants_count' => fn ($q) => $periodAwareCount($q)
                ->where(fn ($builder) => $builder->where('status', 'failed')->orWhere('graduation_status', 'not_completed')),
        ])->get(['id', 'name', 'slug', 'quota']);

        $projectOccupancy = $projects->map(function ($p) {
            $capacity = $p->quota !== null ? (int) $p->quota : null;
            $active = (int) $p->active_participants_count;

            return [
                'id' => $p->id,
                'name' => $p->name,
                'active' => $active,
                'total' => (int) $p->total_participants_count,
                'waitlist' => (int) $p->waitlist_participants_count,
                'graduates' => (int) $p->graduated_participants_count,
                'not_completed' => (int) $p->not_completed_participants_count,
                'max' => $capacity,
                'capacity' => $capacity,
                'rate' => $capacity && $capacity > 0
                    ? round(($active / $capacity) * 100)
                    : null,
            ];
        });

        $monthlySms = (clone $scopeCommunication)->where('type', 'sms')
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->selectRaw('project_id, COUNT(*) as count')
            ->groupBy('project_id')
            ->with('project:id,name')
            ->get();

        $totalSmsSent = (clone $scopeCommunication)->where('type', 'sms')
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->count();

        $upcomingPrograms = (clone $scopeProgram)->with('project:id,name,slug')
            ->where('start_at', '>=', $now)
            ->where('start_at', '<=', $now->copy()->addDays(7))
            ->orderBy('start_at')
            ->take(5)
            ->get(['id', 'title', 'start_at', 'location', 'project_id', 'status']);

        $assignedTasks = CalendarEvent::query()
            ->with(['project:id,name,slug', 'program:id,title,start_at,location,project_id,status'])
            ->whereJsonContains('assigned_users', (int) $user->id)
            ->when($selectedProjectId, fn ($q) => $q->where('project_id', $selectedProjectId))
            ->when($selectedPeriodId, fn ($q) => $q->where('period_id', $selectedPeriodId))
            ->where('start_at', '>=', $now)
            ->orderBy('start_at')
            ->take(5)
            ->get()
            ->map(fn (CalendarEvent $event) => [
                'id' => $event->id,
                'program_id' => $event->program_id,
                'event_type' => $event->event_type ?? ($event->program_id ? 'program' : 'meeting'),
                'title' => $event->program?->title ?? $event->title,
                'description' => $event->program?->description ?? $event->description,
                'start_at' => optional($event->program?->start_at ?? $event->start_at)?->toIso8601String(),
                'end_at' => optional($event->program?->end_at ?? $event->end_at)?->toIso8601String(),
                'location' => $event->program?->location ?? $event->location,
                'status' => $event->program?->status ?? $event->status,
                'project' => $event->project ? [
                    'id' => $event->project->id,
                    'name' => $event->project->name,
                    'slug' => $event->project->slug,
                ] : null,
            ]);

        $userStats = $isGlobal
            ? User::selectRaw('role, COUNT(*) as count')
                ->whereNull('deleted_at')
                ->groupBy('role')
                ->pluck('count', 'role')
            : collect();

        $financialByCategory = (clone $scopeFinancial)->where('status', '!=', 'rejected')
            ->whereBetween('submitted_at', [$startOfMonth, $endOfMonth])
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy('category')
            ->get()
            ->map(fn ($row) => [
                'key' => (string) $row->category,
                'label' => match ((string) $row->category) {
                    'transport' => 'Ulasim',
                    'food' => 'Yemek',
                    'print' => 'Baski',
                    'education' => 'Egitim',
                    'other' => 'Diger',
                    default => (string) $row->category,
                },
                'value' => round((float) $row->total, 2),
            ])
            ->values();

        $financialByProjectRows = (clone $scopeFinancial)->where('status', '!=', 'rejected')
            ->whereBetween('submitted_at', [$startOfMonth, $endOfMonth])
            ->selectRaw('project_id, SUM(amount) as total')
            ->groupBy('project_id')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $projectIdsForSpend = $financialByProjectRows->pluck('project_id')->filter()->unique()->values();
        $projectIdToName = $projectIdsForSpend->isNotEmpty()
            ? Project::query()->whereIn('id', $projectIdsForSpend)->pluck('name', 'id')
            : collect();

        $financialByProject = $financialByProjectRows->map(function ($row) use ($projectIdToName) {
            $pid = $row->project_id;

            return [
                'name' => $pid ? (string) ($projectIdToName[$pid] ?? ('Proje #'.$pid)) : 'Tanimlanmamis proje',
                'value' => round((float) $row->total, 2),
            ];
        })->values();

        $communicationByType = (clone $scopeCommunication)->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->selectRaw('type, COUNT(*) as count')
            ->groupBy('type')
            ->get()
            ->map(fn ($row) => [
                'type' => (string) $row->type,
                'label' => $row->type === 'email' ? 'E-posta (log)' : 'SMS (log)',
                'count' => (int) $row->count,
            ])
            ->values();

        $programsByStatus = (clone $scopeProgram)
            ->whereBetween('start_at', [$startOfMonth, $endOfMonth])
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->get()
            ->map(fn ($row) => [
                'status' => (string) $row->status,
                'count' => (int) $row->count,
            ])
            ->values();

        return response()->json([
            'students' => [
                'active' => $activeStudentCount,
            ],
            'programs' => [
                'monthly_total' => $monthlyPrograms,
                'monthly_completed' => $monthlyCompleted,
                'monthly_upcoming' => $monthlyUpcoming,
            ],
            'financials' => [
                'monthly_expense' => (float) $monthlyExpense,
                'expense_change_percent' => $expenseChangePercent,
                'pending_count' => $pendingFinancials,
            ],
            'pending' => [
                'applications' => $pendingApplications,
                'support' => $pendingSupport,
                'financials' => $pendingFinancials,
            ],
            'credit_risk' => [
                'count' => $creditRiskCount,
                'participants' => $creditRiskParticipants,
            ],
            'project_occupancy' => $projectOccupancy,
            'sms' => [
                'total_this_month' => $totalSmsSent,
                'by_project' => $monthlySms,
            ],
            'charts' => [
                'period' => [
                    'label' => $selectedPeriod?->name ?? $startOfMonth->translatedFormat('F Y'),
                    'start' => optional($selectedPeriod?->start_date)?->toIso8601String() ?? $startOfMonth->toIso8601String(),
                    'end' => optional($selectedPeriod?->end_date)?->toIso8601String() ?? $endOfMonth->toIso8601String(),
                ],
                'financial_by_category' => $financialByCategory,
                'financial_by_project' => $financialByProject,
                'communication_by_type' => $communicationByType,
                'programs_by_status' => $programsByStatus,
            ],
            'upcoming_programs' => $upcomingPrograms,
            'assigned_tasks' => $assignedTasks,
            'user_stats' => $userStats,
            'stats_scope' => $isGlobal ? 'global' : 'projects',
            'dashboard_context' => [
                'project_id' => $selectedProjectId,
                'period_id' => $selectedPeriodId,
                'archive_mode' => PeriodLifecycleService::isArchiveStatus($selectedPeriod?->status),
                'projects' => $this->dashboardProjectsPayload($request),
            ],
            'period_analytics' => $this->selectedPeriodAnalytics($selectedPeriod),
        ]);
    }

    /**
     * Export credit-risk participants.
     *
     * Requires dashboard visibility for the selected project/period context. Global users export all visible risk rows; scoped users export only participants in projects allowed by their dashboard/project permissions. Returns a binary CSV/XLSX/PDF/DOCX file depending on `format`.
     *
     * @authenticated
     *
     * @queryParam project_id integer Optional project filter validated against the user dashboard scope. Example: 1
     * @queryParam period_id integer Optional period filter validated against the selected/allowed project. Example: 3
     * @queryParam format string Optional export format: `csv`, `xlsx`, `pdf`, `docx`, `excel` or `word`. Defaults to csv. Example: xlsx
     *
     * @response 200 binary Credit-risk export file.
     * @response 403 {"message":"Dashboard verilerini goruntuleme yetkiniz bulunmuyor."}
     */
    public function exportCreditRisk(Request $request)
    {
        $this->assertCanViewDashboard($request);

        $user = $request->user();
        $validated = $request->validate([
            'project_id' => 'nullable|integer|exists:projects,id',
            'period_id' => 'nullable|integer|exists:periods,id',
        ]);
        $selectedProjectId = ! empty($validated['project_id']) ? (int) $validated['project_id'] : null;
        $selectedPeriodId = ! empty($validated['period_id']) ? (int) $validated['period_id'] : null;

        if ($selectedProjectId !== null || $selectedPeriodId !== null) {
            $context = $this->resolveProjectPeriodContextForAnyPermission(
                $request,
                $this->dashboardContextPermissions(),
                $selectedProjectId,
                $selectedPeriodId
            );
            $selectedProjectId = $context->projectId;
            $selectedPeriodId = $context->periodId;
        }

        $permissions = $this->dashboardContextPermissions();
        $isGlobal = collect($permissions)->contains(
            fn (string $permission) => $this->permissionResolver->hasGlobalScope($user, $permission)
        );
        $projectIds = $isGlobal
            ? null
            : collect($permissions)
                ->flatMap(fn (string $permission) => $this->permissionResolver->projectIdsForPermission($user, $permission))
                ->filter(fn ($id) => is_numeric($id))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

        $participants = Participant::query()
            ->with(['user:id,name,surname,email', 'project:id,name', 'period:id,name,credit_threshold'])
            ->where('status', 'active')
            ->when(! $isGlobal, fn ($query) => $query->whereIn('project_id', $projectIds ?: [-1]))
            ->when($selectedProjectId, fn ($query) => $query->where('project_id', $selectedProjectId))
            ->when($selectedPeriodId, fn ($query) => $query->where('period_id', $selectedPeriodId))
            ->whereRaw('credit < COALESCE((SELECT credit_threshold FROM periods WHERE periods.id = participants.period_id), 75)')
            ->orderBy('credit')
            ->get();

        $headings = ['Katilimci', 'E-posta', 'Proje', 'Donem', 'Mevcut Kredi', 'Esik', 'Risk Farki'];
        $rows = $participants->map(function (Participant $participant) {
            $threshold = (int) ($participant->period?->credit_threshold ?? 75);
            $credit = (int) $participant->credit;

            return [
                $participant->user ? trim($participant->user->name.' '.$participant->user->surname) : 'Silinmis kullanici',
                $participant->user?->email ?? '-',
                $participant->project?->name ?? '-',
                $participant->period?->name ?? '-',
                $credit,
                $threshold,
                max($threshold - $credit, 0),
            ];
        })->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'kritik_kredi_riski_'.now()->format('Ymd_His'),
            'Kritik Kredi Riski',
            $headings,
            $rows,
        );
    }

    /**
     * List panel activity logs.
     *
     * Requires permission: `logs.view`. Users with global scope can see all activity logs; scoped users see their own logs plus permission matrix logs. The endpoint returns paginated logs, summary counters and filter options for the log screen.
     *
     * @authenticated
     *
     * @queryParam log_name string Optional log source filter. Example: audit
     * @queryParam event string Optional event/action filter. Example: updated
     * @queryParam outcome string Optional outcome filter: `success` or `denied_or_failed`. Example: success
     * @queryParam status_code integer Optional HTTP status code filter. Example: 403
     * @queryParam search string Optional text search in description, event, source, subject and properties. Example: basvuru
     * @queryParam date_from date Optional start date. Example: 2026-06-01
     * @queryParam date_to date Optional end date. Example: 2026-06-30
     * @queryParam per_page integer Optional page size between 5 and 100. Example: 25
     *
     * @response 200 {"logs":{"data":[]},"summary":{"total":0,"success":0,"failed":0,"sources":[],"events":[]},"filters":{"log_names":[],"events":[]}}
     * @response 403 {"message":"Bu islem icin yetkiniz bulunmuyor."}
     */
    public function activityLogs(Request $request)
    {
        $this->abortUnlessAllowed($request, 'logs.view');
        $actor = $request->user();

        try {
            $validated = $this->validatedActivityLogFilters($request);
            $query = $this->activityLogQuery($request, 'logs.view', $validated);
            $summaryQuery = clone $query;
            $perPage = (int) ($validated['per_page'] ?? 25);
            $logs = $query->paginate($perPage);

            return response()->json([
                'logs' => $logs,
                'summary' => $this->activityLogSummary($summaryQuery),
                'filters' => $this->activityLogFilterOptions($actor),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['logs' => ['data' => []], 'warning' => 'Activity log paketi etkin degil.']);
        }
    }

    /**
     * Export panel activity logs.
     *
     * Requires permission: `logs.export`. Global scope exports all matching logs; scoped users are limited to their own activity plus permission matrix logs. Export is capped to the latest 1000 filtered rows and returns a binary CSV/XLSX/PDF/DOCX file depending on `format`.
     *
     * @authenticated
     *
     * @queryParam log_name string Optional log source filter. Example: audit
     * @queryParam event string Optional event/action filter. Example: updated
     * @queryParam outcome string Optional outcome filter: `success` or `denied_or_failed`. Example: success
     * @queryParam status_code integer Optional HTTP status code filter. Example: 403
     * @queryParam search string Optional text search. Example: kullanici
     * @queryParam date_from date Optional start date. Example: 2026-06-01
     * @queryParam date_to date Optional end date. Example: 2026-06-30
     * @queryParam format string Optional export format: `csv`, `xlsx`, `pdf`, `docx`, `excel` or `word`. Defaults to csv. Example: pdf
     *
     * @response 200 binary Activity-log export file.
     * @response 403 {"message":"Bu islem icin yetkiniz bulunmuyor."}
     */
    public function exportActivityLogs(Request $request)
    {
        $this->abortUnlessAllowed($request, 'logs.export');

        try {
            $validated = $this->validatedActivityLogFilters($request);
            $logs = $this->activityLogQuery($request, 'logs.export', $validated)
                ->limit(1000)
                ->get();

            $headings = ['ID', 'Tarih', 'Kaynak', 'Kullanici', 'Rol', 'Aksiyon', 'Sonuc', 'HTTP', 'Sure (ms)', 'Yol', 'Hedef Model', 'Hedef ID', 'Aciklama'];
            $rows = $logs->map(function (Activity $log) {
                $properties = $log->properties?->toArray() ?? [];

                return [
                    $log->id,
                    optional($log->created_at)?->format('d.m.Y H:i:s') ?? '-',
                    $log->log_name ?? '-',
                    $log->causer ? trim($log->causer->name.' '.$log->causer->surname) : 'Sistem',
                    $log->causer->role ?? '-',
                    $log->event ?? ($log->description ?? '-'),
                    data_get($properties, 'outcome', '-'),
                    data_get($properties, 'status_code', '-'),
                    data_get($properties, 'duration_ms', '-'),
                    data_get($properties, 'path', '-'),
                    $log->subject_type ? class_basename($log->subject_type) : '-',
                    $log->subject_id ?? '-',
                    $log->description ?? '-',
                ];
            })->all();

            return AdminExportResponder::download(
                $request->string('format')->toString() ?: 'csv',
                'islem_loglari_'.now()->format('Ymd_His'),
                'Islem Loglari',
                $headings,
                $rows
            );
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Log export olusturulamadi.'], 500);
        }
    }

    private function validatedActivityLogFilters(Request $request): array
    {
        return $request->validate([
            'log_name' => 'nullable|string|max:80',
            'event' => 'nullable|string|max:120',
            'outcome' => 'nullable|in:success,denied_or_failed',
            'status_code' => 'nullable|integer|min:100|max:599',
            'search' => 'nullable|string|max:160',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:5|max:100',
        ]);
    }

    private function activityLogQuery(Request $request, string $permission, array $filters)
    {
        $actor = $request->user();
        $query = Activity::query()
            ->with('causer:id,name,surname,role')
            ->latest();

        if (! $this->permissionResolver->hasGlobalScope($actor, $permission)) {
            $query->where(function ($builder) use ($actor) {
                $builder
                    ->where(function ($self) use ($actor) {
                        $self->where('causer_type', User::class)
                            ->where('causer_id', $actor->id);
                    })
                    ->orWhere('log_name', 'permissions');
            });
        }

        if (! empty($filters['log_name'])) {
            $query->where('log_name', $filters['log_name']);
        }

        if (! empty($filters['event'])) {
            $query->where('event', $filters['event']);
        }

        if (! empty($filters['outcome'])) {
            $query->where('properties->outcome', $filters['outcome']);
        }

        if (! empty($filters['status_code'])) {
            $query->where('properties->status_code', (int) $filters['status_code']);
        }

        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }

        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($builder) use ($search) {
                $builder->where('description', 'like', "%{$search}%")
                    ->orWhere('event', 'like', "%{$search}%")
                    ->orWhere('log_name', 'like', "%{$search}%")
                    ->orWhere('subject_type', 'like', "%{$search}%")
                    ->orWhere('properties', 'like', "%{$search}%");
            });
        }

        return $query;
    }

    private function activityLogSummary($query): array
    {
        $total = (clone $query)->reorder()->count();
        $success = (clone $query)->reorder()->where('properties->outcome', 'success')->count();
        $failed = (clone $query)->reorder()->where('properties->outcome', 'denied_or_failed')->count();
        $logs = (clone $query)->limit(1000)->get(['id', 'log_name', 'event', 'properties']);

        return [
            'total' => $total,
            'success' => $success,
            'failed' => $failed,
            'sources' => $logs->pluck('log_name')->filter()->countBy()->sortDesc()->take(6)->all(),
            'events' => $logs->pluck('event')->filter()->countBy()->sortDesc()->take(8)->all(),
        ];
    }

    private function activityLogFilterOptions(User $actor): array
    {
        $query = Activity::query();
        if (! $this->permissionResolver->hasGlobalScope($actor, 'logs.view')) {
            $query->where(function ($builder) use ($actor) {
                $builder
                    ->where(function ($self) use ($actor) {
                        $self->where('causer_type', User::class)
                            ->where('causer_id', $actor->id);
                    })
                    ->orWhere('log_name', 'permissions');
            });
        }

        return [
            'log_names' => (clone $query)->select('log_name')->whereNotNull('log_name')->distinct()->orderBy('log_name')->pluck('log_name')->values()->all(),
            'events' => (clone $query)->select('event')->whereNotNull('event')->distinct()->orderBy('event')->pluck('event')->values()->all(),
        ];
    }
}
