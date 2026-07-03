<?php

namespace App\Support;

use App\Models\Project;
use App\Models\User;
use App\Services\PermissionResolver;
use Illuminate\Support\Collection;

class PanelModuleCatalog
{
    public function __construct(private readonly PermissionResolver $permissionResolver)
    {
    }

    public function visibleFor(User $user): array
    {
        $authorization = $this->permissionResolver->resolve($user);
        $effectivePermissions = collect($authorization['effective_permissions'] ?? [])
            ->map(fn ($permission) => (string) $permission)
            ->values();
        $scopes = $authorization['scopes'] ?? [];
        $contexts = $authorization['contexts'] ?? [];

        $modules = collect(config('panel_modules.modules', []))
            ->filter(fn (array $module) => $this->isVisible($module, $effectivePermissions, $scopes, $contexts, $user))
            ->map(fn (array $module) => $this->shapeModule($module, $effectivePermissions, $scopes, $contexts))
            ->sortBy([
                ['panel_type', 'asc'],
                ['order', 'asc'],
                ['id', 'asc'],
            ])
            ->values();

        return [
            'modules' => $modules->all(),
            'sections' => $this->sections($modules),
            'authorization_context' => [
                'manageable_project_ids' => $contexts['manageable_project_ids'] ?? [],
                'project_ids_by_special_module' => $contexts['project_ids_by_special_module'] ?? [],
                'user_special_modules' => $contexts['user_special_modules'] ?? [],
                'manageable_unit' => $contexts['manageable_unit'] ?? null,
            ],
        ];
    }

    private function isVisible(array $module, Collection $effectivePermissions, array $scopes, array $contexts, User $user): bool
    {
        $panelType = $module['panel_type'] ?? 'authority';
        if ($panelType === 'participant' && ! in_array($user->role, ['student', 'alumni'], true)) {
            return false;
        }

        $viewPermissions = collect($module['view_permissions'] ?? [])
            ->map(fn ($permission) => (string) $permission)
            ->filter()
            ->values();

        if (($module['panel_type'] ?? 'authority') === 'authority'
            && ($module['always_visible'] ?? false)
            && ! $this->hasAnyAuthorityPermission($effectivePermissions, $scopes)
        ) {
            return false;
        }

        if ($viewPermissions->isEmpty()) {
            return (bool) ($module['always_visible'] ?? false);
        }

        $usableViewPermissions = $viewPermissions
            ->filter(fn (string $permission) => $effectivePermissions->contains($permission)
                && $this->scopeIsUsable($scopes[$permission] ?? null))
            ->values();

        if ($usableViewPermissions->isEmpty()) {
            return false;
        }

        return $this->projectFamilyIsVisible($module, $usableViewPermissions, $scopes, $contexts);
    }

    private function shapeModule(array $module, Collection $effectivePermissions, array $scopes, array $contexts): array
    {
        $actions = collect($module['actions'] ?? [])
            ->map(fn ($permission) => (string) $permission)
            ->filter()
            ->unique()
            ->values();

        $enabledActions = $actions
            ->filter(fn (string $permission) => $effectivePermissions->contains($permission)
                && $this->scopeIsUsable($scopes[$permission] ?? null))
            ->values();

        $shaped = [
            'id' => $module['id'],
            'panel_type' => $module['panel_type'] ?? 'authority',
            'label' => $module['label'] ?? $module['id'],
            'section' => $module['section'] ?? 'general',
            'href' => $module['href'] ?? null,
            'icon' => $module['icon'] ?? null,
            'order' => (int) ($module['order'] ?? 999),
            'view_permissions' => array_values($module['view_permissions'] ?? []),
            'actions' => $actions->all(),
            'enabled_actions' => $enabledActions->all(),
            'scopes' => $this->scopesFor($enabledActions, $scopes),
        ];

        if (isset($module['family_key'])) {
            $shaped['family_key'] = $module['family_key'];
        }
        if (! empty($module['required_project_types'] ?? [])) {
            $shaped['required_project_types'] = array_values($module['required_project_types']);
        }
        if (! empty($module['required_special_modules'] ?? [])) {
            $shaped['required_special_modules'] = array_values($module['required_special_modules']);
        }

        $matchedProjectIds = $this->matchedProjectIdsForModule($module, $enabledActions, $scopes, $contexts);
        if ($matchedProjectIds !== null) {
            $shaped['matched_project_ids'] = $matchedProjectIds;
        }

        return $shaped;
    }

    private function hasAnyAuthorityPermission(Collection $effectivePermissions, array $scopes): bool
    {
        return collect(config('panel_modules.modules', []))
            ->filter(fn (array $module) => ($module['panel_type'] ?? 'authority') === 'authority')
            ->flatMap(fn (array $module) => $module['view_permissions'] ?? [])
            ->filter(fn (string $permission) => ! str_starts_with($permission, 'participant.') && ! str_starts_with($permission, 'alumni.'))
            ->unique()
            ->contains(fn (string $permission) => $effectivePermissions->contains($permission)
                && $this->scopeIsUsable($scopes[$permission] ?? null));
    }

    private function projectFamilyIsVisible(array $module, Collection $permissions, array $scopes, array $contexts): bool
    {
        if (! $this->hasProjectFamilyRequirements($module)) {
            return true;
        }

        return ! empty($this->matchedProjectIdsForModule($module, $permissions, $scopes, $contexts));
    }

    private function matchedProjectIdsForModule(array $module, Collection $permissions, array $scopes, array $contexts): ?array
    {
        if (! $this->hasProjectFamilyRequirements($module)) {
            return null;
        }

        $familyProjectIds = $this->familyProjectIds($module, $contexts);
        if (empty($familyProjectIds)) {
            return [];
        }

        $matched = [];
        foreach ($permissions as $permission) {
            $scope = $scopes[$permission] ?? null;
            if (! $this->scopeIsUsable($scope)) {
                continue;
            }

            $scopeType = $scope['scope_type'] ?? 'none';
            if ($scopeType === 'all') {
                $matched = array_merge($matched, $familyProjectIds);
                continue;
            }

            $scopeProjectIds = $this->projectIdsFromScope($scope, $contexts);
            if (empty($scopeProjectIds)) {
                continue;
            }

            $matched = array_merge($matched, array_intersect($familyProjectIds, $scopeProjectIds));
        }

        return collect($matched)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function hasProjectFamilyRequirements(array $module): bool
    {
        return ! empty($module['required_project_types'] ?? []) || ! empty($module['required_special_modules'] ?? []);
    }

    private function familyProjectIds(array $module, array $contexts): array
    {
        $idsByType = null;
        $requiredTypes = collect($module['required_project_types'] ?? [])
            ->map(fn ($type) => (string) $type)
            ->filter()
            ->values()
            ->all();

        if (! empty($requiredTypes)) {
            $idsByType = Project::query()
                ->whereIn('type', $requiredTypes)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $idsByModule = null;
        $requiredModules = collect($module['required_special_modules'] ?? [])
            ->map(fn ($key) => (string) $key)
            ->filter()
            ->values()
            ->all();

        if (! empty($requiredModules)) {
            $projectIdsBySpecialModule = $contexts['project_ids_by_special_module'] ?? [];
            $idsByModule = collect($requiredModules)
                ->flatMap(fn (string $key) => $projectIdsBySpecialModule[$key] ?? [])
                ->map(fn ($id) => (int) $id)
                ->filter(fn (int $id) => $id > 0)
                ->unique()
                ->values()
                ->all();
        }

        if ($idsByType !== null && $idsByModule !== null) {
            return array_values(array_intersect($idsByType, $idsByModule));
        }

        return array_values($idsByType ?? $idsByModule ?? []);
    }

    private function projectIdsFromScope(?array $scope, array $contexts): array
    {
        $scopeType = $scope['scope_type'] ?? 'none';
        $payload = $scope['scope_payload'] ?? [];

        if (in_array($scopeType, ['own_projects', 'assigned_projects', 'self'], true)) {
            return collect($contexts['manageable_project_ids'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->filter(fn (int $id) => $id > 0)
                ->unique()
                ->values()
                ->all();
        }

        if ($scopeType === 'selected_projects') {
            return collect($payload['project_ids'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->filter(fn (int $id) => $id > 0)
                ->unique()
                ->values()
                ->all();
        }

        return [];
    }

    private function scopeIsUsable(?array $scope): bool
    {
        return ! in_array($scope['scope_type'] ?? 'none', ['none', ''], true);
    }

    private function scopesFor(Collection $permissions, array $scopes): array
    {
        return $permissions
            ->mapWithKeys(fn (string $permission) => [
                $permission => $scopes[$permission] ?? [
                    'scope_type' => 'none',
                    'scope_payload' => [],
                ],
            ])
            ->all();
    }

    private function sections(Collection $modules): array
    {
        return $modules
            ->groupBy('panel_type')
            ->map(fn (Collection $panelModules) => $panelModules
                ->groupBy('section')
                ->map(fn (Collection $sectionModules) => $sectionModules
                    ->pluck('id')
                    ->values()
                    ->all()
                )
                ->all()
            )
            ->all();
    }
}
