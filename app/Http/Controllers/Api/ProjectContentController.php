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
use App\Models\Program;
use App\Models\Project;
use App\Support\AdminExportResponder;
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
        $allowedPermissions = [
            'projects.view',
            'programs.view',
            'programs.create',
            'programs.update',
            'programs.complete',
            'programs.qr.manage',
            'applications.view',
            'volunteer.view',
            'volunteer.manage',
            'financial.view',
            'announcements.create',
            'announcements.view',
            'announcements.update',
            'announcements.delete',
            'announcements.export',
            'announcements.send_sms',
            'announcements.send_email',
            'projects.content.update',
            'projects.application_form.update',
            'periods.view',
            'periods.create',
            'periods.update',
            'periods.export',
            'digital_bohca.view',
            'digital_bohca.create',
            'assignments.view',
            'assignments.create',
            'certificates.view',
            'certificates.create',
            'certificates.delete',
            'certificates.export',
            'projects.internships.view',
            'projects.internships.manage',
            'projects.mentors.view',
            'projects.mentors.manage',
            'projects.eurodesk.view',
            'projects.eurodesk.manage',
            'projects.rewards.view',
            'projects.rewards.manage',
        ];
        $validated = $request->validate([
            'permission' => ['nullable', 'string', Rule::in($allowedPermissions)],
        ]);
        $targetPermission = $validated['permission'] ?? 'projects.view';

        abort_unless(
            $this->permissionResolver->hasPermission($user, $targetPermission),
            403,
            'Projelere erisim yetkiniz yok.'
        );

        $query = Project::query()->with(['periods' => function ($builder) {
            $builder->where('status', 'active');
        }, 'participants.user']);

        if (! $this->permissionResolver->hasGlobalScope($user, $targetPermission)) {
            $ids = $this->permissionResolver->projectIdsForPermission($user, $targetPermission);
            $query->whereIn('id', $ids === [] ? [-1] : $ids);
        }

        return response()->json([
            'projects' => ProjectResource::collection($query->orderBy('name')->get()),
        ]);
    }

    public function exportManageable(Request $request)
    {
        $this->abortUnlessAllowedForProject($request, 'projects.export');
        $user = $request->user();

        $query = Project::query()->with(['periods' => function ($builder) {
            $builder->where('status', 'active');
        }, 'participants.user']);

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
            optional($project->periods->first())->name ?? '-',
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
            ],
            'access' => $access,
            'summary' => [],
            'previews' => [],
        ];

        if ($access['projects.participants.view'] || $access['projects.alumni.view'] || $access['projects.student_cv.view']) {
            $participants = Participant::query()
                ->where('project_id', $project->id)
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
            $payload['summary']['applications'] = [
                'total' => Application::where('project_id', $project->id)->count(),
                'pending' => Application::where('project_id', $project->id)->where('status', 'pending')->count(),
                'approved' => Application::where('project_id', $project->id)->where('status', 'approved')->count(),
                'rejected' => Application::where('project_id', $project->id)->where('status', 'rejected')->count(),
                'waitlisted' => Application::where('project_id', $project->id)->where('status', 'waitlisted')->count(),
            ];
        }

        if ($access['digital_bohca.view']) {
            $payload['summary']['digital_bohca'] = [
                'total' => DigitalBohca::where('project_id', $project->id)->count(),
                'visible_to_student' => DigitalBohca::where('project_id', $project->id)->where('visible_to_student', true)->count(),
            ];
        }

        if ($access['assignments.view']) {
            $assignmentQuery = Assignment::where('project_id', $project->id);
            $payload['summary']['assignments'] = [
                'total' => (clone $assignmentQuery)->count(),
                'open' => (clone $assignmentQuery)->where(function ($query) {
                    $query->whereNull('due_date')->orWhere('due_date', '>=', now());
                })->count(),
                'submissions' => Assignment::where('project_id', $project->id)
                    ->withCount('submissions')
                    ->get()
                    ->sum('submissions_count'),
            ];
        }

        if ($access['certificates.view']) {
            $payload['summary']['certificates'] = [
                'total' => Certificate::where('project_id', $project->id)->count(),
                'issued_this_month' => Certificate::where('project_id', $project->id)
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
            'gallery_paths.*' => 'nullable|string|max:2048',
            'application_open' => 'required|boolean',
            'next_application_date' => 'nullable|date',
            'has_interview' => 'required|boolean',
            'quota' => 'nullable|integer|min:0',
        ]);

        $project->update([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'type' => $validated['type'],
            'short_description' => $validated['short_description'] ?? null,
            'description' => $validated['description'] ?? null,
            'cover_image_path' => $validated['cover_image_path'] ?? null,
            'gallery_paths' => collect($validated['gallery_paths'] ?? [])->filter()->values()->all(),
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

        $applicationForm = ApplicationForm::where('project_id', $project->id)
            ->where('is_active', true)
            ->latest()
            ->first();

        return response()->json([
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
            ],
            'periods' => $project->periods()->orderByDesc('start_date')->get(),
            'application_form' => $applicationForm,
        ]);
    }

    public function updateApplicationForm(Request $request, int $id): JsonResponse
    {
        $project = Project::with('periods')->findOrFail($id);
        $this->abortUnlessAllowedForProject($request, 'projects.application_form.update', $project);

        $validated = $request->validate([
            'period_id' => 'nullable|exists:periods,id',
            'fields' => 'required|array|min:1',
            'fields.*.id' => 'required|string|max:100',
            'fields.*.type' => 'required|in:text,longtext,select,radio,checkbox,file',
            'fields.*.label' => 'required|string|max:255',
            'fields.*.required' => 'required|boolean',
            'fields.*.options' => 'nullable|array',
            'fields.*.options.*' => 'nullable|string|max:255',
            'is_active' => 'sometimes|boolean',
        ]);

        if (!empty($validated['period_id']) && !$project->periods->contains('id', $validated['period_id'])) {
            abort(422, 'Secilen donem bu projeye ait degil.');
        }

        ApplicationForm::query()
            ->where('project_id', $project->id)
            ->when(
                array_key_exists('period_id', $validated),
                fn ($query) => $query->where('period_id', $validated['period_id']),
                fn ($query) => $query->whereNull('period_id')
            )
            ->update(['is_active' => false]);

        $form = ApplicationForm::updateOrCreate(
            [
                'project_id' => $project->id,
                'period_id' => $validated['period_id'] ?? null,
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
                'is_active' => $validated['is_active'] ?? true,
            ]
        );

        return response()->json([
            'message' => 'Basvuru formu kaydedildi.',
            'application_form' => $form->fresh(),
        ]);
    }
}
