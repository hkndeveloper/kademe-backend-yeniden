<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\CommunicationLog;
use App\Models\FinancialTransaction;
use App\Models\Participant;
use App\Models\Program;
use App\Models\Project;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\PermissionResolver;
use App\Support\AdminExportResponder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AdminDashboardController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {
    }

    /**
     * Dashboard özetine erişim: en az bir operasyonel görünürlük izni gerekir.
     */
    private function assertCanViewDashboard(Request $request): void
    {
        $user = $request->user();
        $any = $this->permissionResolver->hasPermission($user, 'programs.view')
            || $this->permissionResolver->hasPermission($user, 'applications.view')
            || $this->permissionResolver->hasPermission($user, 'financial.view')
            || $this->permissionResolver->hasPermission($user, 'support.view')
            || $this->permissionResolver->hasPermission($user, 'certificates.view')
            || $this->permissionResolver->hasPermission($user, 'dashboard.admin.view')
            || $this->permissionResolver->hasPermission($user, 'dashboard.coordinator.view')
            || $this->permissionResolver->hasPermission($user, 'dashboard.staff.view');

        abort_unless($any, 403, 'Dashboard verilerini goruntuleme yetkiniz bulunmuyor.');
    }

    /**
     * GET /admin/dashboard/stats
     * Süper admin: genel; diğer roller: yalnızca PermissionResolver kapsamındaki projeler.
     */
    public function stats(Request $request)
    {
        $this->assertCanViewDashboard($request);

        $user = $request->user();
        $isGlobal = $user->role === 'super_admin';
        $projectIds = $isGlobal ? null : ($this->permissionResolver->resolve($user)['contexts']['manageable_project_ids'] ?? []);

        $now = Carbon::now();
        $startOfMonth = $now->copy()->startOfMonth();
        $endOfMonth = $now->copy()->endOfMonth();

        $scopeParticipant = Participant::query()->when(! $isGlobal, fn ($q) => $q->whereIn('project_id', $projectIds));
        $scopeProgram = Program::query()->when(! $isGlobal, fn ($q) => $q->whereIn('project_id', $projectIds));
        $scopeFinancial = FinancialTransaction::query()->when(! $isGlobal, fn ($q) => $q->whereIn('project_id', $projectIds));
        $scopeApplication = Application::query()->when(! $isGlobal, fn ($q) => $q->whereIn('project_id', $projectIds));
        $scopeSupport = SupportTicket::query()->when(! $isGlobal, fn ($q) => $q->whereIn('project_id', $projectIds));
        $scopeCommunication = CommunicationLog::query()->when(! $isGlobal, fn ($q) => $q->whereIn('project_id', $projectIds));

        $activeStudentCount = (clone $scopeParticipant)->where('status', 'active')->count();

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

        $projectsQuery = Project::query()->when(! $isGlobal, fn ($q) => $q->whereIn('id', $projectIds));
        $projects = $projectsQuery->withCount([
            'participants as active_participants_count' => fn ($q) => $q->where('status', 'active'),
        ])->get(['id', 'name', 'slug', 'max_participants']);

        $projectOccupancy = $projects->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'active' => $p->active_participants_count,
            'max' => $p->max_participants,
            'rate' => $p->max_participants > 0
                ? round(($p->active_participants_count / $p->max_participants) * 100)
                : null,
        ]);

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

        $userStats = $isGlobal
            ? User::selectRaw('role, COUNT(*) as count')
                ->whereNull('deleted_at')
                ->groupBy('role')
                ->pluck('count', 'role')
            : collect();

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
            'project_occupancy' => $projectOccupancy,
            'sms' => [
                'total_this_month' => $totalSmsSent,
                'by_project' => $monthlySms,
            ],
            'upcoming_programs' => $upcomingPrograms,
            'user_stats' => $userStats,
            'stats_scope' => $isGlobal ? 'global' : 'projects',
        ]);
    }

    /**
     * GET /admin/dashboard/activity-logs
     */
    public function activityLogs(Request $request)
    {
        $this->abortUnlessAllowed($request, 'logs.view');

        if ($request->user()->role !== 'super_admin') {
            return response()->json([
                'logs' => [],
                'warning' => 'Aktivite loglari su an yalnizca ust admin kapsaminda listelenir.',
            ]);
        }

        try {
            $query = \Spatie\Activitylog\Models\Activity::query()
                ->with('causer:id,name,surname,role')
                ->latest();

            if ($request->filled('log_name')) {
                $query->where('log_name', $request->string('log_name')->toString());
            }

            $logs = $query->take(50)->get();

            return response()->json(['logs' => $logs]);
        } catch (\Throwable $e) {
            return response()->json(['logs' => [], 'warning' => 'Activity log paketi etkin değil.']);
        }
    }

    public function exportActivityLogs(Request $request)
    {
        $this->abortUnlessAllowed($request, 'logs.export');

        if ($request->user()->role !== 'super_admin') {
            return response()->json(['message' => 'Log disa aktarma yalnizca ust admin icindir.'], 403);
        }

        try {
            $query = \Spatie\Activitylog\Models\Activity::query()
                ->with('causer:id,name,surname,role')
                ->latest();

            if ($request->filled('log_name')) {
                $query->where('log_name', $request->string('log_name')->toString());
            }

            $logs = $query->take(500)->get();

            $headings = ['ID', 'Tarih', 'Kullanici', 'Rol', 'Aksiyon', 'Hedef Model', 'Hedef ID', 'Aciklama'];
            $rows = $logs->map(fn ($log) => [
                $log->id,
                optional($log->created_at)?->format('d.m.Y H:i:s') ?? '-',
                $log->causer ? trim($log->causer->name . ' ' . $log->causer->surname) : 'Sistem',
                $log->causer->role ?? '-',
                $log->event ?? ($log->description ?? '-'),
                $log->subject_type ? class_basename($log->subject_type) : '-',
                $log->subject_id ?? '-',
                $log->description ?? '-',
            ])->all();

            return AdminExportResponder::download('csv', 'islem_loglari_' . now()->format('Ymd_His'), 'Islem Loglari', $headings, $rows);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Log export olusturulamadi.'], 500);
        }
    }
}
