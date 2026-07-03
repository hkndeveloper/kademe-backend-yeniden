<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use App\Support\ProjectFamilyCatalog;
use App\Support\ProjectSpecialModuleCatalog;
use Illuminate\Support\Collection;

class ProjectFamilyAccessResolver
{
    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function definition(string $familyKey): ?array
    {
        return ProjectFamilyCatalog::get($familyKey);
    }

    /**
     * @return Collection<int, Project>
     */
    public function familyProjects(string $familyKey): Collection
    {
        $definition = $this->definition($familyKey);
        if (! $definition) {
            return collect();
        }

        $projectTypes = $definition['project_types'] ?? [];
        $specialModules = $definition['special_modules'] ?? [];

        return Project::query()
            ->when(! empty($projectTypes), fn ($query) => $query->whereIn('type', $projectTypes))
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'type', 'status'])
            ->filter(function (Project $project) use ($specialModules) {
                if (empty($specialModules)) {
                    return true;
                }

                $available = ProjectSpecialModuleCatalog::forProject($project);

                return collect($specialModules)->contains(fn (string $module) => in_array($module, $available, true));
            })
            ->values();
    }

    /**
     * @return list<int>
     */
    public function accessibleProjectIds(User $user, string $familyKey): array
    {
        $definition = $this->definition($familyKey);
        if (! $definition) {
            return [];
        }

        $familyProjectIds = $this->familyProjects($familyKey)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if (empty($familyProjectIds)) {
            return [];
        }

        return collect($definition['permissions'] ?? [])
            ->flatMap(function (string $permission) use ($user, $familyProjectIds) {
                if (! $this->permissionResolver->hasPermission($user, $permission)) {
                    return [];
                }

                if ($this->permissionResolver->hasGlobalScope($user, $permission)) {
                    return $familyProjectIds;
                }

                return array_values(array_intersect(
                    $familyProjectIds,
                    $this->permissionResolver->projectIdsForPermission($user, $permission),
                ));
            })
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, Project>
     */
    public function accessibleProjects(User $user, string $familyKey): Collection
    {
        $accessibleProjectIds = $this->accessibleProjectIds($user, $familyKey);

        return $this->familyProjects($familyKey)
            ->filter(fn (Project $project) => in_array((int) $project->id, $accessibleProjectIds, true))
            ->values();
    }

    /**
     * @return array<string, bool>
     */
    public function accessMap(User $user, string $familyKey, ?int $projectId = null): array
    {
        $definition = $this->definition($familyKey);
        if (! $definition) {
            return [];
        }

        $accessibleProjectIds = $this->accessibleProjectIds($user, $familyKey);
        $projectIsAccessible = $projectId === null || in_array($projectId, $accessibleProjectIds, true);

        return collect($definition['permissions'] ?? [])
            ->mapWithKeys(fn (string $permission) => [
                $permission => $projectIsAccessible && $this->permissionResolver->canAccessProject($user, $permission, $projectId),
            ])
            ->all();
    }
}
