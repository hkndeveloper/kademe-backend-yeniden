<?php

namespace App\Services;

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitPermissionRule;
use App\Models\CoordinationUnitProjectResponsibility;
use App\Models\Project;
use App\Support\AuthorizationManagementCatalog;
use App\Support\CoordinationUnitCatalog;
use App\Support\CoordinationUnitExclusivePermissionCatalog;
use App\Support\CoordinationUnitPermissionTemplateCatalog;
use App\Support\ProjectUnitPermissionTemplateCatalog;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class CoordinationAuthorizationInvariantService
{
    /**
     * Read-only security consistency audit. Admin customizations do not have to
     * equal the code template; only unsafe ownership and referential states fail.
     */
    public function inspect(): array
    {
        $projects = Project::query()
            ->where('status', 'active')
            ->orderBy('id')
            ->get(['id', 'name']);
        $units = CoordinationUnit::query()
            ->withTrashed()
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'kind', 'project_id', 'status', 'deleted_at']);
        $activeUnits = $units
            ->filter(fn (CoordinationUnit $unit) => ! $unit->trashed() && $unit->status === CoordinationUnit::STATUS_ACTIVE)
            ->values();
        $activeUnitIds = $activeUnits->pluck('id')->map(fn ($id) => (int) $id);
        $responsibilities = CoordinationUnitProjectResponsibility::query()
            ->active()
            ->with(['unit:id,code,name,kind,project_id,status,deleted_at', 'project:id,name,status'])
            ->get();
        $rules = CoordinationUnitPermissionRule::query()
            ->active()
            ->with('unit:id,code,name,kind,project_id,status,deleted_at')
            ->get();

        $blockers = [];
        $warnings = [];
        $expectedResponsibilities = [];
        $ownerByDomain = $this->ownerByDomain();

        foreach ($projects as $project) {
            foreach ($ownerByDomain as $domain => $expectedUnitCode) {
                $matching = $responsibilities
                    ->where('project_id', $project->id)
                    ->where('service_domain', $domain)
                    ->values();
                $expectedResponsibilities[] = [
                    'project_id' => (int) $project->id,
                    'project_name' => $project->name,
                    'service_domain' => $domain,
                    'expected_unit_code' => $expectedUnitCode,
                ];

                $valid = $matching->filter(fn (CoordinationUnitProjectResponsibility $row) => $row->is_primary
                    && $row->unit?->code === $expectedUnitCode
                    && ! $row->unit?->trashed()
                    && $row->unit?->status === CoordinationUnit::STATUS_ACTIVE
                );

                if ($matching->count() !== 1 || $valid->count() !== 1) {
                    $blockers[] = [
                        'code' => 'service_responsibility_not_unique_expected_owner',
                        'project_id' => (int) $project->id,
                        'project_name' => $project->name,
                        'service_domain' => $domain,
                        'expected_unit_code' => $expectedUnitCode,
                        'active_rows' => $matching->map(fn (CoordinationUnitProjectResponsibility $row) => [
                            'id' => (int) $row->id,
                            'unit_id' => (int) $row->unit_id,
                            'unit_code' => $row->unit?->code,
                            'is_primary' => (bool) $row->is_primary,
                        ])->all(),
                    ];
                }
            }
        }

        foreach ($responsibilities as $row) {
            if (! $row->unit || $row->unit->trashed() || $row->unit->status !== CoordinationUnit::STATUS_ACTIVE
                || ! $row->project || $row->project->status !== 'active') {
                $blockers[] = [
                    'code' => 'active_responsibility_has_inactive_parent',
                    'responsibility_id' => (int) $row->id,
                    'unit_id' => (int) $row->unit_id,
                    'project_id' => (int) $row->project_id,
                ];
            }

            if (! array_key_exists($row->service_domain, $ownerByDomain)) {
                $blockers[] = [
                    'code' => 'unknown_service_domain',
                    'responsibility_id' => (int) $row->id,
                    'service_domain' => $row->service_domain,
                ];
            }
        }

        $knownPermissions = Permission::query()
            ->where('guard_name', 'web')
            ->pluck('name')
            ->flip();
        $familyUniverse = ProjectUnitPermissionTemplateCatalog::permissionUniverse();
        $allowedPositions = ['coordinator', 'staff'];
        $allowedScopes = [
            CoordinationUnitPermissionRule::SCOPE_LINKED_PROJECT,
            CoordinationUnitPermissionRule::SCOPE_RESPONSIBILITY_PROJECTS,
            CoordinationUnitPermissionRule::SCOPE_OWN_UNIT,
            CoordinationUnitPermissionRule::SCOPE_OWN_RECORD,
            CoordinationUnitPermissionRule::SCOPE_SELF,
            CoordinationUnitPermissionRule::SCOPE_ALL,
            CoordinationUnitPermissionRule::SCOPE_NONE,
        ];

        foreach ($rules as $rule) {
            $ruleContext = [
                'rule_id' => (int) $rule->id,
                'unit_id' => (int) $rule->unit_id,
                'unit_code' => $rule->unit?->code,
                'position' => $rule->position,
                'permission_name' => $rule->permission_name,
            ];

            if (! $rule->unit || ! $activeUnitIds->contains((int) $rule->unit_id)) {
                $blockers[] = ['code' => 'active_rule_has_inactive_unit', ...$ruleContext];
            }
            if (! in_array($rule->position, $allowedPositions, true)) {
                $blockers[] = ['code' => 'invalid_rule_position', ...$ruleContext];
            }
            if (! $knownPermissions->has($rule->permission_name)) {
                $blockers[] = ['code' => 'unknown_rule_permission', ...$ruleContext];
            }
            if (! in_array($rule->scope_source, $allowedScopes, true)) {
                $blockers[] = ['code' => 'invalid_rule_scope', 'scope_source' => $rule->scope_source, ...$ruleContext];
            }
            if ($rule->unit && ! CoordinationUnitExclusivePermissionCatalog::isOwnedBy($rule->permission_name, $rule->unit->code)) {
                $blockers[] = [
                    'code' => 'exclusive_permission_wrong_owner',
                    'expected_unit_code' => CoordinationUnitExclusivePermissionCatalog::ownerCodeFor($rule->permission_name),
                    ...$ruleContext,
                ];
            }

            if ($rule->unit?->kind === CoordinationUnit::KIND_PROJECT
                && in_array($rule->permission_name, $familyUniverse, true)) {
                $expectedKeys = collect(CoordinationUnitPermissionTemplateCatalog::rulesFor($rule->unit))
                    ->map(fn (array $expected) => $expected['position'].'|'.$expected['permission_name']);
                if (! $expectedKeys->contains($rule->position.'|'.$rule->permission_name)) {
                    $blockers[] = ['code' => 'project_family_permission_not_applicable', ...$ruleContext];
                }
            }

            if ($rule->scope_source === CoordinationUnitPermissionRule::SCOPE_LINKED_PROJECT
                && ($rule->unit?->kind !== CoordinationUnit::KIND_PROJECT || $rule->unit?->project_id === null)) {
                $blockers[] = ['code' => 'linked_project_scope_without_project_unit', ...$ruleContext];
            }

            if ($rule->scope_source === CoordinationUnitPermissionRule::SCOPE_RESPONSIBILITY_PROJECTS) {
                $ownedDomains = $rule->unit ? $this->domainsForUnitCode($rule->unit->code) : [];
                if (! $rule->service_domain || ! in_array($rule->service_domain, $ownedDomains, true)) {
                    $blockers[] = [
                        'code' => 'responsibility_scope_domain_not_owned',
                        'service_domain' => $rule->service_domain,
                        'owned_domains' => $ownedDomains,
                        ...$ruleContext,
                    ];
                }
            }
        }

        foreach ($activeUnits as $unit) {
            foreach ($allowedPositions as $position) {
                if ($rules->where('unit_id', $unit->id)->where('position', $position)->isEmpty()) {
                    $warnings[] = [
                        'code' => 'unit_position_has_no_active_rules',
                        'unit_id' => (int) $unit->id,
                        'unit_code' => $unit->code,
                        'position' => $position,
                    ];
                }
            }
        }

        $legacyRolePermissions = collect(AuthorizationManagementCatalog::protectedAuthorityRoles())
            ->mapWithKeys(function (string $roleName) {
                $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
                $names = $role
                    ? $role->permissions->pluck('name')
                        ->filter(fn (string $permission) => AuthorizationManagementCatalog::isUnitBusinessPermission($permission))
                        ->sort()
                        ->values()
                        ->all()
                    : [];

                return [$roleName => [
                    'classification' => 'legacy_only_in_enforce',
                    'count' => count($names),
                    'permission_names' => $names,
                ]];
            });

        return [
            'meta' => [
                'audit_mode' => 'read_only',
                'policy' => 'security-invariants-not-template-equality',
                'admin_customizations_are_preserved' => true,
            ],
            'summary' => [
                'ready' => $blockers === [],
                'active_project_count' => $projects->count(),
                'active_unit_count' => $activeUnits->count(),
                'expected_service_responsibility_count' => count($expectedResponsibilities),
                'active_service_responsibility_count' => $responsibilities->count(),
                'active_permission_rule_count' => $rules->count(),
                'historical_permission_rule_count' => CoordinationUnitPermissionRule::withTrashed()->count(),
                'blocker_count' => count($blockers),
                'warning_count' => count($warnings),
            ],
            'legacy_global_role_permissions' => $legacyRolePermissions->all(),
            'expected_service_responsibilities' => $expectedResponsibilities,
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    /** @return array<string, string> */
    private function ownerByDomain(): array
    {
        $owners = [];
        foreach (CoordinationUnitCatalog::serviceUnits() as $unitCode => $definition) {
            foreach ($definition['domains'] as $domain) {
                $owners[$domain] = $unitCode;
            }
        }

        return $owners;
    }

    /** @return list<string> */
    private function domainsForUnitCode(string $unitCode): array
    {
        return CoordinationUnitCatalog::serviceUnits()[$unitCode]['domains'] ?? [];
    }
}
