<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitMembership;
use App\Models\CoordinationUnitMembershipPermissionOverride;
use App\Models\CoordinationUnitPermissionRule;
use App\Models\CoordinationUnitProjectResponsibility;
use App\Models\Project;
use App\Models\User;
use App\Services\CoordinationAuthorizationPreviewService;
use App\Services\PermissionResolver;
use App\Support\CoordinationUnitCatalog;
use App\Support\CoordinationUnitExclusivePermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * @group Coordination Units
 */
class CoordinationUnitController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver,
        private readonly CoordinationAuthorizationPreviewService $previewService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->abortUnlessGloballyAllowed($request, 'coordination_units.view');
        $canViewPermissionRules = $this->permissionResolver->hasGlobalScope(
            $request->user(),
            'coordination_units.permissions.view'
        );

        $units = CoordinationUnit::query()
            ->with($this->unitRelations($canViewPermissionRules))
            ->orderBy('kind')
            ->orderBy('name')
            ->get()
            ->map(fn (CoordinationUnit $unit) => $this->unitPayload($unit, $canViewPermissionRules))
            ->values();

        return response()->json([
            'units' => $units,
            'options' => $this->options($canViewPermissionRules),
            'authorization_mode' => config('coordination_authorization.mode', 'legacy'),
        ]);
    }

    public function show(Request $request, CoordinationUnit $coordinationUnit): JsonResponse
    {
        $this->abortUnlessGloballyAllowed($request, 'coordination_units.view');
        $canViewPermissionRules = $this->permissionResolver->hasGlobalScope(
            $request->user(),
            'coordination_units.permissions.view'
        );
        $coordinationUnit->load($this->unitRelations($canViewPermissionRules));

        return response()->json(['unit' => $this->unitPayload($coordinationUnit, $canViewPermissionRules)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->abortUnlessGloballyAllowed($request, 'coordination_units.manage');
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/', Rule::unique('coordination_units', 'code')],
            'name' => ['required', 'string', 'max:180'],
            'kind' => ['required', Rule::in([CoordinationUnit::KIND_PROJECT, CoordinationUnit::KIND_SERVICE])],
            'project_id' => [
                'nullable',
                'integer',
                'exists:projects,id',
                Rule::unique('coordination_units', 'project_id'),
            ],
            'description' => ['nullable', 'string', 'max:3000'],
        ]);

        abort_if(
            $validated['kind'] === CoordinationUnit::KIND_PROJECT && empty($validated['project_id']),
            422,
            'Proje koordinatörlüğü için proje zorunludur.'
        );
        abort_if(
            $validated['kind'] === CoordinationUnit::KIND_SERVICE && ! empty($validated['project_id']),
            422,
            'Hizmet koordinatörlüğü doğrudan bir projeye bağlanamaz.'
        );

        $unit = CoordinationUnit::query()->create([
            ...$validated,
            'project_id' => $validated['project_id'] ?? null,
            'status' => CoordinationUnit::STATUS_ACTIVE,
        ]);
        $this->log($request, $unit, 'coordination_unit.created', ['unit' => $unit->only(['code', 'name', 'kind', 'project_id'])]);

        return response()->json(['unit' => $this->unitPayload($unit->load($this->unitRelations()))], 201);
    }

    public function update(Request $request, CoordinationUnit $coordinationUnit): JsonResponse
    {
        $this->abortUnlessGloballyAllowed($request, 'coordination_units.manage');
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:180'],
            'status' => ['sometimes', Rule::in([CoordinationUnit::STATUS_ACTIVE, CoordinationUnit::STATUS_PASSIVE])],
            'description' => ['nullable', 'string', 'max:3000'],
        ]);

        $before = $coordinationUnit->only(['name', 'status', 'description']);
        $coordinationUnit->update($validated);
        $this->log($request, $coordinationUnit, 'coordination_unit.updated', [
            'before' => $before,
            'after' => $coordinationUnit->only(['name', 'status', 'description']),
        ]);

        return response()->json(['unit' => $this->unitPayload($coordinationUnit->load($this->unitRelations()))]);
    }

    public function upsertMembership(Request $request, CoordinationUnit $coordinationUnit): JsonResponse
    {
        $this->abortUnlessGloballyAllowed($request, 'coordination_units.memberships.manage');
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'position' => ['required', Rule::in([
                CoordinationUnitMembership::POSITION_COORDINATOR,
                CoordinationUnitMembership::POSITION_STAFF,
            ])],
            'is_primary' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);
        $user = User::query()->findOrFail($validated['user_id']);
        abort_if(in_array($user->role, ['student', 'alumni'], true), 422, 'Öğrenci veya mezun bir koordinasyon birimine atanamaz.');
        abort_if($coordinationUnit->status !== CoordinationUnit::STATUS_ACTIVE, 422, 'Pasif birime üye atanamaz.');

        $actorId = (int) $request->user()->id;
        $membership = DB::transaction(function () use ($actorId, $coordinationUnit, $user, $validated) {
            $existing = CoordinationUnitMembership::query()
                ->where('unit_id', $coordinationUnit->id)
                ->where('user_id', $user->id)
                ->where('status', CoordinationUnitMembership::STATUS_ACTIVE)
                ->first();
            $hasPrimary = CoordinationUnitMembership::query()
                ->where('user_id', $user->id)
                ->where('status', CoordinationUnitMembership::STATUS_ACTIVE)
                ->where('is_primary', true)
                ->when($existing, fn ($query) => $query->where('id', '!=', $existing->id))
                ->exists();
            $isPrimary = array_key_exists('is_primary', $validated)
                ? (bool) $validated['is_primary']
                : ! $hasPrimary;

            if ($isPrimary) {
                CoordinationUnitMembership::query()
                    ->where('user_id', $user->id)
                    ->where('status', CoordinationUnitMembership::STATUS_ACTIVE)
                    ->when($existing, fn ($query) => $query->where('id', '!=', $existing->id))
                    ->update(['is_primary' => false]);
            }

            $payload = [
                'position' => $validated['position'],
                'is_primary' => $isPrimary,
                'status' => CoordinationUnitMembership::STATUS_ACTIVE,
                'starts_at' => $validated['starts_at'] ?? null,
                'ends_at' => $validated['ends_at'] ?? null,
                'assigned_by' => $actorId,
            ];

            if ($existing) {
                $existing->update($payload);

                return $existing->refresh();
            }

            return CoordinationUnitMembership::query()->create([
                'unit_id' => $coordinationUnit->id,
                'user_id' => $user->id,
                ...$payload,
            ]);
        });

        $this->log($request, $membership, 'coordination_unit.membership_upserted', [
            'unit_id' => (int) $coordinationUnit->id,
            'user_id' => (int) $user->id,
            'position' => $membership->position,
            'is_primary' => (bool) $membership->is_primary,
        ]);

        return response()->json(['membership' => $this->membershipPayload($membership->load('user'))]);
    }

    public function deactivateMembership(Request $request, CoordinationUnitMembership $membership): JsonResponse
    {
        $this->abortUnlessGloballyAllowed($request, 'coordination_units.memberships.manage');

        DB::transaction(function () use ($membership) {
            $wasPrimary = (bool) $membership->is_primary;
            $membership->update([
                'status' => CoordinationUnitMembership::STATUS_PASSIVE,
                'is_primary' => false,
                'ends_at' => $membership->ends_at ?? now(),
            ]);
            CoordinationUnitMembershipPermissionOverride::query()
                ->where('membership_id', $membership->id)
                ->active()
                ->update(['status' => CoordinationUnitMembershipPermissionOverride::STATUS_PASSIVE]);

            if ($wasPrimary) {
                CoordinationUnitMembership::query()
                    ->active()
                    ->where('user_id', $membership->user_id)
                    ->orderBy('starts_at')
                    ->orderBy('id')
                    ->first()?->update(['is_primary' => true]);
            }
        });
        $this->log($request, $membership, 'coordination_unit.membership_deactivated', [
            'unit_id' => (int) $membership->unit_id,
            'user_id' => (int) $membership->user_id,
        ]);

        return response()->json(['message' => 'Birim üyeliği pasifleştirildi.']);
    }

    public function upsertResponsibility(Request $request, CoordinationUnit $coordinationUnit): JsonResponse
    {
        $this->abortUnlessGloballyAllowed($request, 'coordination_units.responsibilities.manage');
        abort_unless($coordinationUnit->kind === CoordinationUnit::KIND_SERVICE, 422, 'Proje sorumluluğu yalnız hizmet birimine atanabilir.');
        $validated = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'service_domain' => ['required', Rule::in(array_keys(CoordinationUnitCatalog::serviceDomains()))],
            'is_primary' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);

        $responsibility = DB::transaction(function () use ($coordinationUnit, $validated, $request) {
            $isPrimary = (bool) ($validated['is_primary'] ?? true);
            if ($isPrimary) {
                CoordinationUnitProjectResponsibility::query()
                    ->where('project_id', $validated['project_id'])
                    ->where('service_domain', $validated['service_domain'])
                    ->where('status', CoordinationUnitProjectResponsibility::STATUS_ACTIVE)
                    ->update(['is_primary' => false]);
            }

            $existing = CoordinationUnitProjectResponsibility::query()
                ->where('unit_id', $coordinationUnit->id)
                ->where('project_id', $validated['project_id'])
                ->where('service_domain', $validated['service_domain'])
                ->where('status', CoordinationUnitProjectResponsibility::STATUS_ACTIVE)
                ->first();
            $payload = [
                'is_primary' => $isPrimary,
                'status' => CoordinationUnitProjectResponsibility::STATUS_ACTIVE,
                'starts_at' => $validated['starts_at'] ?? null,
                'ends_at' => $validated['ends_at'] ?? null,
                'assigned_by' => $request->user()->id,
            ];

            if ($existing) {
                $existing->update($payload);

                return $existing->refresh();
            }

            return CoordinationUnitProjectResponsibility::query()->create([
                'unit_id' => $coordinationUnit->id,
                'project_id' => $validated['project_id'],
                'service_domain' => $validated['service_domain'],
                ...$payload,
            ]);
        });

        $this->log($request, $responsibility, 'coordination_unit.responsibility_upserted', [
            'unit_id' => (int) $coordinationUnit->id,
            'project_id' => (int) $responsibility->project_id,
            'service_domain' => $responsibility->service_domain,
        ]);

        return response()->json(['responsibility' => $this->responsibilityPayload($responsibility->load('project'))]);
    }

    public function deactivateResponsibility(
        Request $request,
        CoordinationUnitProjectResponsibility $responsibility
    ): JsonResponse {
        $this->abortUnlessGloballyAllowed($request, 'coordination_units.responsibilities.manage');

        DB::transaction(function () use ($responsibility) {
            $wasPrimary = (bool) $responsibility->is_primary;
            $responsibility->update([
                'status' => 'passive',
                'is_primary' => false,
                'ends_at' => $responsibility->ends_at ?? now(),
            ]);

            if ($wasPrimary) {
                CoordinationUnitProjectResponsibility::query()
                    ->active()
                    ->where('project_id', $responsibility->project_id)
                    ->where('service_domain', $responsibility->service_domain)
                    ->orderBy('id')
                    ->first()?->update(['is_primary' => true]);
            }
        });
        $this->log($request, $responsibility, 'coordination_unit.responsibility_deactivated', [
            'unit_id' => (int) $responsibility->unit_id,
            'project_id' => (int) $responsibility->project_id,
            'service_domain' => $responsibility->service_domain,
        ]);

        return response()->json(['message' => 'Proje sorumluluğu pasifleştirildi.']);
    }

    public function upsertPermissionRule(Request $request, CoordinationUnit $coordinationUnit): JsonResponse
    {
        $this->abortUnlessGloballyAllowed($request, 'coordination_units.permissions.manage');
        $validated = $request->validate([
            'position' => ['required', Rule::in([
                CoordinationUnitMembership::POSITION_COORDINATOR,
                CoordinationUnitMembership::POSITION_STAFF,
            ])],
            'permission_name' => ['required', Rule::in($this->granularPermissionNames())],
            'scope_source' => ['required', Rule::in($this->scopeSources())],
            'service_domain' => ['nullable', Rule::in(array_keys(CoordinationUnitCatalog::serviceDomains()))],
            'scope_payload' => ['nullable', 'array'],
        ]);
        abort_unless(
            CoordinationUnitExclusivePermissionCatalog::isOwnedBy($validated['permission_name'], $coordinationUnit->code),
            422,
            'Bu işlem yetkisi seçilen koordinatörlüğe atanamaz.'
        );
        abort_if(
            $validated['scope_source'] === CoordinationUnitPermissionRule::SCOPE_LINKED_PROJECT
                && $coordinationUnit->kind !== CoordinationUnit::KIND_PROJECT,
            422,
            'linked_project kapsamı yalnız proje koordinatörlüğünde kullanılabilir.'
        );
        abort_if(
            $validated['scope_source'] === CoordinationUnitPermissionRule::SCOPE_RESPONSIBILITY_PROJECTS
                && ($coordinationUnit->kind !== CoordinationUnit::KIND_SERVICE || empty($validated['service_domain'])),
            422,
            'responsibility_projects kapsamı için hizmet birimi ve servis alanı zorunludur.'
        );

        $rule = CoordinationUnitPermissionRule::query()
            ->where('unit_id', $coordinationUnit->id)
            ->where('position', $validated['position'])
            ->where('permission_name', $validated['permission_name'])
            ->where('status', CoordinationUnitPermissionRule::STATUS_ACTIVE)
            ->first();
        $payload = [
            'effect' => CoordinationUnitPermissionRule::EFFECT_ALLOW,
            'scope_source' => $validated['scope_source'],
            'service_domain' => $validated['scope_source'] === CoordinationUnitPermissionRule::SCOPE_RESPONSIBILITY_PROJECTS
                ? $validated['service_domain']
                : null,
            'scope_payload' => $validated['scope_payload'] ?? [],
            'status' => CoordinationUnitPermissionRule::STATUS_ACTIVE,
            'created_by' => $request->user()->id,
        ];

        if ($rule) {
            $rule->update($payload);
            $rule->refresh();
        } else {
            $rule = CoordinationUnitPermissionRule::query()->create([
                'unit_id' => $coordinationUnit->id,
                'position' => $validated['position'],
                'permission_name' => $validated['permission_name'],
                ...$payload,
            ]);
        }
        $this->log($request, $rule, 'coordination_unit.permission_rule_upserted', [
            'unit_id' => (int) $coordinationUnit->id,
            'position' => $rule->position,
            'permission_name' => $rule->permission_name,
            'scope_source' => $rule->scope_source,
        ]);

        return response()->json(['permission_rule' => $this->permissionRulePayload($rule)]);
    }

    public function deactivatePermissionRule(Request $request, CoordinationUnitPermissionRule $permissionRule): JsonResponse
    {
        $this->abortUnlessGloballyAllowed($request, 'coordination_units.permissions.manage');
        $permissionRule->update([
            'status' => 'passive',
            'ends_at' => $permissionRule->ends_at ?? now(),
        ]);
        $this->log($request, $permissionRule, 'coordination_unit.permission_rule_deactivated', [
            'unit_id' => (int) $permissionRule->unit_id,
            'position' => $permissionRule->position,
            'permission_name' => $permissionRule->permission_name,
        ]);

        return response()->json(['message' => 'Birim permission kuralı pasifleştirildi.']);
    }

    public function authorizationPreview(Request $request): JsonResponse
    {
        $this->abortUnlessGloballyAllowed($request, 'coordination_units.authorization.preview');
        $validated = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);

        return response()->json($this->previewService->preview(User::query()->findOrFail($validated['user_id'])));
    }

    private function unitRelations(bool $includePermissionRules = true): array
    {
        $relations = [
            'project:id,name,slug,status',
            'memberships' => fn ($query) => $query->with('user:id,name,surname,email,role,status')->orderByDesc('status')->orderBy('id'),
            'projectResponsibilities' => fn ($query) => $query->with('project:id,name,slug,status')->orderByDesc('status')->orderBy('service_domain'),
        ];

        if ($includePermissionRules) {
            $relations['permissionRules'] = fn ($query) => $query->orderByDesc('status')->orderBy('position')->orderBy('permission_name');
        }

        return $relations;
    }

    private function unitPayload(CoordinationUnit $unit, bool $includePermissionRules = true): array
    {
        $payload = [
            'id' => (int) $unit->id,
            'code' => $unit->code,
            'name' => $unit->name,
            'kind' => $unit->kind,
            'status' => $unit->status,
            'description' => $unit->description,
            'project' => $unit->project ? [
                'id' => (int) $unit->project->id,
                'name' => $unit->project->name,
                'slug' => $unit->project->slug,
                'status' => $unit->project->status,
            ] : null,
            'memberships' => $unit->memberships->map(fn ($item) => $this->membershipPayload($item))->values(),
            'responsibilities' => $unit->projectResponsibilities->map(fn ($item) => $this->responsibilityPayload($item))->values(),
        ];

        if ($includePermissionRules) {
            $payload['permission_rules'] = $unit->permissionRules
                ->map(fn ($item) => $this->permissionRulePayload($item))
                ->values();
        }

        return $payload;
    }

    private function membershipPayload(CoordinationUnitMembership $membership): array
    {
        return [
            'id' => (int) $membership->id,
            'unit_id' => (int) $membership->unit_id,
            'position' => $membership->position,
            'is_primary' => (bool) $membership->is_primary,
            'status' => $membership->status,
            'starts_at' => $membership->starts_at?->toISOString(),
            'ends_at' => $membership->ends_at?->toISOString(),
            'user' => $membership->user ? [
                'id' => (int) $membership->user->id,
                'name' => trim($membership->user->name.' '.$membership->user->surname),
                'email' => $membership->user->email,
                'role' => $membership->user->role,
                'status' => $membership->user->status,
            ] : null,
        ];
    }

    private function responsibilityPayload(CoordinationUnitProjectResponsibility $responsibility): array
    {
        return [
            'id' => (int) $responsibility->id,
            'unit_id' => (int) $responsibility->unit_id,
            'project_id' => (int) $responsibility->project_id,
            'service_domain' => $responsibility->service_domain,
            'is_primary' => (bool) $responsibility->is_primary,
            'status' => $responsibility->status,
            'starts_at' => $responsibility->starts_at?->toISOString(),
            'ends_at' => $responsibility->ends_at?->toISOString(),
            'project' => $responsibility->project ? [
                'id' => (int) $responsibility->project->id,
                'name' => $responsibility->project->name,
                'slug' => $responsibility->project->slug,
                'status' => $responsibility->project->status,
            ] : null,
        ];
    }

    private function permissionRulePayload(CoordinationUnitPermissionRule $rule): array
    {
        return [
            'id' => (int) $rule->id,
            'unit_id' => (int) $rule->unit_id,
            'position' => $rule->position,
            'permission_name' => $rule->permission_name,
            'effect' => $rule->effect,
            'scope_source' => $rule->scope_source,
            'service_domain' => $rule->service_domain,
            'scope_payload' => $rule->scope_payload ?? [],
            'status' => $rule->status,
        ];
    }

    private function options(bool $includePermissionRules = true): array
    {
        $options = [
            'projects' => Project::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'slug']),
            'users' => User::query()
                ->whereNotIn('role', ['student', 'alumni'])
                ->where('status', 'active')
                ->orderBy('name')
                ->orderBy('surname')
                ->get(['id', 'name', 'surname', 'email', 'role']),
            'service_domains' => CoordinationUnitCatalog::serviceDomains(),
            'positions' => [
                CoordinationUnitMembership::POSITION_COORDINATOR,
                CoordinationUnitMembership::POSITION_STAFF,
            ],
        ];

        if ($includePermissionRules) {
            $options['permission_groups'] = config('permission_catalog.granular_permissions', []);
            $options['scope_sources'] = $this->scopeSources();
        }

        return $options;
    }

    private function granularPermissionNames(): array
    {
        return collect(config('permission_catalog.granular_permissions', []))
            ->flatten()
            ->map(fn ($permission) => (string) $permission)
            ->unique()
            ->values()
            ->all();
    }

    private function scopeSources(): array
    {
        return [
            CoordinationUnitPermissionRule::SCOPE_LINKED_PROJECT,
            CoordinationUnitPermissionRule::SCOPE_RESPONSIBILITY_PROJECTS,
            CoordinationUnitPermissionRule::SCOPE_OWN_UNIT,
            CoordinationUnitPermissionRule::SCOPE_OWN_RECORD,
            CoordinationUnitPermissionRule::SCOPE_SELF,
            CoordinationUnitPermissionRule::SCOPE_ALL,
        ];
    }

    private function log(Request $request, $subject, string $description, array $properties): void
    {
        activity('coordination_units')
            ->causedBy($request->user())
            ->performedOn($subject)
            ->withProperties($properties)
            ->log($description);
    }
}
