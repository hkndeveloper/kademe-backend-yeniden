<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Concerns\ResolvesProjectPeriodContext;
use App\Http\Controllers\Controller;
use App\Models\EurodeskPartnership;
use App\Models\EurodeskProject;
use App\Models\Internship;
use App\Models\Mentor;
use App\Models\Participant;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\ProjectModuleEnrollment;
use App\Models\RewardAward;
use App\Models\RewardTier;
use App\Models\User;
use App\Services\PermissionResolver;
use App\Support\ProjectSpecialModuleCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Schema;

/**
 * @group Project Special Modules
 */
class ProjectSpecialModuleController extends Controller
{
    use AuthorizesGranularPermissions;
    use ResolvesProjectPeriodContext;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {}

    private function project(Request $request, int $projectId, string $permission): Project
    {
        $project = Project::query()->findOrFail($projectId);
        $this->abortUnlessAllowedForProject($request, $permission, $project);

        return $project;
    }

    private function ensureProjectSupports(Project $project, string|array $moduleKeys): void
    {
        $required = is_array($moduleKeys) ? $moduleKeys : [$moduleKeys];
        $available = ProjectSpecialModuleCatalog::forProject($project);

        abort_unless(
            collect($required)->contains(fn (string $key) => in_array($key, $available, true)),
            404,
            'Bu proje turu bu ozel modulu desteklemiyor.'
        );
    }

    /**
     * @param  array<string, bool>  $access
     * @return array<string, bool>
     */
    private function filterAccessBySupportedModules(Project $project, array $access): array
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

    /**
     * Get project special module workspace.
     *
     * Requires at least one project-scoped module permission among internships, mentors, Eurodesk or rewards. The project must support the requested module family through `ProjectSpecialModuleCatalog`; unsupported families are hidden from the access matrix. Exposed under `/api/admin/projects/{id}/special-modules` and `/api/panel/projects/{id}/special-modules`.
     *
     * @authenticated
     * @urlParam id integer required Project ID. Example: 1
     * @queryParam period_id integer Optional period filter. Must belong to the project when provided. Example: 3
     * @response 200 {"project":{"id":1,"name":"Kademe+"},"access":{"projects.rewards.view":true},"applicable_modules":["reward_tiers","participants_by_module"],"participants":[],"kademe_modules":[]}
     * @response 403 {"message":"Bu proje modulleri icin yetkiniz bulunmuyor."}
     * @response 404 {"message":"Bu proje turu bu ozel modulu desteklemiyor."}
     */
    public function index(Request $request, int $projectId): JsonResponse
    {
        $project = Project::query()->findOrFail($projectId);
        $validated = $request->validate([
            'period_id' => 'nullable|integer|exists:periods,id',
        ]);
        $permissions = [
            'projects.internships.view',
            'projects.internships.manage',
            'projects.mentors.view',
            'projects.mentors.manage',
            'projects.eurodesk.view',
            'projects.eurodesk.manage',
            'projects.rewards.view',
            'projects.rewards.manage',
        ];
        $context = $this->resolveProjectPeriodContextForAnyPermission(
            $request,
            $permissions,
            $project->id,
            isset($validated['period_id']) ? (int) $validated['period_id'] : null,
        );
        $periodId = $context->periodId;

        $access = [];
        foreach ($permissions as $permission) {
            $access[$permission] = $this->permissionResolver->canAccessProject($request->user(), $permission, $project->id);
        }
        $access = $this->filterAccessBySupportedModules($project, $access);

        abort_unless(in_array(true, $access, true), 403, 'Bu proje modulleri icin yetkiniz bulunmuyor.');

        $participants = Participant::query()
            ->with('user:id,name,surname,email')
            ->where('project_id', $project->id)
            ->when($periodId, fn ($query) => $query->where('period_id', $periodId))
            ->orderByDesc('created_at')
            ->get();

        $rewardTiers = ($access['projects.rewards.view'] || $access['projects.rewards.manage'])
            ? RewardTier::query()->where(function ($query) use ($project) {
                $query->where('project_id', $project->id)->orWhereNull('project_id');
            })->latest()->get()
            : collect();

        return response()->json([
            'project' => $project->only(['id', 'name', 'slug', 'type']),
            'access' => $access,
            'applicable_modules' => ProjectSpecialModuleCatalog::forProject($project),
            'participants' => $participants->map(fn (Participant $participant) => [
                'id' => $participant->id,
                'name' => trim(($participant->user?->name ?? '').' '.($participant->user?->surname ?? '')),
                'email' => $participant->user?->email,
                'status' => $participant->status,
                'graduation_status' => $participant->graduation_status,
            ])->values(),
            'internships' => ($access['projects.internships.view'] || $access['projects.internships.manage'])
                ? Internship::query()
                    ->with('participant.user:id,name,surname,email')
                    ->whereHas('participant', fn ($query) => $query->where('project_id', $project->id))
                    ->when($periodId, fn ($query) => $query->whereHas('participant', fn ($inner) => $inner->where('period_id', $periodId)))
                    ->latest()
                    ->get()
                : [],
            'mentors' => ($access['projects.mentors.view'] || $access['projects.mentors.manage'])
                ? Mentor::query()
                    ->where('project_id', $project->id)
                    ->with(['participants' => fn ($query) => $query
                        ->when($periodId, fn ($builder) => $builder->wherePivot('period_id', $periodId))
                        ->with('user:id,name,surname,email')
                    ])
                    ->latest()
                    ->get()
                    ->map(fn (Mentor $mentor) => [
                        'id' => $mentor->id,
                        'name' => $mentor->name,
                        'expertise' => $mentor->expertise,
                        'bio' => $mentor->bio,
                        'photo_path' => $mentor->photo_path,
                        'assigned_participants' => $mentor->participants
                            ->map(fn (Participant $participant) => [
                                'id' => $participant->id,
                                'name' => trim(($participant->user?->name ?? '').' '.($participant->user?->surname ?? '')),
                                'email' => $participant->user?->email,
                                'period_id' => $participant->pivot?->period_id,
                                'note' => $participant->pivot?->note,
                            ])
                            ->values(),
                    ])
                    ->values()
                : [],
            'eurodesk_projects' => ($access['projects.eurodesk.view'] || $access['projects.eurodesk.manage'])
                ? EurodeskProject::query()
                    ->with(['partnerships', 'period:id,name,status'])
                    ->where('project_id', $project->id)
                    ->when($periodId, fn ($query) => $query->where(function ($builder) use ($periodId) {
                        $builder->whereNull('period_id')->orWhere('period_id', $periodId);
                    }))
                    ->latest()
                    ->get()
                : [],
            'eurodesk_summary' => ($access['projects.eurodesk.view'] || $access['projects.eurodesk.manage'])
                ? $this->eurodeskSummary($project, $periodId)
                : null,
            'reward_tiers' => ($access['projects.rewards.view'] || $access['projects.rewards.manage'])
                ? $rewardTiers
                : [],
            'reward_eligible_participants' => ($access['projects.rewards.view'] || $access['projects.rewards.manage'])
                ? $this->rewardEligibleParticipants($project, $participants, $rewardTiers)
                : [],
            'reward_awards' => ($access['projects.rewards.view'] || $access['projects.rewards.manage'])
                ? RewardAward::query()
                    ->with(['participant.user:id,name,surname,email', 'tier:id,name,reward_description', 'awarder:id,name,surname', 'deliverer:id,name,surname'])
                    ->where('project_id', $project->id)
                    ->when($periodId, fn ($query) => $query->whereHas('participant', fn ($inner) => $inner->where('period_id', $periodId)))
                    ->latest('awarded_at')
                    ->get()
                    ->map(fn (RewardAward $award) => $this->rewardAwardPayload($award))
                    ->values()
                : [],
            'kademe_modules' => $this->kademeModulesPayload($project, $access, $periodId),
        ]);
    }

    /**
     * @param  array<string, bool>  $access
     */
    private function kademeModulesPayload(Project $project, array $access, ?int $periodId = null): array
    {
        if (! ProjectSpecialModuleCatalog::supportsKademeModuleWorkflow($project)) {
            return [];
        }

        if (! ($access['projects.rewards.view'] ?? false) && ! ($access['projects.rewards.manage'] ?? false)) {
            return [];
        }

        $query = ProjectModule::query()
            ->where('project_id', $project->id)
            ->when(
                $periodId,
                fn ($builder) => $builder->where(fn ($inner) => $inner->whereNull('period_id')->orWhere('period_id', $periodId))
            )
            ->orderBy('sort_order');

        if ($access['projects.rewards.manage'] ?? false) {
            return $query
                ->with(['enrollments.user:id,name,surname,email'])
                ->get()
                ->map(fn (ProjectModule $module) => $this->serializeKademeModuleForPanel($module, true))
                ->values()
                ->all();
        }

        return $query
            ->withCount('enrollments')
            ->get()
            ->map(fn (ProjectModule $module) => $this->serializeKademeModuleForPanel($module, false))
            ->values()
            ->all();
    }

    private function serializeKademeModuleForPanel(ProjectModule $module, bool $includeEnrollments): array
    {
        $base = [
            'id' => $module->id,
            'title' => $module->title,
            'description' => $module->description,
            'period_id' => $module->period_id,
            'sort_order' => (int) $module->sort_order,
            'is_active' => (bool) $module->is_active,
            'application_open' => (bool) $module->application_open,
            'requires_consent' => (bool) $module->requires_consent,
            'consent_checkbox_label' => $module->consent_checkbox_label,
            'warning_text' => $module->warning_text,
            'requires_coordinator_approval' => (bool) $module->requires_coordinator_approval,
            'outcomes' => $module->outcomes ?? [],
            'instructors' => $module->instructors ?? [],
            'faq_items' => $module->faq_items ?? [],
        ];

        if ($includeEnrollments) {
            $base['enrollments'] = $module->enrollments->map(fn (ProjectModuleEnrollment $row) => [
                'id' => $row->id,
                'user_id' => $row->user_id,
                'participant_id' => $row->participant_id,
                'status' => $row->status,
                'consented_at' => optional($row->consented_at)?->toIso8601String(),
                'reviewed_at' => optional($row->reviewed_at)?->toIso8601String(),
                'note' => $row->note,
                'user' => $row->user ? [
                    'name' => trim(($row->user->name ?? '').' '.($row->user->surname ?? '')),
                    'email' => $row->user->email,
                ] : null,
            ])->values()->all();
        } else {
            $base['enrollments_count'] = (int) ($module->enrollments_count ?? $module->enrollments()->count());
        }

        return $base;
    }

    /**
     * Create a KADEME+ project module.
     *
     * Requires permission: `projects.rewards.manage` for the project and a project type that supports KADEME+ module workflow. Optional period must belong to the project and completed periods require archive update permission.
     *
     * @authenticated
     * @urlParam id integer required Project ID. Example: 1
     * @bodyParam title string required Module title. Example: Liderlik Atolyesi
     * @bodyParam period_id integer Optional period ID. Example: 3
     * @bodyParam description string Optional module description.
     * @bodyParam sort_order integer Optional display order. Example: 1
     * @bodyParam is_active boolean Optional active flag. Example: true
     * @bodyParam application_open boolean Optional student enrollment flag. Example: true
     * @bodyParam requires_consent boolean Optional consent requirement. Example: true
     * @bodyParam consent_checkbox_label string Optional consent label.
     * @bodyParam warning_text string Optional warning text.
     * @bodyParam requires_coordinator_approval boolean Optional review requirement. Example: false
     * @bodyParam outcomes string[] Optional outcome bullets. Example: ["Takim calismasi"]
     * @bodyParam instructors object[] Optional instructor cards.
     * @bodyParam faq_items object[] Optional FAQ items.
     * @response 201 {"message":"KADEME+ modulu kaydedildi.","kademe_module":{"id":5,"title":"Liderlik Atolyesi"}}
     * @response 403 {"message":"Bu proje icin yetkiniz bulunmuyor."}
     * @response 422 {"message":"Bu proje turu KADEME+ modullerini desteklemiyor."}
     */
    public function storeKademeModule(Request $request, int $projectId): JsonResponse
    {
        $project = $this->project($request, $projectId, 'projects.rewards.manage');
        abort_unless(ProjectSpecialModuleCatalog::supportsKademeModuleWorkflow($project), 422, 'Bu proje turu KADEME+ modullerini desteklemiyor.');

        $validated = $this->validatedKademeModule($request, true);
        if (! empty($validated['period_id'])) {
            abort_unless(
                \App\Models\Period::query()->whereKey((int) $validated['period_id'])->where('project_id', $projectId)->exists(),
                422,
                'Secilen donem bu projeye ait degil.'
            );
            $this->assertPeriodWritable($request, (int) $validated['period_id']);
        }

        $module = ProjectModule::query()->create(array_merge([
            'sort_order' => 0,
            'is_active' => true,
            'application_open' => true,
            'requires_consent' => true,
            'requires_coordinator_approval' => false,
        ], $validated, ['project_id' => $projectId]));

        return response()->json([
            'message' => 'KADEME+ modulu kaydedildi.',
            'kademe_module' => $this->serializeKademeModuleForPanel($module->fresh(), true),
        ], 201);
    }

    /**
     * Update a KADEME+ project module.
     *
     * Requires permission: `projects.rewards.manage` for the project and KADEME+ workflow support. Existing and new period scopes are checked for archive lock.
     *
     * @authenticated
     * @urlParam id integer required Project ID. Example: 1
     * @urlParam item integer required KADEME+ module ID. Example: 5
     * @bodyParam title string Optional module title. Example: Guncel Liderlik Atolyesi
     * @bodyParam period_id integer Optional period ID belonging to the project. Example: 3
     * @bodyParam description string Optional module description.
     * @bodyParam is_active boolean Optional active flag. Example: true
     * @bodyParam application_open boolean Optional enrollment flag. Example: false
     * @response 200 {"message":"KADEME+ modulu guncellendi.","kademe_module":{"id":5,"title":"Guncel Liderlik Atolyesi"}}
     * @response 403 {"message":"Bu proje icin yetkiniz bulunmuyor."}
     * @response 422 {"message":"Secilen donem bu projeye ait degil."}
     */
    public function updateKademeModule(Request $request, int $projectId, int $id): JsonResponse
    {
        $project = $this->project($request, $projectId, 'projects.rewards.manage');
        abort_unless(ProjectSpecialModuleCatalog::supportsKademeModuleWorkflow($project), 422, 'Bu proje turu KADEME+ modullerini desteklemiyor.');
        $module = ProjectModule::query()->where('project_id', $projectId)->findOrFail($id);
        $validated = $this->validatedKademeModule($request, false);
        if (! empty($validated['period_id'])) {
            abort_unless(
                \App\Models\Period::query()->whereKey((int) $validated['period_id'])->where('project_id', $projectId)->exists(),
                422,
                'Secilen donem bu projeye ait degil.'
            );
        }
        $this->assertPeriodWritable($request, $module->period_id);
        $this->assertPeriodWritable($request, isset($validated['period_id']) ? (int) $validated['period_id'] : null);
        $module->update($validated);

        return response()->json([
            'message' => 'KADEME+ modulu guncellendi.',
            'kademe_module' => $this->serializeKademeModuleForPanel($module->fresh(['enrollments.user:id,name,surname,email']), true),
        ]);
    }

    /**
     * Delete a KADEME+ project module.
     *
     * Requires permission: `projects.rewards.manage` for the project and KADEME+ workflow support. The module period is checked for archive lock before deletion.
     *
     * @authenticated
     * @urlParam id integer required Project ID. Example: 1
     * @urlParam item integer required KADEME+ module ID. Example: 5
     * @response 200 {"message":"KADEME+ modulu silindi."}
     * @response 403 {"message":"Bu proje icin yetkiniz bulunmuyor."}
     */
    public function destroyKademeModule(Request $request, int $projectId, int $id): JsonResponse
    {
        $project = $this->project($request, $projectId, 'projects.rewards.manage');
        abort_unless(ProjectSpecialModuleCatalog::supportsKademeModuleWorkflow($project), 422, 'Bu proje turu KADEME+ modullerini desteklemiyor.');
        $module = ProjectModule::query()->where('project_id', $projectId)->findOrFail($id);
        $this->assertPeriodWritable($request, $module->period_id);
        $module->delete();

        return response()->json(['message' => 'KADEME+ modulu silindi.']);
    }

    /**
     * Review a KADEME+ module enrollment.
     *
     * Requires permission: `projects.rewards.manage`, project access and support for `participants_by_module`. The enrollment must belong to a module in the selected project; completed periods require archive update permission.
     *
     * @authenticated
     * @urlParam id integer required Project ID. Example: 1
     * @urlParam enrollmentId integer required Enrollment ID. Example: 12
     * @bodyParam status string required Review status: `pending`, `approved` or `rejected`. Example: approved
     * @bodyParam note string Optional reviewer note. Example: Uygun goruldu.
     * @response 200 {"message":"Modul kaydi guncellendi.","enrollment":{"id":12,"status":"approved"}}
     * @response 403 {"message":"Bu proje icin yetkiniz bulunmuyor."}
     * @response 422 {"message":"The given data was invalid."}
     */
    public function updateKademeModuleEnrollment(Request $request, int $projectId, int $enrollmentId): JsonResponse
    {
        $project = $this->project($request, $projectId, 'projects.rewards.manage');
        $this->ensureProjectSupports($project, 'participants_by_module');
        $enrollment = ProjectModuleEnrollment::query()
            ->with('module:id,project_id,period_id')
            ->whereHas('module', fn ($q) => $q->where('project_id', $projectId))
            ->findOrFail($enrollmentId);
        $this->assertPeriodWritable($request, $enrollment->module?->period_id);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['pending', 'approved', 'rejected'])],
            'note' => 'nullable|string|max:2000',
        ]);

        $enrollment->update([
            'status' => $validated['status'],
            'note' => $validated['note'] ?? $enrollment->note,
            'reviewed_at' => now(),
            'reviewed_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Modul kaydi guncellendi.',
            'enrollment' => $enrollment->fresh(['user:id,name,surname,email']),
        ]);
    }

    private function validatedKademeModule(Request $request, bool $creating): array
    {
        $titleRule = $creating ? 'required' : 'sometimes|required';

        return $request->validate([
            'title' => $titleRule.'|string|max:255',
            'period_id' => 'nullable|integer|exists:periods,id',
            'description' => 'nullable|string|max:20000',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'sometimes|boolean',
            'application_open' => 'sometimes|boolean',
            'requires_consent' => 'sometimes|boolean',
            'consent_checkbox_label' => 'nullable|string|max:2000',
            'warning_text' => 'nullable|string|max:20000',
            'requires_coordinator_approval' => 'sometimes|boolean',
            'outcomes' => 'nullable|array',
            'outcomes.*' => 'string|max:1000',
            'instructors' => 'nullable|array',
            'instructors.*.name' => 'required_with:instructors|string|max:255',
            'instructors.*.bio' => 'nullable|string|max:5000',
            'instructors.*.photo_path' => 'nullable|string|max:2048',
            'faq_items' => 'nullable|array',
            'faq_items.*.question' => 'required_with:faq_items|string|max:500',
            'faq_items.*.answer' => 'required_with:faq_items|string|max:5000',
        ]);
    }

    /**
     * Create an internship record.
     *
     * Requires permission: `projects.internships.manage`, project access and project support for `internships`. Participant must belong to the project; participant period is checked for archive lock.
     *
     * @authenticated
     * @urlParam id integer required Project ID. Example: 1
     * @bodyParam participant_id integer required Participant ID in the project. Example: 42
     * @bodyParam company_name string required Company name. Example: ACME A.S.
     * @bodyParam position string required Position title. Example: Stajyer
     * @bodyParam start_date date required Start date. Example: 2026-07-01
     * @bodyParam end_date date Optional end date. Example: 2026-08-30
     * @bodyParam description string Optional description.
     * @bodyParam document_path string Optional document path or URL.
     * @response 201 {"message":"Staj bilgisi kaydedildi.","internship":{"id":7,"company_name":"ACME A.S."}}
     * @response 422 {"message":"Secilen katilimci bu projeye ait degil."}
     */
    public function storeInternship(Request $request, int $projectId): JsonResponse
    {
        $project = $this->project($request, $projectId, 'projects.internships.manage');
        $this->ensureProjectSupports($project, 'internships');
        $validated = $request->validate([
            'participant_id' => 'required|exists:participants,id',
            'company_name' => 'required|string|max:255',
            'position' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'description' => 'nullable|string|max:5000',
            'document_path' => 'nullable|string|max:2048',
        ]);

        $participant = Participant::query()->where('id', $validated['participant_id'])->where('project_id', $projectId)->first();
        abort_unless($participant, 422, 'Secilen katilimci bu projeye ait degil.');
        $this->assertPeriodWritable($request, $participant->period_id);

        return response()->json([
            'message' => 'Staj bilgisi kaydedildi.',
            'internship' => Internship::create($validated)->load('participant.user:id,name,surname,email'),
        ], 201);
    }

    /**
     * Update an internship record.
     *
     * Requires permission: `projects.internships.manage`, project access and project support for `internships`. The internship must belong to a participant in the selected project.
     *
     * @authenticated
     * @urlParam id integer required Project ID. Example: 1
     * @urlParam item integer required Internship ID. Example: 7
     * @bodyParam company_name string required Company name. Example: ACME A.S.
     * @bodyParam position string required Position title. Example: Junior Analyst
     * @bodyParam start_date date required Start date. Example: 2026-07-01
     * @bodyParam end_date date Optional end date. Example: 2026-08-30
     * @bodyParam description string Optional description.
     * @bodyParam document_path string Optional document path or URL.
     * @response 200 {"message":"Staj bilgisi guncellendi.","internship":{"id":7}}
     */
    public function updateInternship(Request $request, int $projectId, int $id): JsonResponse
    {
        $project = $this->project($request, $projectId, 'projects.internships.manage');
        $this->ensureProjectSupports($project, 'internships');
        $internship = Internship::query()
            ->with('participant:id,period_id')
            ->whereHas('participant', fn ($query) => $query->where('project_id', $projectId))
            ->findOrFail($id);
        $this->assertPeriodWritable($request, $internship->participant?->period_id);
        $validated = $request->validate([
            'company_name' => 'required|string|max:255',
            'position' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'description' => 'nullable|string|max:5000',
            'document_path' => 'nullable|string|max:2048',
        ]);

        $internship->update($validated);

        return response()->json(['message' => 'Staj bilgisi guncellendi.', 'internship' => $internship->fresh('participant.user:id,name,surname,email')]);
    }

    /**
     * Delete an internship record.
     *
     * Requires permission: `projects.internships.manage`, project access and project support for `internships`. The participant period is checked for archive lock before deletion.
     *
     * @authenticated
     * @urlParam id integer required Project ID. Example: 1
     * @urlParam item integer required Internship ID. Example: 7
     * @response 200 {"message":"Staj bilgisi silindi."}
     */
    public function destroyInternship(Request $request, int $projectId, int $id): JsonResponse
    {
        $project = $this->project($request, $projectId, 'projects.internships.manage');
        $this->ensureProjectSupports($project, 'internships');
        $internship = Internship::query()
            ->with('participant:id,period_id')
            ->whereHas('participant', fn ($query) => $query->where('project_id', $projectId))
            ->findOrFail($id);
        $this->assertPeriodWritable($request, $internship->participant?->period_id);
        $internship->delete();

        return response()->json(['message' => 'Staj bilgisi silindi.']);
    }

    /**
     * Create a project mentor.
     *
     * Requires permission: `projects.mentors.manage`, project access and project support for `mentors`.
     *
     * @authenticated
     * @urlParam id integer required Project ID. Example: 2
     * @bodyParam name string required Mentor name. Example: Ayse Kaya
     * @bodyParam bio string Optional mentor bio.
     * @bodyParam expertise string Optional expertise. Example: Kariyer Planlama
     * @bodyParam photo_path string Optional photo path or URL.
     * @response 201 {"message":"Mentor kaydedildi.","mentor":{"id":4,"name":"Ayse Kaya"}}
     */
    public function storeMentor(Request $request, int $projectId): JsonResponse
    {
        $project = $this->project($request, $projectId, 'projects.mentors.manage');
        $this->ensureProjectSupports($project, 'mentors');
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'bio' => 'nullable|string|max:5000',
            'expertise' => 'nullable|string|max:255',
            'photo_path' => 'nullable|string|max:2048',
        ]);

        return response()->json([
            'message' => 'Mentor kaydedildi.',
            'mentor' => Mentor::create($validated + ['project_id' => $projectId]),
        ], 201);
    }

    /**
     * Update a project mentor.
     *
     * Requires permission: `projects.mentors.manage`, project access and project support for `mentors`.
     *
     * @authenticated
     * @urlParam id integer required Project ID. Example: 2
     * @urlParam item integer required Mentor ID. Example: 4
     * @bodyParam name string required Mentor name. Example: Ayse Kaya
     * @bodyParam bio string Optional mentor bio.
     * @bodyParam expertise string Optional expertise.
     * @bodyParam photo_path string Optional photo path or URL.
     * @response 200 {"message":"Mentor guncellendi.","mentor":{"id":4}}
     */
    public function updateMentor(Request $request, int $projectId, int $id): JsonResponse
    {
        $project = $this->project($request, $projectId, 'projects.mentors.manage');
        $this->ensureProjectSupports($project, 'mentors');
        $mentor = Mentor::query()->where('project_id', $projectId)->findOrFail($id);
        $mentor->update($request->validate([
            'name' => 'required|string|max:255',
            'bio' => 'nullable|string|max:5000',
            'expertise' => 'nullable|string|max:255',
            'photo_path' => 'nullable|string|max:2048',
        ]));

        return response()->json(['message' => 'Mentor guncellendi.', 'mentor' => $mentor->fresh()]);
    }

    /**
     * Delete a project mentor.
     *
     * Requires permission: `projects.mentors.manage`, project access and project support for `mentors`.
     *
     * @authenticated
     * @urlParam id integer required Project ID. Example: 2
     * @urlParam item integer required Mentor ID. Example: 4
     * @response 200 {"message":"Mentor silindi."}
     */
    public function destroyMentor(Request $request, int $projectId, int $id): JsonResponse
    {
        $project = $this->project($request, $projectId, 'projects.mentors.manage');
        $this->ensureProjectSupports($project, 'mentors');
        Mentor::query()->where('project_id', $projectId)->findOrFail($id)->delete();

        return response()->json(['message' => 'Mentor silindi.']);
    }

    // -------------------------------------------------------
    // Mentor-Katilimci eslestirme (Pergel)
    // -------------------------------------------------------

    /**
     * Assign a mentor to a participant.
     *
     * Requires permission: `projects.mentors.manage`, project access and project support for `mentors`. Participant and mentor must belong to the project; optional period must belong to the project and match the participant period.
     *
     * @authenticated
     * @urlParam id integer required Project ID. Example: 2
     * @urlParam mentorId integer required Mentor ID. Example: 4
     * @bodyParam participant_id integer required Participant ID. Example: 42
     * @bodyParam period_id integer Optional period ID. Example: 3
     * @bodyParam note string Optional assignment note. Example: Ilk gorusme planlandi.
     * @response 200 {"message":"Katilimci mentor ile eslendi."}
     * @response 422 {"message":"Secilen katilimci bu doneme ait degil."}
     */
    public function assignMentorToParticipant(Request $request, int $projectId, int $mentorId): JsonResponse
    {
        $project = $this->project($request, $projectId, 'projects.mentors.manage');
        $this->ensureProjectSupports($project, 'mentors');
        $mentor = Mentor::query()->where('project_id', $projectId)->findOrFail($mentorId);

        $validated = $request->validate([
            'participant_id' => 'required|integer|exists:participants,id',
            'period_id'      => 'nullable|integer|exists:periods,id',
            'note'           => 'nullable|string|max:1000',
        ]);

        $participant = Participant::query()
            ->where('project_id', $projectId)
            ->findOrFail((int) $validated['participant_id']);

        if (! empty($validated['period_id'])) {
            $this->assertOptionalPeriodBelongsToProject($validated['period_id'], $projectId);
            $this->assertPeriodWritable($request, (int) $validated['period_id']);
            abort_unless(
                (int) $participant->period_id === (int) $validated['period_id'],
                422,
                'Secilen katilimci bu doneme ait degil.'
            );
        }

        $pivotData = [
            'period_id' => $validated['period_id'] ?? $participant->period_id,
        ];

        if (Schema::hasColumn('participant_mentor', 'assigned_by')) {
            $pivotData['assigned_by'] = $request->user()->id;
        }

        if (Schema::hasColumn('participant_mentor', 'note')) {
            $pivotData['note'] = $validated['note'] ?? null;
        }

        $mentor->participants()->syncWithoutDetaching([
            $validated['participant_id'] => $pivotData,
        ]);

        return response()->json(['message' => 'Katilimci mentor ile eslendi.']);
    }

    /**
     * Remove a mentor assignment from a participant.
     *
     * Requires permission: `projects.mentors.manage`, project access and project support for `mentors`. Participant period is checked for archive lock.
     *
     * @authenticated
     * @urlParam id integer required Project ID. Example: 2
     * @urlParam mentorId integer required Mentor ID. Example: 4
     * @urlParam participantId integer required Participant ID. Example: 42
     * @response 200 {"message":"Katilimci mentor eslestirmesi kaldirildi."}
     */
    public function unassignMentorFromParticipant(Request $request, int $projectId, int $mentorId, int $participantId): JsonResponse
    {
        $project = $this->project($request, $projectId, 'projects.mentors.manage');
        $this->ensureProjectSupports($project, 'mentors');
        $mentor = Mentor::query()->where('project_id', $projectId)->findOrFail($mentorId);
        $participant = Participant::query()
            ->where('project_id', $projectId)
            ->findOrFail($participantId);
        $this->assertPeriodWritable($request, $participant->period_id);
        $mentor->participants()->detach($participantId);

        return response()->json(['message' => 'Katilimci mentor eslestirmesi kaldirildi.']);
    }

    /**
     * List participants assigned to a mentor.
     *
     * Requires permission: `projects.mentors.view`, project access and project support for `mentors`. Optional period filter must belong to the project.
     *
     * @authenticated
     * @urlParam id integer required Project ID. Example: 2
     * @urlParam mentorId integer required Mentor ID. Example: 4
     * @queryParam period_id integer Optional period filter. Example: 3
     * @response 200 {"mentor":{"id":4,"name":"Ayse Kaya"},"participants":[]}
     */
    public function mentorParticipants(Request $request, int $projectId, int $mentorId): JsonResponse
    {
        $project = $this->project($request, $projectId, 'projects.mentors.view');
        $this->ensureProjectSupports($project, 'mentors');
        $validated = $request->validate([
            'period_id' => 'nullable|integer|exists:periods,id',
        ]);
        if (! empty($validated['period_id'])) {
            $this->assertOptionalPeriodBelongsToProject($validated['period_id'], $projectId);
        }

        $mentor = Mentor::query()->where('project_id', $projectId)->with([
            'participants' => fn ($query) => $query
                ->when(! empty($validated['period_id']), fn ($builder) => $builder->wherePivot('period_id', (int) $validated['period_id']))
                ->with('user:id,name,surname,email'),
        ])->findOrFail($mentorId);

        return response()->json([
            'mentor'       => $mentor,
            'participants' => $mentor->participants,
        ]);
    }

    private function eurodeskSummary(Project $project, ?int $periodId): array
    {
        $rows = EurodeskProject::query()
            ->with('partnerships:id,eurodesk_project_id,country')
            ->where('project_id', $project->id)
            ->when($periodId, fn ($query) => $query->where(function ($builder) use ($periodId) {
                $builder->whereNull('period_id')->orWhere('period_id', $periodId);
            }))
            ->get();

        $countries = $rows
            ->flatMap(fn (EurodeskProject $row) => $row->partnerships->pluck('country'))
            ->filter()
            ->map(fn ($country) => trim((string) $country))
            ->filter()
            ->unique()
            ->values();

        return [
            'total_projects' => $rows->count(),
            'applied_projects' => $rows->where('grant_status', 'applied')->count(),
            'approved_projects' => $rows->where('grant_status', 'approved')->count(),
            'completed_projects' => $rows->where('grant_status', 'completed')->count(),
            'rejected_projects' => $rows->where('grant_status', 'rejected')->count(),
            'total_grant_amount' => round((float) $rows->sum(fn (EurodeskProject $row) => (float) $row->grant_amount), 2),
            'approved_grant_amount' => round((float) $rows->where('grant_status', 'approved')->sum(fn (EurodeskProject $row) => (float) $row->grant_amount), 2),
            'partnership_count' => $rows->sum(fn (EurodeskProject $row) => $row->partnerships->count()),
            'country_count' => $countries->count(),
            'countries' => $countries->all(),
        ];
    }
    /**
     * Create a Eurodesk project record.
     *
     * Requires permission: `projects.eurodesk.manage`, project access and project support for `eurodesk_projects`. Optional period must belong to the project and completed periods require archive update permission.
     *
     * @authenticated
     * @urlParam id integer required Project ID. Example: 6
     * @bodyParam period_id integer Optional period ID. Example: 3
     * @bodyParam title string required Eurodesk project title. Example: Youth Exchange
     * @bodyParam partner_organizations string[] Optional partner organization names.
     * @bodyParam grant_amount number Optional grant amount. Example: 25000
     * @bodyParam grant_status string required Grant status: `applied`, `approved`, `rejected` or `completed`. Example: applied
     * @bodyParam start_date date Optional start date. Example: 2026-07-01
     * @bodyParam end_date date Optional end date. Example: 2026-09-01
     * @response 201 {"message":"Eurodesk proje bilgisi kaydedildi.","eurodesk_project":{"id":9,"title":"Youth Exchange"}}
     */
    public function storeEurodeskProject(Request $request, int $projectId): JsonResponse
    {
        $project = $this->project($request, $projectId, 'projects.eurodesk.manage');
        $this->ensureProjectSupports($project, 'eurodesk_projects');
        $validated = $this->validateEurodesk($request);
        $this->assertOptionalPeriodBelongsToProject($validated['period_id'] ?? null, $projectId);
        $this->assertPeriodWritable($request, isset($validated['period_id']) ? (int) $validated['period_id'] : null);

        return response()->json([
            'message' => 'Eurodesk proje bilgisi kaydedildi.',
            'eurodesk_project' => EurodeskProject::create($validated + ['project_id' => $projectId])->load(['partnerships', 'period:id,name,status']),
        ], 201);
    }

    /**
     * Update a Eurodesk project record.
     *
     * Requires permission: `projects.eurodesk.manage`, project access and project support for `eurodesk_projects`. Existing and target period scopes are checked for archive lock.
     *
     * @authenticated
     * @urlParam id integer required Project ID. Example: 6
     * @urlParam item integer required Eurodesk project ID. Example: 9
     * @bodyParam title string required Eurodesk project title. Example: Youth Exchange 2026
     * @bodyParam grant_status string required Grant status: `applied`, `approved`, `rejected` or `completed`. Example: approved
     * @response 200 {"message":"Eurodesk proje bilgisi guncellendi.","eurodesk_project":{"id":9}}
     */
    public function updateEurodeskProject(Request $request, int $projectId, int $id): JsonResponse
    {
        $project = $this->project($request, $projectId, 'projects.eurodesk.manage');
        $this->ensureProjectSupports($project, 'eurodesk_projects');
        $eurodeskProject = EurodeskProject::query()->where('project_id', $projectId)->findOrFail($id);
        $validated = $this->validateEurodesk($request);
        $this->assertOptionalPeriodBelongsToProject($validated['period_id'] ?? null, $projectId);
        $this->assertPeriodWritable($request, $eurodeskProject->period_id);
        $this->assertPeriodWritable($request, isset($validated['period_id']) ? (int) $validated['period_id'] : null);
        $eurodeskProject->update($validated);

        return response()->json(['message' => 'Eurodesk proje bilgisi guncellendi.', 'eurodesk_project' => $eurodeskProject->fresh(['partnerships', 'period:id,name,status'])]);
    }

    /**
     * Delete a Eurodesk project record.
     *
     * Requires permission: `projects.eurodesk.manage`, project access and project support for `eurodesk_projects`. The record period is checked for archive lock before deletion.
     *
     * @authenticated
     * @urlParam id integer required Project ID. Example: 6
     * @urlParam item integer required Eurodesk project ID. Example: 9
     * @response 200 {"message":"Eurodesk proje bilgisi silindi."}
     */
    public function destroyEurodeskProject(Request $request, int $projectId, int $id): JsonResponse
    {
        $project = $this->project($request, $projectId, 'projects.eurodesk.manage');
        $this->ensureProjectSupports($project, 'eurodesk_projects');
        $eurodeskProject = EurodeskProject::query()->where('project_id', $projectId)->findOrFail($id);
        $this->assertPeriodWritable($request, $eurodeskProject->period_id);
        $eurodeskProject->delete();

        return response()->json(['message' => 'Eurodesk proje bilgisi silindi.']);
    }

    // -------------------------------------------------------
    // Eurodesk Partnership CRUD
    // -------------------------------------------------------

    /**
     * Create a Eurodesk partnership.
     *
     * Requires permission: `projects.eurodesk.manage`, project access and project support for `eurodesk_projects`. The parent Eurodesk project must belong to the selected project.
     *
     * @authenticated
     * @urlParam id integer required Project ID. Example: 6
     * @urlParam eurodeskProjectId integer required Eurodesk project ID. Example: 9
     * @bodyParam organization_name string required Organization name. Example: Youth NGO
     * @bodyParam country string Optional country. Example: Germany
     * @bodyParam contact_info string Optional contact details.
     * @response 201 {"message":"Ortaklik kaydedildi.","partnership":{"id":3,"organization_name":"Youth NGO"}}
     */
    public function storeEurodeskPartnership(Request $request, int $projectId, int $eurodeskProjectId): JsonResponse
    {
        $project = $this->project($request, $projectId, 'projects.eurodesk.manage');
        $this->ensureProjectSupports($project, 'eurodesk_projects');
        $eurodeskProject = EurodeskProject::query()->where('project_id', $projectId)->findOrFail($eurodeskProjectId);
        $this->assertPeriodWritable($request, $eurodeskProject->period_id);

        $validated = $request->validate([
            'organization_name' => 'required|string|max:255',
            'country'           => 'nullable|string|max:100',
            'contact_info'      => 'nullable|string|max:1000',
        ]);

        $partnership = $eurodeskProject->partnerships()->create($validated);

        return response()->json(['message' => 'Ortaklik kaydedildi.', 'partnership' => $partnership], 201);
    }

    /**
     * Update a Eurodesk partnership.
     *
     * Requires permission: `projects.eurodesk.manage`, project access and project support for `eurodesk_projects`. The partnership must belong to the parent Eurodesk project.
     *
     * @authenticated
     * @urlParam id integer required Project ID. Example: 6
     * @urlParam eurodeskProjectId integer required Eurodesk project ID. Example: 9
     * @urlParam partnershipId integer required Partnership ID. Example: 3
     * @bodyParam organization_name string required Organization name. Example: Youth NGO
     * @bodyParam country string Optional country. Example: Germany
     * @bodyParam contact_info string Optional contact details.
     * @response 200 {"message":"Ortaklik guncellendi.","partnership":{"id":3}}
     */
    public function updateEurodeskPartnership(Request $request, int $projectId, int $eurodeskProjectId, int $partnershipId): JsonResponse
    {
        $project = $this->project($request, $projectId, 'projects.eurodesk.manage');
        $this->ensureProjectSupports($project, 'eurodesk_projects');
        $eurodeskProject = EurodeskProject::query()->where('project_id', $projectId)->findOrFail($eurodeskProjectId);
        $this->assertPeriodWritable($request, $eurodeskProject->period_id);

        $partnership = EurodeskPartnership::query()
            ->where('eurodesk_project_id', $eurodeskProject->id)
            ->findOrFail($partnershipId);

        $partnership->update($request->validate([
            'organization_name' => 'required|string|max:255',
            'country'           => 'nullable|string|max:100',
            'contact_info'      => 'nullable|string|max:1000',
        ]));

        return response()->json(['message' => 'Ortaklik guncellendi.', 'partnership' => $partnership->fresh()]);
    }

    /**
     * Delete a Eurodesk partnership.
     *
     * Requires permission: `projects.eurodesk.manage`, project access and project support for `eurodesk_projects`.
     *
     * @authenticated
     * @urlParam id integer required Project ID. Example: 6
     * @urlParam eurodeskProjectId integer required Eurodesk project ID. Example: 9
     * @urlParam partnershipId integer required Partnership ID. Example: 3
     * @response 200 {"message":"Ortaklik silindi."}
     */
    public function destroyEurodeskPartnership(Request $request, int $projectId, int $eurodeskProjectId, int $partnershipId): JsonResponse
    {
        $project = $this->project($request, $projectId, 'projects.eurodesk.manage');
        $this->ensureProjectSupports($project, 'eurodesk_projects');
        $eurodeskProject = EurodeskProject::query()->where('project_id', $projectId)->findOrFail($eurodeskProjectId);
        $this->assertPeriodWritable($request, $eurodeskProject->period_id);

        EurodeskPartnership::query()
            ->where('eurodesk_project_id', $eurodeskProject->id)
            ->findOrFail($partnershipId)
            ->delete();

        return response()->json(['message' => 'Ortaklik silindi.']);
    }

    private function validateEurodesk(Request $request): array
    {
        return $request->validate([
            'period_id' => 'nullable|integer|exists:periods,id',
            'title' => 'required|string|max:255',
            'partner_organizations' => 'nullable|array',
            'partner_organizations.*' => 'nullable|string|max:255',
            'grant_amount' => 'nullable|numeric|min:0',
            'grant_status' => ['required', Rule::in(['applied', 'approved', 'rejected', 'completed'])],
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);
    }

    private function assertOptionalPeriodBelongsToProject(mixed $periodId, int $projectId): void
    {
        if (empty($periodId)) {
            return;
        }

        abort_unless(
            \App\Models\Period::query()->whereKey((int) $periodId)->where('project_id', $projectId)->exists(),
            422,
            'Secilen donem bu projeye ait degil.'
        );
    }

    private function rewardAwardPayload(RewardAward $award): array
    {
        return [
            'id' => $award->id,
            'participant_id' => $award->participant_id,
            'reward_tier_id' => $award->reward_tier_id,
            'name' => trim(($award->participant?->user?->name ?? '').' '.($award->participant?->user?->surname ?? '')),
            'email' => $award->participant?->user?->email,
            'reward_name' => $award->reward_name,
            'status' => $award->status,
            'awarded_at' => optional($award->awarded_at)?->toIso8601String(),
            'delivered_at' => optional($award->delivered_at)?->toIso8601String(),
            'note' => $award->note,
            'tier' => $award->tier?->only(['id', 'name', 'reward_description']),
            'awarder' => $award->awarder ? trim($award->awarder->name.' '.$award->awarder->surname) : null,
            'deliverer' => $award->deliverer ? trim($award->deliverer->name.' '.$award->deliverer->surname) : null,
        ];
    }
    /**
     * Create a reward tier.
     *
     * Requires permission: `projects.rewards.manage`, project access and project support for `reward_tiers`.
     *
     * @authenticated
     * @urlParam id integer required Project ID. Example: 5
     * @bodyParam name string required Tier name. Example: Altin Kademe
     * @bodyParam description string Optional description.
     * @bodyParam min_badges integer required Minimum badge count. Example: 3
     * @bodyParam min_credits integer required Minimum credit amount. Example: 90
     * @bodyParam reward_description string required Reward description. Example: Hediye kutusu
     * @response 201 {"message":"Hediye kademesi kaydedildi.","reward_tier":{"id":2,"name":"Altin Kademe"}}
     */
    public function storeRewardTier(Request $request, int $projectId): JsonResponse
    {
        $project = $this->project($request, $projectId, 'projects.rewards.manage');
        $this->ensureProjectSupports($project, 'reward_tiers');
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'min_badges' => 'required|integer|min:0',
            'min_credits' => 'required|integer|min:0',
            'reward_description' => 'required|string|max:255',
        ]);

        return response()->json([
            'message' => 'Hediye kademesi kaydedildi.',
            'reward_tier' => RewardTier::create($validated + ['project_id' => $projectId]),
        ], 201);
    }

    /**
     * Update a reward tier.
     *
     * Requires permission: `projects.rewards.manage`, project access and project support for `reward_tiers`. Only project-owned reward tiers are updated through this endpoint.
     *
     * @authenticated
     * @urlParam id integer required Project ID. Example: 5
     * @urlParam item integer required Reward tier ID. Example: 2
     * @bodyParam name string required Tier name. Example: Altin Kademe
     * @bodyParam min_badges integer required Minimum badge count. Example: 3
     * @bodyParam min_credits integer required Minimum credit amount. Example: 90
     * @bodyParam reward_description string required Reward description. Example: Hediye kutusu
     * @response 200 {"message":"Hediye kademesi guncellendi.","reward_tier":{"id":2}}
     */
    public function updateRewardTier(Request $request, int $projectId, int $id): JsonResponse
    {
        $project = $this->project($request, $projectId, 'projects.rewards.manage');
        $this->ensureProjectSupports($project, 'reward_tiers');
        $tier = RewardTier::query()->where('project_id', $projectId)->findOrFail($id);
        $tier->update($request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'min_badges' => 'required|integer|min:0',
            'min_credits' => 'required|integer|min:0',
            'reward_description' => 'required|string|max:255',
        ]));

        return response()->json(['message' => 'Hediye kademesi guncellendi.', 'reward_tier' => $tier->fresh()]);
    }

    /**
     * Delete a reward tier.
     *
     * Requires permission: `projects.rewards.manage`, project access and project support for `reward_tiers`.
     *
     * @authenticated
     * @urlParam id integer required Project ID. Example: 5
     * @urlParam item integer required Reward tier ID. Example: 2
     * @response 200 {"message":"Hediye kademesi silindi."}
     */
    public function destroyRewardTier(Request $request, int $projectId, int $id): JsonResponse
    {
        $project = $this->project($request, $projectId, 'projects.rewards.manage');
        $this->ensureProjectSupports($project, 'reward_tiers');
        RewardTier::query()->where('project_id', $projectId)->findOrFail($id)->delete();

        return response()->json(['message' => 'Hediye kademesi silindi.']);
    }

    /**
     * Create a reward award for a participant.
     *
     * Requires permission: `projects.rewards.manage`, project access and project support for `reward_tiers`. Participant must belong to the project; optional tier must be global or project-owned. Participant period is checked for archive lock.
     *
     * @authenticated
     * @urlParam id integer required Project ID. Example: 5
     * @bodyParam participant_id integer required Participant ID in the project. Example: 42
     * @bodyParam reward_tier_id integer Optional reward tier ID. Example: 2
     * @bodyParam reward_name string required Award name. Example: Hediye Kutusu
     * @bodyParam status string Optional status: `planned`, `given` or `cancelled`. Defaults to given. Example: planned
     * @bodyParam awarded_at date Optional award date. Example: 2026-06-30
     * @bodyParam note string Optional note.
     * @response 201 {"message":"Hediye kaydi olusturuldu.","reward_award":{"id":10,"status":"planned"}}
     * @response 422 {"message":"Secilen hediye kademesi bu proje icin uygun degil."}
     */
    public function storeRewardAward(Request $request, int $projectId): JsonResponse
    {
        $project = $this->project($request, $projectId, 'projects.rewards.manage');
        $this->ensureProjectSupports($project, 'reward_tiers');
        $validated = $request->validate([
            'participant_id' => 'required|exists:participants,id',
            'reward_tier_id' => 'nullable|exists:reward_tiers,id',
            'reward_name' => 'required|string|max:255',
            'status' => ['nullable', Rule::in(['planned', 'given', 'cancelled'])],
            'awarded_at' => 'nullable|date',
            'note' => 'nullable|string|max:2000',
        ]);

        $participant = Participant::query()->where('id', $validated['participant_id'])->where('project_id', $projectId)->first();
        abort_unless($participant, 422, 'Secilen katilimci bu projeye ait degil.');
        $this->assertPeriodWritable($request, $participant->period_id);

        if (! empty($validated['reward_tier_id'])) {
            abort_unless(
                RewardTier::query()
                    ->where('id', $validated['reward_tier_id'])
                    ->where(function ($query) use ($projectId) {
                        $query->where('project_id', $projectId)->orWhereNull('project_id');
                    })
                    ->exists(),
                422,
                'Secilen hediye kademesi bu proje icin uygun degil.'
            );
        }

        $award = RewardAward::query()->create([
            'project_id' => $projectId,
            'participant_id' => $validated['participant_id'],
            'reward_tier_id' => $validated['reward_tier_id'] ?? null,
            'reward_name' => $validated['reward_name'],
            'status' => $validated['status'] ?? 'given',
            'awarded_at' => $validated['awarded_at'] ?? now(),
            'note' => $validated['note'] ?? null,
            'awarded_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Hediye kaydi olusturuldu.',
            'reward_award' => $this->rewardAwardPayload($award->load(['participant.user:id,name,surname,email', 'tier:id,name,reward_description', 'awarder:id,name,surname', 'deliverer:id,name,surname'])),
        ], 201);
    }

    /**
     * Mark a reward award as delivered.
     *
     * Requires permission: `projects.rewards.manage`, project access and project support for `reward_tiers`. Participant period is checked for archive lock.
     *
     * @authenticated
     * @urlParam id integer required Project ID. Example: 5
     * @urlParam item integer required Reward award ID. Example: 10
     * @response 200 {"message":"Hediye teslim edildi olarak isaretlendi.","award":{"id":10,"status":"given"}}
     */
    public function markRewardDelivered(Request $request, int $projectId, int $id): JsonResponse
    {
        $project = $this->project($request, $projectId, 'projects.rewards.manage');
        $this->ensureProjectSupports($project, 'reward_tiers');
        $award = RewardAward::query()
            ->with('participant:id,period_id')
            ->where('project_id', $projectId)
            ->findOrFail($id);
        $this->assertPeriodWritable($request, $award->participant?->period_id);

        $award->markDelivered($request->user()->id);

        return response()->json([
            'message' => 'Hediye teslim edildi olarak isaretlendi.',
            'award' => $this->rewardAwardPayload($award->fresh(['participant.user:id,name,surname,email', 'tier:id,name,reward_description', 'awarder:id,name,surname', 'deliverer:id,name,surname'])),
        ]);
    }

    /**
     * Delete a reward award.
     *
     * Requires permission: `projects.rewards.manage`, project access and project support for `reward_tiers`. Participant period is checked for archive lock.
     *
     * @authenticated
     * @urlParam id integer required Project ID. Example: 5
     * @urlParam item integer required Reward award ID. Example: 10
     * @response 200 {"message":"Hediye kaydi silindi."}
     */
    public function destroyRewardAward(Request $request, int $projectId, int $id): JsonResponse
    {
        $project = $this->project($request, $projectId, 'projects.rewards.manage');
        $this->ensureProjectSupports($project, 'reward_tiers');
        $award = RewardAward::query()
            ->with('participant:id,period_id')
            ->where('project_id', $projectId)
            ->findOrFail($id);
        $this->assertPeriodWritable($request, $award->participant?->period_id);
        $award->delete();

        return response()->json(['message' => 'Hediye kaydi silindi.']);
    }

    private function rewardEligibleParticipants(Project $project, $participants, $rewardTiers): array
    {
        if ($rewardTiers->isEmpty()) {
            return [];
        }

        $userIds = $participants->pluck('user_id')->unique()->values();
        $badgesByUser = User::query()
            ->whereIn('id', $userIds)
            ->with(['badges' => function ($query) use ($project) {
                $query->where(function ($inner) use ($project) {
                    $inner->whereNull('badges.project_id')->orWhere('badges.project_id', $project->id);
                });
            }])
            ->get()
            ->mapWithKeys(fn (User $user) => [$user->id => $user->badges->count()]);

        return $participants
            ->map(function (Participant $participant) use ($badgesByUser, $rewardTiers) {
                $badgeCount = (int) ($badgesByUser[$participant->user_id] ?? 0);
                $credit = (int) $participant->credit;
                $eligibleTiers = $rewardTiers
                    ->filter(fn (RewardTier $tier) => $badgeCount >= (int) $tier->min_badges && $credit >= (int) $tier->min_credits)
                    ->values();

                if ($eligibleTiers->isEmpty()) {
                    return null;
                }

                return [
                    'participant_id' => $participant->id,
                    'user_id' => $participant->user_id,
                    'name' => trim(($participant->user?->name ?? '').' '.($participant->user?->surname ?? '')),
                    'email' => $participant->user?->email,
                    'badge_count' => $badgeCount,
                    'credit' => $credit,
                    'eligible_rewards' => $eligibleTiers->map(fn (RewardTier $tier) => [
                        'id' => $tier->id,
                        'name' => $tier->name,
                        'reward_description' => $tier->reward_description,
                    ])->all(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}

