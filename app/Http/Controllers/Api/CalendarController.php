<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Models\Program;
use App\Models\Project;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\GoogleCalendarService;
use App\Services\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;

class CalendarController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {
    }

    private function canAssignProgram(User $user, Program $program): bool
    {
        return $this->permissionResolver->canAccessProject($user, 'calendar.assignments.manage', $program->project_id);
    }

    private function mapAssignments(Collection $users): array
    {
        return $users
            ->map(function (User $user) {
                return [
                    'id' => $user->id,
                    'name' => trim($user->name . ' ' . $user->surname),
                    'role' => $user->role,
                    'unit' => $user->staffProfile?->unit,
                    'title' => $user->staffProfile?->title,
                ];
            })
            ->values()
            ->all();
    }

    public function overview(Request $request, GoogleCalendarService $googleCalendar): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'calendar.view');
        $user = $request->user();
        $validated = $request->validate([
            'project_id' => 'nullable|exists:projects,id',
        ]);
        $projectIds = $this->permissionResolver->projectIdsForPermission($user, 'calendar.view');

        if (empty($projectIds)) {
            return response()->json([
                'projects' => [],
                'programs' => [],
                'summary' => [
                    'total_programs' => 0,
                    'upcoming_this_week' => 0,
                    'upcoming_this_month' => 0,
                    'open_support_count' => 0,
                    'google_synced_count' => 0,
                ],
                'upcoming_tasks' => [],
                'google_calendar' => $googleCalendar->getStatus(),
            ]);
        }

        $projects = Project::query()
            ->with(['periods' => fn ($query) => $query->where('status', 'active')->orderByDesc('start_date')])
            ->whereIn('id', $projectIds)
            ->orderBy('name')
            ->get();

        $programQuery = Program::query()
            ->with([
                'project:id,name',
                'period:id,name',
                'calendarEvent:id,program_id,google_event_id,assigned_users',
            ])
            ->whereIn('project_id', $projectIds)
            ->orderBy('start_at');

        if (!empty($validated['project_id'])) {
            $programQuery->where('project_id', $validated['project_id']);
        }

        $programCollection = $programQuery->get();

        $assignedUserIds = $programCollection
            ->pluck('calendarEvent.assigned_users')
            ->filter()
            ->flatten()
            ->unique()
            ->values();

        $assignedUsers = User::query()
            ->with('staffProfile')
            ->whereIn('id', $assignedUserIds)
            ->get()
            ->keyBy('id');

        $currentUserId = $user->id;

        $programs = $programCollection
            ->map(function (Program $program) use ($assignedUsers, $currentUserId) {
                $assignedIds = collect($program->calendarEvent?->assigned_users ?? [])
                    ->filter(fn ($value) => is_numeric($value))
                    ->map(fn ($value) => (int) $value)
                    ->values();

                $assignmentItems = $assignedIds
                    ->map(fn (int $userId) => $assignedUsers->get($userId))
                    ->filter();

                return [
                    'id' => $program->id,
                    'title' => $program->title,
                    'description' => $program->description,
                    'location' => $program->location,
                    'status' => $program->status,
                    'radius_meters' => $program->radius_meters,
                    'credit_deduction' => $program->credit_deduction,
                    'start_at' => optional($program->start_at)?->toIso8601String(),
                    'end_at' => optional($program->end_at)?->toIso8601String(),
                    'project_id' => $program->project_id,
                    'project' => $program->project ? [
                        'id' => $program->project->id,
                        'name' => $program->project->name,
                    ] : null,
                    'period' => $program->period ? [
                        'id' => $program->period->id,
                        'name' => $program->period->name,
                    ] : null,
                    'calendar_event' => $program->calendarEvent ? [
                        'google_event_id' => $program->calendarEvent->google_event_id,
                        'assigned_user_ids' => $assignedIds->all(),
                        'assigned_users' => $this->mapAssignments($assignmentItems),
                        'assigned_count' => $assignmentItems->count(),
                        'is_assigned_to_current_user' => $assignedIds->contains($currentUserId),
                    ] : null,
                ];
            })
            ->values();

        $now = now();
        $weekEnd = $now->copy()->addDays(7);
        $monthEnd = $now->copy()->addDays(30);

        $upcomingTasks = $programs
            ->filter(function (array $program) use ($now) {
                if (empty($program['start_at'])) {
                    return false;
                }

                return Carbon::parse($program['start_at'])->greaterThanOrEqualTo($now);
            })
            ->take(8)
            ->values();

        $openSupportCount = SupportTicket::query()
            ->whereIn('project_id', $projectIds)
            ->whereIn('status', ['open', 'in_progress'])
            ->count();

        $syncedCount = $programs
            ->filter(fn (array $program) => !empty($program['calendar_event']['google_event_id']))
            ->count();

        return response()->json([
            'projects' => $projects->map(function (Project $project) {
                $activePeriod = $project->periods->first();

                return [
                    'id' => $project->id,
                    'name' => $project->name,
                    'active_period' => $activePeriod ? [
                        'id' => $activePeriod->id,
                        'name' => $activePeriod->name,
                    ] : null,
                ];
            })->values(),
            'programs' => $programs,
            'summary' => [
                'total_programs' => $programs->count(),
                'upcoming_this_week' => $programs->filter(function (array $program) use ($now, $weekEnd) {
                    if (empty($program['start_at'])) {
                        return false;
                    }

                    return Carbon::parse($program['start_at'])->between($now, $weekEnd);
                })->count(),
                'upcoming_this_month' => $programs->filter(function (array $program) use ($now, $monthEnd) {
                    if (empty($program['start_at'])) {
                        return false;
                    }

                    return Carbon::parse($program['start_at'])->between($now, $monthEnd);
                })->count(),
                'open_support_count' => $openSupportCount,
                'google_synced_count' => $syncedCount,
            ],
            'upcoming_tasks' => $upcomingTasks,
            'google_calendar' => $googleCalendar->getStatus(),
        ]);
    }

    public function assignees(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'calendar.assignments.manage');
        $validated = $request->validate([
            'project_id' => 'nullable|integer|exists:projects,id',
        ]);
        $user = $request->user();
        $projectId = $validated['project_id'] ?? null;

        if ($projectId !== null) {
            abort_unless(
                $this->permissionResolver->canAccessProject($user, 'calendar.assignments.manage', (int) $projectId),
                403,
                'Bu proje icin gorev atama yetkiniz yok.'
            );
        }

        $users = User::query()
            ->with('staffProfile')
            ->whereIn('role', ['coordinator', 'staff'])
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        $users = $users->filter(function (User $candidate) use ($projectId) {
            if ($projectId === null) {
                return $this->permissionResolver->hasPermission($candidate, 'calendar.assignments.manage');
            }

            return $this->permissionResolver->canAccessProject($candidate, 'calendar.assignments.manage', (int) $projectId);
        })->values();

        return response()->json([
            'users' => $this->mapAssignments($users),
        ]);
    }

    public function updateAssignments(Request $request, int $programId): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'calendar.assignments.manage');
        $program = Program::query()->with(['project:id,name', 'calendarEvent'])->findOrFail($programId);
        abort_unless($this->canAssignProgram($request->user(), $program), 403, 'Bu programa gorev atama yetkiniz yok.');

        $validated = $request->validate([
            'assigned_user_ids' => 'array',
            'assigned_user_ids.*' => 'integer|exists:users,id',
        ]);

        $assignedIds = collect($validated['assigned_user_ids'] ?? [])
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values();

        $allowedUsers = User::query()
            ->whereIn('id', $assignedIds)
            ->whereIn('role', ['coordinator', 'staff'])
            ->where('status', 'active')
            ->get()
            ->filter(fn (User $candidate) => $this->permissionResolver->canAccessProject($candidate, 'calendar.assignments.manage', $program->project_id))
            ->values();

        $allowedIds = $allowedUsers->pluck('id');

        $event = CalendarEvent::query()->updateOrCreate(
            ['program_id' => $program->id],
            [
                'project_id' => $program->project_id,
                'title' => $program->title,
                'description' => $program->description,
                'location' => $program->location,
                'start_at' => $program->start_at,
                'end_at' => $program->end_at,
                'created_by' => $program->created_by ?? $request->user()->id,
                'assigned_users' => $allowedIds->values()->all(),
            ]
        );

        $assignedUsers = User::query()
            ->with('staffProfile')
            ->whereIn('id', $allowedIds)
            ->get();

        return response()->json([
            'message' => 'Gorev atamalari guncellendi.',
            'program_id' => $program->id,
            'calendar_event' => [
                'google_event_id' => $event->google_event_id,
                'assigned_user_ids' => $allowedIds->values()->all(),
                'assigned_users' => $this->mapAssignments($assignedUsers),
                'assigned_count' => $assignedUsers->count(),
            ],
        ]);
    }

    public function googleStatus(Request $request, GoogleCalendarService $googleCalendar): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'calendar.view');
        return response()->json($googleCalendar->getStatus());
    }

    public function googleConnect(Request $request, GoogleCalendarService $googleCalendar): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'calendar.google.connect');
        $validated = $request->validate([
            'panel' => 'required|in:admin,coordinator,staff',
        ]);

        return response()->json([
            'authorization_url' => $googleCalendar->getAuthorizationUrl($validated['panel']),
        ]);
    }

    public function googleSync(Request $request, GoogleCalendarService $googleCalendar): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'calendar.google.sync');
        $result = $googleCalendar->syncAllPrograms();

        return response()->json([
            'message' => 'Google Calendar senkronizasyonu tamamlandi.',
            'result' => $result,
            'google_calendar' => $googleCalendar->getStatus(),
        ]);
    }
}
