<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Application;
use App\Models\ApplicationForm;
use App\Models\Assignment;
use App\Models\Certificate;
use App\Models\DigitalBohca;
use App\Models\Participant;
use App\Models\Period;
use App\Models\Program;
use App\Models\Project;
use App\Support\AdminExportResponder;
use App\Support\ProjectSpecialModuleCatalog;
use App\Services\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectContentController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {
    }

    public function manageable(Request $request): JsonResponse
    {
        $user = $request->user();
        $allowedPermissions = $this->manageablePermissionNames();
        $validated = $request->validate([
            'permission' => ['nullable', 'string', Rule::in($allowedPermissions)],
        ]);
        $targetPermission = $validated['permission'] ?? 'projects.view';

        abort_unless(
            $this->permissionResolver->hasPermission($user, $targetPermission),
            403,
            'Projelere erisim yetkiniz yok.'
        );

        $query = Project::query()->with([
            'periods' => fn ($builder) => $builder->orderByDesc('start_date'),
            'participants.user',
        ]);

        if (! $this->permissionResolver->hasGlobalScope($user, $targetPermission)) {
            $ids = $this->permissionResolver->projectIdsForPermission($user, $targetPermission);
            $query->whereIn('id', $ids === [] ? [-1] : $ids);
        }

        return response()->json([
            'projects' => ProjectResource::collection($query->orderBy('name')->get()),
        ]);
    }

    private function manageablePermissionNames(): array
    {
        $catalog = config('permission_catalog.granular_permissions', []);
        $permissions = collect($catalog)
            ->flatten()
            ->filter(fn ($permission) => is_string($permission) && $permission !== '')
            ->values()
            ->all();

        return array_values(array_unique([
            'projects.view',
            ...$permissions,
        ]));
    }

    public function exportManageable(Request $request)
    {
        $this->abortUnlessAllowedForProject($request, 'projects.export');
        $user = $request->user();

        $query = Project::query()->with([
            'periods' => fn ($builder) => $builder->orderByDesc('start_date'),
            'participants.user',
        ]);

        if (! $this->permissionResolver->hasGlobalScope($user, 'projects.export')) {
            $ids = $this->permissionResolver->projectIdsForPermission($user, 'projects.export');
            $query->whereIn('id', $ids === [] ? [-1] : $ids);
        }

        $projects = $query->orderBy('name')->get();
        $headings = ['ID', 'Proje', 'Slug', 'Tur', 'Durum', 'Aktif Donem', 'Aktif Ogrenci', 'Mezun', 'Basvuru Acik'];
        $rows = $projects->map(fn (Project $project) => [
            $project->id,
            $project->name,
            $project->slug,
            $project->type,
            $project->status,
            optional($project->periods->firstWhere('status', 'active'))->name ?? '-',
            $project->participants->where('status', 'active')->count(),
            $project->participants->where('graduation_status', 'graduated')->count(),
            $project->application_open ? 'evet' : 'hayir',
        ])->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'projeler_' . now()->format('Ymd_His'),
            'Projeler',
            $headings,
            $rows,
        );
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $project = Project::with(['periods', 'participants.user'])->findOrFail($id);
        $canView = $this->permissionResolver->canAccessProject($request->user(), 'projects.view', (int) $project->id);
        $canEdit = $this->permissionResolver->canAccessProject($request->user(), 'projects.content.update', (int) $project->id);
        abort_unless($canView || $canEdit, 403, 'Bu proje icerigini goruntuleme yetkiniz yok.');

        $applicationForm = ApplicationForm::where('project_id', $project->id)
            ->where('is_active', true)
            ->latest()
            ->first();

        return response()->json([
            'project' => new ProjectResource($project),
            'editable' => [
                'name' => $project->name,
                'slug' => $project->slug,
                'type' => $project->type,
                'short_description' => $project->short_description,
                'description' => $project->description,
                'cover_image_path' => $project->cover_image_path,
                'gallery_paths' => $project->gallery_paths ?? [],
                'application_open' => (bool) $project->application_open,
                'next_application_date' => optional($project->next_application_date)->format('Y-m-d'),
                'has_interview' => (bool) $project->has_interview,
                'quota' => $project->quota,
            ],
            'application_form' => $applicationForm,
        ]);
    }

    public function modules(Request $request, int $id): JsonResponse
    {
        $project = Project::with(['periods' => fn ($query) => $query->latest('start_date')])->findOrFail($id);
        $user = $request->user();
        $validated = $request->validate([
            'period_id' => 'nullable|integer|exists:periods,id',
        ]);

        if (! empty($validated['period_id']) && ! $project->periods->contains('id', $validated['period_id'])) {
            abort(422, 'Secilen donem bu projeye ait degil.');
        }

        $activePeriod = $project->periods->firstWhere('status', 'active');
        $selectedPeriodId = array_key_exists('period_id', $validated)
            ? ($validated['period_id'] ?: null)
            : ($activePeriod?->id);

        $permissions = [
            'projects.view',
            'projects.content.update',
            'projects.application_form.update',
            'projects.participants.view',
            'projects.alumni.view',
            'projects.student_cv.view',
            'projects.attendance.view',
            'projects.internships.view',
            'projects.internships.manage',
            'projects.mentors.view',
            'projects.mentors.manage',
            'projects.eurodesk.view',
            'projects.eurodesk.manage',
            'projects.rewards.view',
            'projects.rewards.manage',
            'programs.view',
            'programs.attendance.view',
            'applications.view',
            'volunteer.view',
            'digital_bohca.view',
            'assignments.view',
            'assignments.submissions.view',
            'certificates.view',
        ];

        $access = [];
        foreach ($permissions as $permission) {
            $access[$permission] = $this->permissionResolver->canAccessProject($user, $permission, $project->id);
        }
        $access = $this->filterModuleAccessByProjectType($project, $access);

        abort_unless(in_array(true, $access, true), 403, 'Bu proje icin yetkiniz bulunmuyor.');
        $request->attributes->set('audit.permission_checked', 'projects.modules.view');
        $request->attributes->set('audit.permission_scope', [
            'scope_type' => 'project',
            'project_id' => $project->id,
        ]);

        $payload = [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'type' => $project->type,
                'quota' => $project->quota,
                'application_open' => (bool) $project->application_open,
                'active_period' => optional($project->periods->firstWhere('status', 'active'))?->only(['id', 'name', 'status']),
                'selected_period' => optional($project->periods->firstWhere('id', $selectedPeriodId))?->only(['id', 'name', 'status', 'start_date', 'end_date']),
                'periods' => $project->periods
                    ->map(fn (Period $period) => $period->only(['id', 'name', 'status', 'start_date', 'end_date']))
                    ->values(),
            ],
            'access' => $access,
            'applicable_modules' => ProjectSpecialModuleCatalog::forProject($project),
            'summary' => [],
            'previews' => [],
        ];

        if ($access['projects.participants.view'] || $access['projects.alumni.view'] || $access['projects.student_cv.view']) {
            $participants = Participant::query()
                ->where('project_id', $project->id)
                ->when($selectedPeriodId, fn ($query) => $query->where('period_id', $selectedPeriodId))
                ->with(['user:id,name,surname,email,university,department,profile_photo_path', 'user.profile:id,user_id,digital_cv_data,linkedin_url,github_url'])
                ->latest()
                ->get();

            $payload['summary']['participants'] = [
                'total' => $participants->count(),
                'active' => $participants->where('status', 'active')->count(),
                'graduates' => $participants->filter(fn (Participant $participant) =>
                    $participant->graduation_status === 'graduated' || $participant->graduated_at !== null
                )->count(),
                'average_credit' => $participants->count() > 0 ? round($participants->avg('credit') ?? 0, 1) : 0,
            ];

            if ($access['projects.participants.view']) {
                $payload['previews']['participants'] = $participants
                    ->where('status', 'active')
                    ->take(5)
                    ->map(fn (Participant $participant) => $this->participantPreview($participant, false))
                    ->values();
            }

            if ($access['projects.alumni.view']) {
                $payload['previews']['alumni'] = $participants
                    ->filter(fn (Participant $participant) =>
                        $participant->graduation_status === 'graduated' || $participant->graduated_at !== null
                    )
                    ->take(5)
                    ->map(fn (Participant $participant) => $this->participantPreview($participant, false))
                    ->values();
            }

            if ($access['projects.student_cv.view']) {
                $payload['previews']['student_cvs'] = $participants
                    ->filter(fn (Participant $participant) => ! empty($participant->user?->profile?->digital_cv_data))
                    ->take(5)
                    ->map(fn (Participant $participant) => $this->participantPreview($participant, true))
                    ->values();
            }
        }

        if ($access['programs.view'] || $access['projects.attendance.view'] || $access['programs.attendance.view']) {
            $programs = Program::query()
                ->where('project_id', $project->id)
                ->when($selectedPeriodId, fn ($query) => $query->where('period_id', $selectedPeriodId))
                ->withCount([
                    'attendances',
                    'attendances as valid_attendances_count' => fn ($query) => $query->where('is_valid', true),
                ])
                ->orderByDesc('start_at')
                ->get();

            $payload['summary']['programs'] = [
                'total' => $programs->count(),
                'upcoming' => $programs->where('start_at', '>=', now())->count(),
                'completed' => $programs->where('status', 'completed')->count(),
                'total_attendances' => $programs->sum('attendances_count'),
                'valid_attendances' => $programs->sum('valid_attendances_count'),
            ];

            if ($access['projects.attendance.view'] || $access['programs.attendance.view']) {
                $payload['previews']['attendance'] = $programs
                    ->take(6)
                    ->map(fn (Program $program) => [
                        'id' => $program->id,
                        'title' => $program->title,
                        'start_at' => optional($program->start_at)?->toISOString(),
                        'status' => $program->status,
                        'credit_deduction' => $program->credit_deduction,
                        'attendances_count' => $program->attendances_count,
                        'valid_attendances_count' => $program->valid_attendances_count,
                    ])
                    ->values();
            }
        }

        if ($access['applications.view']) {
            $applicationQuery = Application::where('project_id', $project->id)
                ->when($selectedPeriodId, fn ($query) => $query->where('period_id', $selectedPeriodId));
            $payload['summary']['applications'] = [
                'total' => (clone $applicationQuery)->count(),
                'pending' => (clone $applicationQuery)->where('status', 'pending')->count(),
                'approved' => (clone $applicationQuery)->where('status', 'accepted')->count(),
                'rejected' => (clone $applicationQuery)->where('status', 'rejected')->count(),
                'waitlisted' => (clone $applicationQuery)->where('status', 'waitlisted')->count(),
            ];
        }

        if ($access['digital_bohca.view']) {
            $bohcaQuery = DigitalBohca::where('project_id', $project->id)
                ->when($selectedPeriodId, fn ($query) => $query->where(function ($builder) use ($selectedPeriodId) {
                    $builder->whereNull('period_id')->orWhere('period_id', $selectedPeriodId);
                }));
            $payload['summary']['digital_bohca'] = [
                'total' => (clone $bohcaQuery)->count(),
                'visible_to_student' => (clone $bohcaQuery)->where('visible_to_student', true)->count(),
            ];
        }

        if ($access['assignments.view']) {
            $assignmentQuery = Assignment::where('project_id', $project->id)
                ->when($selectedPeriodId, fn ($query) => $query->where('period_id', $selectedPeriodId));
            $payload['summary']['assignments'] = [
                'total' => (clone $assignmentQuery)->count(),
                'open' => (clone $assignmentQuery)->where(function ($query) {
                    $query->whereNull('due_date')->orWhere('due_date', '>=', now());
                })->count(),
                'submissions' => (clone $assignmentQuery)
                    ->withCount('submissions')
                    ->get()
                    ->sum('submissions_count'),
            ];
        }

        if ($access['certificates.view']) {
            $certificateQuery = Certificate::where('project_id', $project->id)
                ->when($selectedPeriodId, fn ($query) => $query->where('period_id', $selectedPeriodId));
            $payload['summary']['certificates'] = [
                'total' => (clone $certificateQuery)->count(),
                'issued_this_month' => (clone $certificateQuery)
                    ->whereBetween('issued_at', [now()->startOfMonth(), now()->endOfMonth()])
                    ->count(),
            ];
        }

        return response()->json($payload);
    }

    private function participantPreview(Participant $participant, bool $includeCv): array
    {
        return [
            'id' => $participant->id,
            'status' => $participant->status,
            'graduation_status' => $participant->graduation_status,
            'credit' => $participant->credit,
            'graduated_at' => optional($participant->graduated_at)?->toDateString(),
            'user' => [
                'id' => $participant->user?->id,
                'name' => $participant->user?->name,
                'surname' => $participant->user?->surname,
                'email' => $participant->user?->email,
                'university' => $participant->user?->university,
                'department' => $participant->user?->department,
                'profile_photo_path' => $participant->user?->profile_photo_path,
                'cv' => $includeCv ? [
                    'digital_cv_data' => $participant->user?->profile?->digital_cv_data,
                    'linkedin_url' => $participant->user?->profile?->linkedin_url,
                    'github_url' => $participant->user?->profile?->github_url,
                ] : null,
            ],
        ];
    }

    /**
     * @param  array<string, bool>  $access
     * @return array<string, bool>
     */
    private function filterModuleAccessByProjectType(Project $project, array $access): array
    {
        $modules = ProjectSpecialModuleCatalog::forProject($project);
        $families = [
            'projects.internships.' => ['internships'],
            'projects.mentors.' => ['mentors'],
            'projects.eurodesk.' => ['eurodesk_projects'],
            'projects.rewards.' => ['reward_tiers', 'participants_by_module', 'badges'],
        ];

        foreach ($families as $prefix => $requiredModules) {
            $supported = collect($requiredModules)->contains(fn (string $key) => in_array($key, $modules, true));
            foreach (array_keys($access) as $permission) {
                if (str_starts_with($permission, $prefix) && ! $supported) {
                    $access[$permission] = false;
                }
            }
        }

        return $access;
    }

    private function normalizeGalleryItems(array $items): array
    {
        return collect($items)
            ->map(function ($item) {
                if (is_string($item)) {
                    $path = trim($item);

                    return $path === '' ? null : ['path' => $path];
                }

                if (! is_array($item)) {
                    return null;
                }

                $path = trim((string) ($item['path'] ?? $item['url'] ?? ''));
                if ($path === '') {
                    return null;
                }

                $normalized = ['path' => $path];

                foreach (['caption', 'year'] as $field) {
                    $value = trim((string) ($item[$field] ?? ''));
                    if ($value !== '') {
                        $normalized[$field] = $value;
                    }
                }

                if (isset($item['period_id']) && is_numeric($item['period_id'])) {
                    $normalized['period_id'] = (int) $item['period_id'];
                }

                return $normalized;
            })
            ->filter()
            ->values()
            ->all();
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->abortUnlessAllowedForProject($request, 'projects.content.update', $project);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::unique('projects', 'slug')->ignore($project->id),
            ],
            'type' => 'required|string|max:255',
            'short_description' => 'nullable|string|max:1000',
            'description' => 'nullable|string',
            'cover_image_path' => 'nullable|string|max:2048',
            'gallery_paths' => 'nullable|array',
            'gallery_paths.*' => 'nullable',
            'gallery_paths.*.path' => 'nullable|string|max:2048',
            'gallery_paths.*.url' => 'nullable|string|max:2048',
            'gallery_paths.*.caption' => 'nullable|string|max:255',
            'gallery_paths.*.year' => 'nullable|string|max:32',
            'gallery_paths.*.period_id' => 'nullable|integer|exists:periods,id',
            'application_open' => 'required|boolean',
            'next_application_date' => 'nullable|date',
            'has_interview' => 'required|boolean',
            'quota' => 'nullable|integer|min:0',
        ]);
        $galleryItems = $this->normalizeGalleryItems($validated['gallery_paths'] ?? []);
        $galleryPeriodIds = collect($galleryItems)->pluck('period_id')->filter()->unique()->values()->all();
        if ($galleryPeriodIds !== []) {
            $validPeriodCount = Period::query()
                ->where('project_id', $project->id)
                ->whereIn('id', $galleryPeriodIds)
                ->count();

            abort_unless($validPeriodCount === count($galleryPeriodIds), 422, 'Galeri donemi bu projeye ait olmalidir.');
        }

        $project->update([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'type' => $validated['type'],
            'short_description' => $validated['short_description'] ?? null,
            'description' => $validated['description'] ?? null,
            'cover_image_path' => $validated['cover_image_path'] ?? null,
            'gallery_paths' => $galleryItems,
            'application_open' => $validated['application_open'],
            'next_application_date' => $validated['next_application_date'] ?? null,
            'has_interview' => $validated['has_interview'],
            'quota' => $validated['quota'] ?? null,
        ]);

        $project->load(['periods', 'participants.user']);

        return response()->json([
            'message' => 'Proje icerigi guncellendi.',
            'project' => new ProjectResource($project),
            'editable' => [
                'name' => $project->name,
                'slug' => $project->slug,
                'type' => $project->type,
                'short_description' => $project->short_description,
                'description' => $project->description,
                'cover_image_path' => $project->cover_image_path,
                'gallery_paths' => $project->gallery_paths ?? [],
                'application_open' => (bool) $project->application_open,
                'next_application_date' => optional($project->next_application_date)->format('Y-m-d'),
                'has_interview' => (bool) $project->has_interview,
                'quota' => $project->quota,
            ],
        ]);
    }

    public function applicationForm(Request $request, int $id): JsonResponse
    {
        $project = Project::with('periods')->findOrFail($id);
        $this->abortUnlessAllowedForProject($request, 'projects.view', $project);

        $validated = $request->validate([
            'period_id' => 'nullable|integer|exists:periods,id',
            'program_id' => 'nullable|integer|exists:programs,id',
        ]);

        if (! empty($validated['period_id']) && ! $project->periods->contains('id', $validated['period_id'])) {
            abort(422, 'Secilen donem bu projeye ait degil.');
        }

        $program = null;

        if (! empty($validated['program_id'])) {
            $program = Program::query()
                ->where('project_id', $project->id)
                ->findOrFail($validated['program_id']);

            if (! empty($validated['period_id']) && (int) $program->period_id !== (int) $validated['period_id']) {
                abort(422, 'Secilen program bu doneme ait degil.');
            }
        }

        $applicationFormQuery = ApplicationForm::where('project_id', $project->id)
            ->where('is_active', true);

        if ($program) {
            $applicationFormQuery->where('program_id', $program->id);
        } else {
            $applicationFormQuery->whereNull('program_id');
        }

        if ($request->has('period_id')) {
            $applicationFormQuery->where('period_id', $validated['period_id'] ?? null);
        }

        $applicationForm = $applicationFormQuery
            ->latest()
            ->first();

        return response()->json([
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
            ],
            'periods' => $project->periods()->orderByDesc('start_date')->get(),
            'programs' => Program::query()
                ->where('project_id', $project->id)
                ->orderByDesc('start_at')
                ->get(['id', 'project_id', 'period_id', 'title', 'start_at', 'status']),
            'application_form' => $applicationForm,
        ]);
    }

    public function updateApplicationForm(Request $request, int $id): JsonResponse
    {
        $project = Project::with('periods')->findOrFail($id);
        $this->abortUnlessAllowedForProject($request, 'projects.application_form.update', $project);

        $validated = $request->validate([
            'period_id' => 'nullable|exists:periods,id',
            'program_id' => 'nullable|exists:programs,id',
            'fields' => 'required|array|min:1',
            'fields.*.id' => 'required|string|max:100',
            'fields.*.type' => 'required|in:text,longtext,select,radio,checkbox,file',
            'fields.*.label' => 'required|string|max:255',
            'fields.*.required' => 'required|boolean',
            'fields.*.options' => 'nullable|array',
            'fields.*.options.*' => 'nullable|string|max:255',
            'require_consent' => 'sometimes|boolean',
            'consent_text' => 'nullable|string|max:5000',
            'is_active' => 'sometimes|boolean',
            'auto_reject_rules' => 'nullable|array',
            'auto_reject_rules.*.field_id' => 'required_with:auto_reject_rules|string|max:100',
            'auto_reject_rules.*.operator' => 'required_with:auto_reject_rules|string|in:equals,not_equals,contains,gt,lt,gte,lte',
            'auto_reject_rules.*.value' => 'required_with:auto_reject_rules|string|max:255',
            'auto_reject_rules.*.reason' => 'nullable|string|max:500',
        ]);

        if (!empty($validated['period_id']) && !$project->periods->contains('id', $validated['period_id'])) {
            abort(422, 'Secilen donem bu projeye ait degil.');
        }

        $program = null;

        if (! empty($validated['program_id'])) {
            $program = Program::query()
                ->where('project_id', $project->id)
                ->findOrFail($validated['program_id']);

            if (! empty($validated['period_id']) && (int) $program->period_id !== (int) $validated['period_id']) {
                abort(422, 'Secilen program bu doneme ait degil.');
            }

            $validated['period_id'] = $program->period_id;
        }

        ApplicationForm::query()
            ->where('project_id', $project->id)
            ->where('period_id', $validated['period_id'] ?? null)
            ->when($program, fn ($query) => $query->where('program_id', $program->id), fn ($query) => $query->whereNull('program_id'))
            ->update(['is_active' => false]);

        $form = ApplicationForm::updateOrCreate(
            [
                'project_id' => $project->id,
                'period_id' => $validated['period_id'] ?? null,
                'program_id' => $program?->id,
            ],
            [
                'fields' => array_map(function (array $field) {
                    $payload = [
                        'id' => $field['id'],
                        'type' => $field['type'],
                        'label' => $field['label'],
                        'required' => (bool) $field['required'],
                    ];

                    if (in_array($field['type'], ['select', 'radio', 'checkbox'], true)) {
                        $payload['options'] = array_values(array_filter($field['options'] ?? [], fn ($option) => $option !== null && $option !== ''));
                    }

                    return $payload;
                }, $validated['fields']),
                'require_consent' => (bool) ($validated['require_consent'] ?? false),
                'consent_text' => $validated['consent_text'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
                'auto_reject_rules' => $validated['auto_reject_rules'] ?? null,
            ]
        );

        return response()->json([
            'message' => 'Basvuru formu kaydedildi.',
            'application_form' => $form->fresh(),
        ]);
    }
}
