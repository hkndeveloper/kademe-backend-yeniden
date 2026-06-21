<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Concerns\ResolvesProjectPeriodContext;
use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Models\Period;
use App\Models\Program;
use App\Models\Project;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\AdminExportResponder;
use App\Services\GoogleCalendarService;
use App\Services\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;

class CalendarController extends Controller
{
    use AuthorizesGranularPermissions;
    use ResolvesProjectPeriodContext;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {
    }

    private function canAssignProgram(User $user, Program $program): bool
    {
        return $this->permissionResolver->canAccessProject($user, 'calendar.assignments.manage', $program->project_id);
    }

    private function canAccessCalendarEvent(User $user, string $permission, ?int $projectId): bool
    {
        if ($projectId === null) {
            return $this->permissionResolver->hasGlobalScope($user, $permission);
        }

        return $this->permissionResolver->canAccessProject($user, $permission, $projectId);
    }

    private function viewableProjectIds(User $user): array
    {
        // Is kurali: coordinator/personel, etkinlik cakisma kontrolu icin tum projeleri gorebilir.
        $allowCrossProjectCalendarView = (bool) config('permission_catalog.calendar_cross_project_view_for_staff_coordinator', true);
        if ($allowCrossProjectCalendarView && in_array($user->role, ['coordinator', 'staff'], true)) {
            return Project::query()->pluck('id')->all();
        }

        return $this->permissionResolver->projectIdsForPermission($user, 'calendar.view');
    }

    private function assignableProjectIds(User $user): array
    {
        if ($this->permissionResolver->hasGlobalScope($user, 'calendar.assignments.manage')) {
            return Project::query()->pluck('id')->all();
        }

        return $this->permissionResolver->projectIdsForPermission($user, 'calendar.assignments.manage');
    }

    private function assignableProjectIdsFor(User $user, string $permission): array
    {
        if ($this->permissionResolver->hasGlobalScope($user, $permission)) {
            return Project::query()->pluck('id')->all();
        }

        return $this->permissionResolver->projectIdsForPermission($user, $permission);
    }

    private function isMediaUnit(User $user): bool
    {
        $unit = mb_strtolower((string) $user->staffProfile?->unit);
        $markers = config('permission_catalog.media_unit_markers', ['medya', 'media']);

        foreach ($markers as $marker) {
            $marker = mb_strtolower((string) $marker);
            if ($marker !== '' && str_contains($unit, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function canBeAssignedToProject(User $candidate, int $projectId): bool
    {
        if ($this->isMediaUnit($candidate)) {
            return true;
        }

        if ($candidate->coordinatedProjects->contains('id', $projectId)) {
            return true;
        }

        return $candidate->participations->contains('project_id', $projectId)
            || $this->permissionResolver->canAccessProject($candidate, 'calendar.view', $projectId)
            || $this->permissionResolver->canAccessProject($candidate, 'projects.view', $projectId);
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

    private function allowedMeetingAssignees(Collection $assignedIds, ?int $projectId): Collection
    {
        $users = User::query()
            ->with(['staffProfile', 'coordinatedProjects:id', 'participations:id,user_id,project_id'])
            ->whereIn('id', $assignedIds)
            ->whereIn('role', ['coordinator', 'staff'])
            ->where('status', 'active')
            ->get();

        if ($projectId === null) {
            return $users->values();
        }

        return $users
            ->filter(fn (User $candidate) => $this->canBeAssignedToProject($candidate, $projectId))
            ->values();
    }

    private function mapCalendarMeeting(CalendarEvent $event, Collection $assignedUsers, int $currentUserId): array
    {
        $assignedIds = collect($event->assigned_users ?? [])
            ->filter(fn ($value) => is_numeric($value))
            ->map(fn ($value) => (int) $value)
            ->values();

        $assignmentItems = $assignedIds
            ->map(fn (int $userId) => $assignedUsers->get($userId))
            ->filter();

        return [
            'id' => $event->id,
            'calendar_event_id' => $event->id,
            'event_type' => 'meeting',
            'title' => $event->title,
            'description' => $event->description,
            'location' => $event->location,
            'status' => $event->status ?? 'scheduled',
            'radius_meters' => null,
            'credit_deduction' => null,
            'application_quota' => null,
            'start_at' => optional($event->start_at)?->toIso8601String(),
            'end_at' => optional($event->end_at)?->toIso8601String(),
            'project_id' => $event->project_id,
            'project' => $event->project ? [
                'id' => $event->project->id,
                'name' => $event->project->name,
            ] : null,
            'period' => $event->period ? [
                'id' => $event->period->id,
                'name' => $event->period->name,
            ] : null,
            'calendar_event' => [
                'google_event_id' => $event->google_event_id,
                'assigned_user_ids' => $assignedIds->all(),
                'assigned_users' => $this->mapAssignments($assignmentItems),
                'assigned_count' => $assignmentItems->count(),
                'is_assigned_to_current_user' => $assignedIds->contains($currentUserId),
            ],
        ];
    }

    public function overview(Request $request, GoogleCalendarService $googleCalendar): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'calendar.view');
        $user = $request->user();
        $validated = $request->validate([
            'project_id' => 'nullable|exists:projects,id',
            'period_id' => 'nullable|exists:periods,id',
        ]);
        $context = $this->resolveProjectPeriodContext(
            $request,
            'calendar.view',
            ! empty($validated['project_id']) ? (int) $validated['project_id'] : null,
            ! empty($validated['period_id']) ? (int) $validated['period_id'] : null,
        );
        $projectIds = $context->projectIdsForQuery();

        if (empty($projectIds) && ! $this->permissionResolver->hasPermission($user, 'calendar.view')) {
            return response()->json([
                'projects' => [],
                'programs' => [],
                'summary' => [
                    'total_programs' => 0,
                    'today_programs' => 0,
                    'upcoming_this_week' => 0,
                    'upcoming_this_month' => 0,
                    'open_support_count' => 0,
                    'google_synced_count' => 0,
                    'google_pending_count' => 0,
                    'unassigned_count' => 0,
                ],
                'upcoming_tasks' => [],
                'attention_items' => [],
                'google_calendar' => $googleCalendar->getStatus(),
            ]);
        }

        $projects = Project::query()
            ->with(['periods' => fn ($query) => $query->orderByDesc('start_date')])
            ->whereIn('id', $projectIds)
            ->orderBy('name')
            ->get();

        $programQuery = Program::query()
            ->with([
                'project:id,name',
                'period:id,name',
                'calendarEvent:id,program_id,google_event_id,assigned_users',
            ])
            ->orderBy('start_at');
        $this->applyProjectPeriodContext($programQuery, $context);

        $programCollection = $programQuery->get();

        $meetingQuery = CalendarEvent::query()
            ->with(['project:id,name', 'period:id,name'])
            ->where('event_type', 'meeting')
            ->orderBy('start_at');

        if ($context->projectId !== null) {
            $meetingQuery->where('project_id', $context->projectId);
        }

        if ($context->periodId !== null) {
            $meetingQuery->where('period_id', $context->periodId);
        }

        if (! $this->permissionResolver->hasGlobalScope($user, 'calendar.view')) {
            $meetingQuery->where(function ($query) use ($projectIds, $user) {
                if (! empty($projectIds)) {
                    $query->whereIn('project_id', $projectIds);
                } else {
                    $query->whereRaw('1 = 0');
                }

                $query->orWhereJsonContains('assigned_users', (int) $user->id)
                    ->orWhere('created_by', $user->id);
            });
        }

        $meetingCollection = $meetingQuery->get();

        $assignedUserIds = $programCollection
            ->pluck('calendarEvent.assigned_users')
            ->filter()
            ->flatten()
            ->merge($meetingCollection->pluck('assigned_users')->filter()->flatten())
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
                    'calendar_event_id' => $program->calendarEvent?->id,
                    'event_type' => 'program',
                    'title' => $program->title,
                    'description' => $program->description,
                    'location' => $program->location,
                    'status' => $program->status,
                    'radius_meters' => $program->radius_meters,
                    'credit_deduction' => $program->credit_deduction,
                    'application_quota' => $program->application_quota,
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

        $meetings = $meetingCollection
            ->map(fn (CalendarEvent $event) => $this->mapCalendarMeeting($event, $assignedUsers, $currentUserId))
            ->values();

        $calendarItems = $programs
            ->concat($meetings)
            ->sortBy(fn (array $item) => $item['start_at'] ?? '9999-12-31T23:59:59+00:00')
            ->values();

        $now = now();
        $weekEnd = $now->copy()->addDays(7);
        $monthEnd = $now->copy()->addDays(30);

        $upcomingTasks = $calendarItems
            ->filter(function (array $program) use ($now) {
                if (empty($program['start_at'])) {
                    return false;
                }

                return Carbon::parse($program['start_at'])->greaterThanOrEqualTo($now);
            })
            ->take(8)
            ->values();

        $attentionItems = $calendarItems
            ->filter(function (array $program) use ($now) {
                if (empty($program['start_at'])) {
                    return false;
                }

                $startAt = Carbon::parse($program['start_at']);

                return $startAt->greaterThanOrEqualTo($now)
                    && (
                        empty($program['calendar_event']['assigned_count'])
                        || (
                            ($program['event_type'] ?? 'program') === 'program'
                            && empty($program['calendar_event']['google_event_id'])
                        )
                    );
            })
            ->take(6)
            ->values();

        $supportProjectIds = $this->permissionResolver->projectIdsForPermission($user, 'support.view');
        $openSupportCount = empty($supportProjectIds)
            ? 0
            : SupportTicket::query()
            ->whereIn('project_id', $supportProjectIds)
                ->whereIn('status', ['open', 'in_progress'])
                ->count();

        $syncedCount = $programs
            ->filter(fn (array $program) => !empty($program['calendar_event']['google_event_id']))
            ->count();
        $unassignedCount = $programs
            ->filter(fn (array $program) => empty($program['calendar_event']['assigned_count']))
            ->count();

        return response()->json([
            'projects' => $projects->map(function (Project $project) {
                $activePeriod = $project->periods->firstWhere('status', 'active') ?? $project->periods->first();

                return [
                    'id' => $project->id,
                    'name' => $project->name,
                    'active_period' => $activePeriod ? [
                        'id' => $activePeriod->id,
                        'name' => $activePeriod->name,
                    ] : null,
                ];
            })->values(),
            'programs' => $calendarItems,
            'summary' => [
                'total_programs' => $programs->count(),
                'total_meetings' => $meetings->count(),
                'total_events' => $calendarItems->count(),
                'today_programs' => $calendarItems->filter(function (array $program) use ($now) {
                    if (empty($program['start_at'])) {
                        return false;
                    }

                    return Carbon::parse($program['start_at'])->isSameDay($now);
                })->count(),
                'upcoming_this_week' => $calendarItems->filter(function (array $program) use ($now, $weekEnd) {
                    if (empty($program['start_at'])) {
                        return false;
                    }

                    return Carbon::parse($program['start_at'])->between($now, $weekEnd);
                })->count(),
                'upcoming_this_month' => $calendarItems->filter(function (array $program) use ($now, $monthEnd) {
                    if (empty($program['start_at'])) {
                        return false;
                    }

                    return Carbon::parse($program['start_at'])->between($now, $monthEnd);
                })->count(),
                'open_support_count' => $openSupportCount,
                'google_synced_count' => $syncedCount,
                'google_pending_count' => max($programs->count() - $syncedCount, 0),
                'unassigned_count' => $calendarItems->filter(fn (array $program) => empty($program['calendar_event']['assigned_count']))->count(),
            ],
            'upcoming_tasks' => $upcomingTasks,
            'attention_items' => $attentionItems,
            'google_calendar' => $googleCalendar->getStatus(),
        ]);
    }

    public function assignees(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => 'nullable|integer|exists:projects,id',
            'context' => 'nullable|in:program,meeting_create,meeting_manage',
        ]);
        $user = $request->user();
        $projectId = $validated['project_id'] ?? null;
        $context = $validated['context'] ?? 'program';
        $permission = match ($context) {
            'meeting_create' => 'calendar.meetings.create',
            'meeting_manage' => 'calendar.meetings.manage',
            default => 'calendar.assignments.manage',
        };

        $this->abortUnlessAllowed($request, $permission);

        if ($projectId !== null) {
            abort_unless(
                $this->permissionResolver->canAccessProject($user, $permission, (int) $projectId),
                403,
                'Bu proje icin atama yetkiniz yok.'
            );
        } elseif ($context !== 'program') {
            abort_unless(
                $this->permissionResolver->hasGlobalScope($user, $permission),
                403,
                'Genel toplanti icin global yetki gerekir.'
            );
        }

        $assignableProjectIds = $projectId !== null
            ? [(int) $projectId]
            : ($context === 'program'
                ? $this->assignableProjectIds($user)
                : $this->assignableProjectIdsFor($user, $permission));

        $users = User::query()
            ->with(['staffProfile', 'coordinatedProjects:id', 'participations:id,user_id,project_id'])
            ->whereIn('role', ['coordinator', 'staff'])
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        if ($context !== 'program' && $projectId === null) {
            return response()->json([
                'users' => $this->mapAssignments($users),
            ]);
        }

        $users = $users->filter(function (User $candidate) use ($assignableProjectIds) {
            foreach ($assignableProjectIds as $assignableProjectId) {
                if ($this->canBeAssignedToProject($candidate, (int) $assignableProjectId)) {
                    return true;
                }
            }

            return false;
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
        $this->assertPeriodWritable($request, $program->period_id);

        $validated = $request->validate([
            'assigned_user_ids' => 'array',
            'assigned_user_ids.*' => 'integer|exists:users,id',
        ]);

        $assignedIds = collect($validated['assigned_user_ids'] ?? [])
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values();

        $allowedUsers = User::query()
            ->with(['staffProfile', 'coordinatedProjects:id', 'participations:id,user_id,project_id'])
            ->whereIn('id', $assignedIds)
            ->whereIn('role', ['coordinator', 'staff'])
            ->where('status', 'active')
            ->get()
            ->filter(fn (User $candidate) => $this->canBeAssignedToProject($candidate, (int) $program->project_id))
            ->values();

        $allowedIds = $allowedUsers->pluck('id');

        $event = CalendarEvent::query()->updateOrCreate(
            ['program_id' => $program->id],
            [
                'project_id' => $program->project_id,
                'period_id' => $program->period_id,
                'event_type' => 'program',
                'title' => $program->title,
                'description' => $program->description,
                'location' => $program->location,
                'start_at' => $program->start_at,
                'end_at' => $program->end_at,
                'status' => $program->status ?? 'scheduled',
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

    public function storeMeeting(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'calendar.meetings.create');
        $user = $request->user();
        $validated = $request->validate([
            'project_id' => 'nullable|integer|exists:projects,id',
            'period_id' => 'nullable|integer|exists:periods,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'location' => 'nullable|string|max:255',
            'start_at' => 'required|date',
            'end_at' => 'nullable|date|after:start_at',
            'assigned_user_ids' => 'array',
            'assigned_user_ids.*' => 'integer|exists:users,id',
        ]);

        $projectId = array_key_exists('project_id', $validated) && $validated['project_id'] !== null
            ? (int) $validated['project_id']
            : null;
        $periodId = array_key_exists('period_id', $validated) && $validated['period_id'] !== null
            ? (int) $validated['period_id']
            : null;

        if ($periodId !== null) {
            $period = Period::query()
                ->select(['id', 'project_id', 'status'])
                ->findOrFail($periodId);

            if ($projectId !== null && (int) $period->project_id !== $projectId) {
                throw ValidationException::withMessages([
                    'period_id' => ['Secilen donem bu projeye ait degil.'],
                ]);
            }

            $projectId ??= (int) $period->project_id;
        }

        abort_unless(
            $this->canAccessCalendarEvent($user, 'calendar.meetings.create', $projectId),
            403,
            'Bu kapsamda toplanti olusturma yetkiniz yok.'
        );
        $this->assertPeriodWritable($request, $periodId);

        $assignedIds = collect($validated['assigned_user_ids'] ?? [])
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values();
        $allowedUsers = $this->allowedMeetingAssignees($assignedIds, $projectId);
        $allowedIds = $allowedUsers->pluck('id')->values();

        $event = CalendarEvent::query()->create([
            'event_type' => 'meeting',
            'project_id' => $projectId,
            'period_id' => $periodId,
            'program_id' => null,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'location' => $validated['location'] ?? null,
            'start_at' => Carbon::parse($validated['start_at']),
            'end_at' => ! empty($validated['end_at']) ? Carbon::parse($validated['end_at']) : null,
            'status' => 'scheduled',
            'created_by' => $user->id,
            'assigned_users' => $allowedIds->all(),
            'metadata' => ['source' => 'panel'],
        ])->load(['project:id,name', 'period:id,name']);

        return response()->json([
            'message' => 'Toplanti olusturuldu.',
            'meeting' => $this->mapCalendarMeeting(
                $event,
                $allowedUsers->keyBy('id'),
                (int) $user->id
            ),
        ], 201);
    }

    public function updateMeetingAssignments(Request $request, int $eventId): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'calendar.meetings.manage');
        $user = $request->user();
        $event = CalendarEvent::query()
            ->with('project:id,name')
            ->where('event_type', 'meeting')
            ->findOrFail($eventId);

        abort_unless(
            $this->canAccessCalendarEvent($user, 'calendar.meetings.manage', $event->project_id),
            403,
            'Bu toplantinin davetlilerini yonetme yetkiniz yok.'
        );
        $this->assertPeriodWritable($request, $event->period_id);

        $validated = $request->validate([
            'assigned_user_ids' => 'array',
            'assigned_user_ids.*' => 'integer|exists:users,id',
        ]);

        $assignedIds = collect($validated['assigned_user_ids'] ?? [])
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values();
        $allowedUsers = $this->allowedMeetingAssignees(
            $assignedIds,
            $event->project_id !== null ? (int) $event->project_id : null
        );
        $allowedIds = $allowedUsers->pluck('id')->values();

        $event->update([
            'assigned_users' => $allowedIds->all(),
        ]);

        return response()->json([
            'message' => 'Toplanti davetlileri guncellendi.',
            'meeting_id' => $event->id,
            'calendar_event' => [
                'google_event_id' => $event->google_event_id,
                'assigned_user_ids' => $allowedIds->all(),
                'assigned_users' => $this->mapAssignments($allowedUsers),
                'assigned_count' => $allowedUsers->count(),
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

        try {
            $result = $googleCalendar->syncAllPrograms();
        } catch (\Throwable $exception) {
            $googleCalendar->recordSyncError($exception->getMessage());
            throw $exception;
        }

        return response()->json([
            'message' => 'Google Calendar senkronizasyonu tamamlandi.',
            'result' => $result,
            'google_calendar' => $googleCalendar->getStatus(),
        ]);
    }

    public function export(Request $request)
    {
        $this->abortUnlessAllowed($request, 'calendar.export');
        $user = $request->user();
        $validated = $request->validate([
            'project_id' => 'nullable|exists:projects,id',
            'period_id' => 'nullable|exists:periods,id',
        ]);

        $projectIds = $this->permissionResolver->hasGlobalScope($user, 'calendar.export')
            ? Project::query()->pluck('id')->all()
            : $this->permissionResolver->projectIdsForPermission($user, 'calendar.export');

        if (empty($projectIds)) {
            $projectIds = [0];
        }

        $query = Program::query()
            ->with([
                'project:id,name',
                'period:id,name',
                'calendarEvent:id,program_id,google_event_id,assigned_users',
            ])
            ->whereIn('project_id', $projectIds)
            ->orderByDesc('start_at');

        if (! empty($validated['project_id'])) {
            abort_unless(
                in_array((int) $validated['project_id'], $projectIds, true),
                403,
                'Bu proje icin takvim export yetkiniz yok.'
            );
            $query->where('project_id', (int) $validated['project_id']);
        }

        if (! empty($validated['period_id'])) {
            $query->where('period_id', (int) $validated['period_id']);
        }

        $programs = $query->get();
        $assignedUserIds = $programs
            ->pluck('calendarEvent.assigned_users')
            ->filter()
            ->flatten()
            ->unique()
            ->values();

        $assignedUsers = User::query()
            ->whereIn('id', $assignedUserIds)
            ->get()
            ->keyBy('id');

        $headings = ['ID', 'Proje', 'Donem', 'Baslik', 'Konum', 'Baslangic', 'Bitis', 'Durum', 'Google', 'Atanan Kisi Sayisi', 'Atanan Kisiler'];
        $rows = $programs->map(function (Program $program) use ($assignedUsers) {
            $assignedIds = collect($program->calendarEvent?->assigned_users ?? [])
                ->filter(fn ($value) => is_numeric($value))
                ->map(fn ($value) => (int) $value)
                ->values();

            $assignedNames = $assignedIds
                ->map(function (int $userId) use ($assignedUsers) {
                    $user = $assignedUsers->get($userId);
                    return $user ? trim($user->name . ' ' . $user->surname) : null;
                })
                ->filter()
                ->values()
                ->implode(', ');

            return [
                $program->id,
                $program->project?->name ?? '-',
                $program->period?->name ?? '-',
                $program->title,
                $program->location ?? '-',
                optional($program->start_at)?->format('d.m.Y H:i') ?? '-',
                optional($program->end_at)?->format('d.m.Y H:i') ?? '-',
                $program->status ?? '-',
                $program->calendarEvent?->google_event_id ? 'senkron' : 'bekliyor',
                $assignedIds->count(),
                $assignedNames !== '' ? $assignedNames : '-',
            ];
        })->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'takvim_programlari_' . now()->format('Ymd_His'),
            'Takvim Programlari',
            $headings,
            $rows,
        );
    }
}
