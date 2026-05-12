<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\ApplicationForm;
use App\Models\EurodeskProject;
use App\Models\Internship;
use App\Models\KpdRoom;
use App\Models\Mentor;
use App\Models\Program;
use App\Models\Project;
use App\Models\RewardTier;
use App\Support\MediaStorage;
use App\Support\ProjectSpecialModuleCatalog;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    /**
     * Ziyaretcilerin ve ogrencilerin gorebilecegi acik projeleri listeler.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:100',
            'type' => 'nullable|string|max:100',
        ]);

        $projects = Project::where('status', 'active')
            ->with(['periods' => function ($query) {
                $query->where('status', 'active');
            }])
            ->when(! empty($validated['search']), function ($query) use ($validated) {
                $search = $validated['search'];
                $query->where(function ($builder) use ($search) {
                    $builder
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('type', 'like', '%'.$search.'%')
                        ->orWhere('short_description', 'like', '%'.$search.'%')
                        ->orWhere('description', 'like', '%'.$search.'%');
                });
            })
            ->when(! empty($validated['type']), fn ($query) => $query->where('type', $validated['type']))
            ->orderBy('name')
            ->get();

        return response()->json([
            'projects' => ProjectResource::collection($projects),
        ]);
    }

    /**
     * Proje detayini ve basvuru formunu getirir.
     */
    public function show($slug)
    {
        $project = Project::where('slug', $slug)
            ->where('status', 'active')
            ->with([
                'periods',
                'participants.user',
            ])
            ->firstOrFail();

        $currentPeriod = $project->periods->where('status', 'active')->first();
        $applicationForm = $project->application_open
            ? $this->activeApplicationForm($project, $currentPeriod)
            : null;

        return response()->json([
            'project' => new ProjectResource($project),
            'current_period' => $currentPeriod,
            'application_form' => $applicationForm,
            'programs' => $this->publicProgramPayload($project),
            'project_specials' => $this->publicSpecialModules($project),
        ]);
    }

    private function publicProgramPayload(Project $project): array
    {
        $programs = Program::query()
            ->where('project_id', $project->id)
            ->with('period:id,name')
            ->whereIn('status', ['scheduled', 'active', 'completed'])
            ->orderBy('start_at')
            ->get();

        $now = now();

        $upcoming = $programs
            ->filter(fn (Program $program) => $program->start_at && $program->start_at->gte($now) && in_array($program->status, ['scheduled', 'active'], true))
            ->take(8)
            ->map(fn (Program $program) => $this->formatPublicProgram($program))
            ->values();

        $recentCompleted = $programs
            ->filter(fn (Program $program) => $program->status === 'completed' || ($program->end_at && $program->end_at->lt($now)))
            ->sortByDesc('start_at')
            ->take(8)
            ->map(fn (Program $program) => $this->formatPublicProgram($program))
            ->values();

        $calendar = $programs
            ->map(fn (Program $program) => $this->formatPublicProgram($program))
            ->values();

        $calendarMonths = $programs
            ->filter(fn (Program $program) => ! is_null($program->start_at))
            ->groupBy(fn (Program $program) => $program->start_at->format('Y-m'))
            ->map(function ($items, string $key) {
                $date = $items->first()->start_at;

                return [
                    'key' => $key,
                    'label' => $date->translatedFormat('F Y'),
                    'year' => (int) $date->format('Y'),
                    'month' => (int) $date->format('m'),
                    'count' => $items->count(),
                ];
            })
            ->values();

        return [
            'summary' => [
                'total' => $programs->count(),
                'upcoming' => $programs->filter(fn (Program $program) => $program->start_at && $program->start_at->gte($now))->count(),
                'completed' => $programs->filter(fn (Program $program) => $program->status === 'completed' || ($program->end_at && $program->end_at->lt($now)))->count(),
            ],
            'upcoming' => $upcoming,
            'recent_completed' => $recentCompleted,
            'calendar' => $calendar,
            'calendar_months' => $calendarMonths,
        ];
    }

    private function activeApplicationForm(Project $project, mixed $currentPeriod): ?ApplicationForm
    {
        if ($currentPeriod) {
            $periodForm = ApplicationForm::where('project_id', $project->id)
                ->where('period_id', $currentPeriod->id)
                ->where('is_active', true)
                ->latest()
                ->first();

            if ($periodForm) {
                return $periodForm;
            }
        }

        return ApplicationForm::where('project_id', $project->id)
            ->whereNull('period_id')
            ->where('is_active', true)
            ->latest()
            ->first();
    }

    private function formatPublicProgram(Program $program): array
    {
        return [
            'id' => $program->id,
            'title' => $program->title,
            'description' => $program->description,
            'location' => $program->location,
            'guest_info' => $program->guest_info,
            'status' => $program->status,
            'start_at' => optional($program->start_at)->toIso8601String(),
            'end_at' => optional($program->end_at)->toIso8601String(),
            'period' => $program->period ? [
                'id' => $program->period->id,
                'name' => $program->period->name,
            ] : null,
        ];
    }

    private function publicSpecialModules(Project $project): array
    {
        $keys = $this->projectModuleKeys($project);
        $payload = [
            'module_keys' => $keys,
        ];

        if (in_array('internships', $keys, true)) {
            $internships = Internship::query()
                ->whereHas('participant', fn ($query) => $query->where('project_id', $project->id))
                ->get(['company_name', 'position', 'start_date', 'end_date']);

            $payload['internships'] = [
                'total' => $internships->count(),
                'active' => $internships->filter(fn (Internship $internship) => ! $internship->end_date || $internship->end_date->isFuture())->count(),
                'companies' => $internships->pluck('company_name')->filter()->unique()->take(8)->values(),
                'positions' => $internships->pluck('position')->filter()->unique()->take(8)->values(),
            ];
        }

        if (in_array('mentors', $keys, true)) {
            $payload['mentors'] = Mentor::query()
                ->where('project_id', $project->id)
                ->latest()
                ->take(12)
                ->get()
                ->map(fn (Mentor $mentor) => [
                    'id' => $mentor->id,
                    'name' => $mentor->name,
                    'bio' => $mentor->bio,
                    'expertise' => $mentor->expertise,
                    'photo' => MediaStorage::url($mentor->photo_path),
                ])
                ->values();
        }

        if (in_array('reward_tiers', $keys, true)) {
            $payload['reward_tiers'] = RewardTier::query()
                ->where(function ($query) use ($project) {
                    $query->where('project_id', $project->id)->orWhereNull('project_id');
                })
                ->orderBy('min_badges')
                ->orderBy('min_credits')
                ->get(['id', 'name', 'description', 'min_badges', 'min_credits', 'reward_description'])
                ->values();
        }

        if (in_array('eurodesk_projects', $keys, true)) {
            $payload['eurodesk_projects'] = EurodeskProject::query()
                ->where('project_id', $project->id)
                ->latest('start_date')
                ->take(10)
                ->get()
                ->map(fn (EurodeskProject $eurodeskProject) => [
                    'id' => $eurodeskProject->id,
                    'title' => $eurodeskProject->title,
                    'partner_organizations' => $eurodeskProject->partner_organizations ?? [],
                    'grant_amount' => $eurodeskProject->grant_amount,
                    'grant_status' => $eurodeskProject->grant_status,
                    'start_date' => optional($eurodeskProject->start_date)->toDateString(),
                    'end_date' => optional($eurodeskProject->end_date)->toDateString(),
                ])
                ->values();
        }

        if (in_array('kpd_appointments', $keys, true)) {
            $payload['kpd'] = [
                'rooms' => KpdRoom::query()
                    ->get(['id', 'name', 'description'])
                    ->map(fn (KpdRoom $room) => [
                        'id' => $room->id,
                        'name' => $room->name,
                        'description' => $room->description,
                    ])
                    ->values(),
            ];
        }

        return $payload;
    }

    private function projectModuleKeys(Project $project): array
    {
        return ProjectSpecialModuleCatalog::forProject($project);
    }
}
