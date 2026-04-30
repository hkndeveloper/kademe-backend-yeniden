<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\ApplicationForm;
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
            'financial.view',
            'announcements.create',
            'projects.content.update',
            'projects.application_form.update',
            'periods.view',
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
        $this->abortUnlessAllowedForProject($request, 'projects.view', $project);

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
