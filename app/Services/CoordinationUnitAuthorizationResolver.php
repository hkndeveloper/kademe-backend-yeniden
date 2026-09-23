<?php

namespace App\Services;

use App\Models\CoordinationUnitMembership;
use App\Models\CoordinationUnitPermissionRule;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CoordinationUnitAuthorizationResolver
{
    public function resolve(User $user, Collection $overrides): array
    {
        if (! $this->isAvailable()) {
            return $this->emptyResult();
        }

        $memberships = $this->membershipsFor($user);

        return $this->resolveFromMemberships($user, $memberships, $memberships, $overrides);
    }

    public function resolveForMembership(
        User $user,
        ?CoordinationUnitMembership $activeMembership,
        Collection $globalOverrides,
        Collection $membershipOverrides
    ): array {
        if (! $this->isAvailable()) {
            return $this->emptyResult();
        }

        $allMemberships = $this->membershipsFor($user);
        $activeMemberships = $activeMembership === null
            ? collect()
            : $allMemberships->where('id', $activeMembership->id)->values();
        $overrides = $globalOverrides
            ->concat($membershipOverrides)
            ->values();

        return $this->resolveFromMemberships($user, $activeMemberships, $allMemberships, $overrides);
    }

    private function resolveFromMemberships(
        User $user,
        Collection $authorizationMemberships,
        Collection $contextMemberships,
        Collection $overrides
    ): array {

        $scopesByPermission = [];
        $scopesByMembershipAndPermission = [];
        $authorizationMembershipIds = $authorizationMemberships->pluck('id')->map(fn ($id) => (int) $id);
        foreach ($contextMemberships as $membership) {
            $rules = $membership->unit->permissionRules
                ->where('position', $membership->position)
                ->where('effect', CoordinationUnitPermissionRule::EFFECT_ALLOW);

            foreach ($rules as $rule) {
                $scope = $this->scopeForRule($membership, $rule);
                $scopesByMembershipAndPermission[$membership->id][$rule->permission_name][] = $scope;
                if ($authorizationMembershipIds->contains((int) $membership->id)) {
                    $scopesByPermission[$rule->permission_name][] = $scope;
                }
            }
        }

        $scopes = collect($scopesByPermission)
            ->map(fn (array $permissionScopes) => $this->mergeScopes($permissionScopes))
            ->all();

        $effectivePermissions = collect(array_keys($scopes));
        $effectivePermissions = $this->applyAllowOverrides($effectivePermissions, $scopes, $overrides);
        $effectivePermissions = $this->applyDenyOverrides($effectivePermissions, $overrides);
        $scopes = collect($scopes)
            ->only($effectivePermissions->all())
            ->all();

        $projectIdsByPermission = collect($scopes)
            ->map(fn (array $scope) => $this->projectIdsFromScope($scope))
            ->all();

        $manageableProjectIds = collect($projectIdsByPermission)
            ->flatten()
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $primaryMembership = $contextMemberships->firstWhere('is_primary', true) ?? $contextMemberships->first();
        $activeMembership = $authorizationMemberships->first();

        return [
            'effective_permissions' => $effectivePermissions->sort()->values(),
            'scopes' => $scopes,
            'contexts' => [
                'manageable_project_ids' => $manageableProjectIds,
                'project_ids_by_permission' => $projectIdsByPermission,
                'unit_memberships' => $contextMemberships
                    ->map(fn (CoordinationUnitMembership $membership) => $this->membershipContext(
                        $membership,
                        $scopesByMembershipAndPermission[$membership->id] ?? []
                    ))
                    ->values()
                    ->all(),
                'primary_unit_id' => $primaryMembership?->unit_id === null
                    ? null
                    : (int) $primaryMembership->unit_id,
                'active_unit_id' => $activeMembership?->unit_id === null
                    ? null
                    : (int) $activeMembership->unit_id,
                'active_membership_id' => $activeMembership?->id === null
                    ? null
                    : (int) $activeMembership->id,
                'coordinated_unit_ids' => $contextMemberships
                    ->where('position', CoordinationUnitMembership::POSITION_COORDINATOR)
                    ->pluck('unit_id')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all(),
                'staffed_unit_ids' => $contextMemberships
                    ->where('position', CoordinationUnitMembership::POSITION_STAFF)
                    ->pluck('unit_id')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all(),
                'manageable_unit' => $primaryMembership?->unit?->name,
            ],
        ];
    }

    private function membershipsFor(User $user): Collection
    {
        return CoordinationUnitMembership::query()
            ->active()
            ->where('user_id', $user->id)
            ->with([
                'unit.projectResponsibilities' => fn ($query) => $query->active(),
                'unit.permissionRules' => fn ($query) => $query->active(),
                'permissionOverrides' => fn ($query) => $query->active(),
            ])
            ->get()
            ->filter(fn (CoordinationUnitMembership $membership) => $membership->unit?->status === 'active')
            ->values();
    }

    private function scopeForRule(
        CoordinationUnitMembership $membership,
        CoordinationUnitPermissionRule $rule
    ): array {
        $unit = $membership->unit;
        $basePayload = [
            'unit_ids' => [(int) $unit->id],
            'unit_codes' => [$unit->code],
            'units' => [$unit->name],
            'membership_ids' => [(int) $membership->id],
        ];

        return match ($rule->scope_source) {
            CoordinationUnitPermissionRule::SCOPE_LINKED_PROJECT => [
                'scope_type' => 'selected_projects',
                'scope_payload' => array_merge($basePayload, [
                    'project_ids' => $unit->project_id === null ? [] : [(int) $unit->project_id],
                ]),
            ],
            CoordinationUnitPermissionRule::SCOPE_RESPONSIBILITY_PROJECTS => [
                'scope_type' => 'selected_projects',
                'scope_payload' => array_merge($basePayload, [
                    'project_ids' => $unit->projectResponsibilities
                        ->where('service_domain', $rule->service_domain)
                        ->pluck('project_id')
                        ->map(fn ($id) => (int) $id)
                        ->unique()
                        ->values()
                        ->all(),
                    'service_domain' => $rule->service_domain,
                ]),
            ],
            CoordinationUnitPermissionRule::SCOPE_OWN_UNIT => [
                'scope_type' => 'own_unit',
                'scope_payload' => array_merge($basePayload, [
                    'unit' => $unit->name,
                ]),
            ],
            CoordinationUnitPermissionRule::SCOPE_OWN_RECORD => [
                'scope_type' => 'own_record',
                'scope_payload' => array_merge($basePayload, [
                    'project_ids' => $unit->project_id === null ? [] : [(int) $unit->project_id],
                ]),
            ],
            CoordinationUnitPermissionRule::SCOPE_SELF => [
                'scope_type' => 'self',
                'scope_payload' => array_merge($basePayload, ['user_id' => (int) $membership->user_id]),
            ],
            CoordinationUnitPermissionRule::SCOPE_ALL => [
                'scope_type' => 'all',
                'scope_payload' => $basePayload,
            ],
            default => [
                'scope_type' => 'none',
                'scope_payload' => $basePayload,
            ],
        };
    }

    private function mergeScopes(array $scopes): array
    {
        $priority = [
            'all' => 100,
            'selected_projects' => 90,
            'own_unit' => 80,
            'own_record' => 70,
            'self' => 60,
            'none' => 10,
        ];
        $scopeType = collect($scopes)
            ->sortByDesc(fn (array $scope) => $priority[$scope['scope_type'] ?? 'none'] ?? 0)
            ->first()['scope_type'] ?? 'none';
        $sameTypeScopes = collect($scopes)->where('scope_type', $scopeType);
        $payload = [];

        foreach (['project_ids', 'unit_ids', 'unit_codes', 'units', 'membership_ids'] as $listKey) {
            $values = $sameTypeScopes
                ->flatMap(fn (array $scope) => $scope['scope_payload'][$listKey] ?? [])
                ->unique()
                ->values()
                ->all();
            if ($values !== []) {
                $payload[$listKey] = $values;
            }
        }

        $firstPayload = $sameTypeScopes->first()['scope_payload'] ?? [];
        foreach (['unit', 'user_id', 'service_domain'] as $scalarKey) {
            if (array_key_exists($scalarKey, $firstPayload)) {
                $payload[$scalarKey] = $firstPayload[$scalarKey];
            }
        }

        return [
            'scope_type' => $scopeType,
            'scope_payload' => $payload,
        ];
    }

    private function applyAllowOverrides(Collection $permissions, array &$scopes, Collection $overrides): Collection
    {
        foreach ($overrides->where('effect', 'allow') as $override) {
            $permissionName = (string) ($override['permission_name'] ?? '');
            if ($permissionName === '') {
                continue;
            }

            $permissions->push($permissionName);
            $scopes[$permissionName] = [
                'scope_type' => $override['scope_type'] ?? 'none',
                'scope_payload' => (array) ($override['scope_payload'] ?? []),
            ];
        }

        return $permissions->unique()->values();
    }

    private function applyDenyOverrides(Collection $permissions, Collection $overrides): Collection
    {
        $denied = $overrides
            ->where('effect', 'deny')
            ->pluck('permission_name')
            ->filter()
            ->values();

        return $permissions
            ->reject(function (string $permission) use ($denied) {
                return $denied->contains(function (string $deniedPermission) use ($permission) {
                    if ($permission === $deniedPermission) {
                        return true;
                    }

                    return Str::endsWith($deniedPermission, '.*')
                        && Str::startsWith($permission, Str::beforeLast($deniedPermission, '.*').'.');
                });
            })
            ->unique()
            ->values();
    }

    private function projectIdsFromScope(array $scope): array
    {
        if (($scope['scope_type'] ?? 'none') === 'all') {
            return Project::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return collect($scope['scope_payload']['project_ids'] ?? [])
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function isAvailable(): bool
    {
        return Schema::hasTable('coordination_units')
            && Schema::hasTable('coordination_unit_memberships')
            && Schema::hasTable('coordination_unit_permission_rules')
            && Schema::hasTable('coordination_unit_project_responsibilities');
    }

    private function membershipContext(CoordinationUnitMembership $membership, array $scopesByPermission): array
    {
        $mergedScopes = collect($scopesByPermission)
            ->map(fn (array $scopes) => $this->mergeScopes($scopes))
            ->all();
        $projectIdsByPermission = collect($mergedScopes)
            ->map(fn (array $scope) => $this->projectIdsFromScope($scope))
            ->all();

        return [
            'membership_id' => (int) $membership->id,
            'unit_id' => (int) $membership->unit_id,
            'unit_code' => $membership->unit->code,
            'unit_name' => $membership->unit->name,
            'unit_kind' => $membership->unit->kind,
            'position' => $membership->position,
            'is_primary' => (bool) $membership->is_primary,
            'project_id' => $membership->unit->project_id === null
                ? null
                : (int) $membership->unit->project_id,
            'permissions' => collect(array_keys($mergedScopes))->sort()->values()->all(),
            'project_ids_by_permission' => $projectIdsByPermission,
            'manageable_project_ids' => collect($projectIdsByPermission)
                ->flatten()
                ->filter(fn ($id) => is_numeric($id))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->sort()
                ->values()
                ->all(),
        ];
    }

    private function emptyResult(): array
    {
        return [
            'effective_permissions' => collect(),
            'scopes' => [],
            'contexts' => [
                'manageable_project_ids' => [],
                'project_ids_by_permission' => [],
                'unit_memberships' => [],
                'primary_unit_id' => null,
                'active_unit_id' => null,
                'active_membership_id' => null,
                'coordinated_unit_ids' => [],
                'staffed_unit_ids' => [],
                'manageable_unit' => null,
            ],
        ];
    }
}
