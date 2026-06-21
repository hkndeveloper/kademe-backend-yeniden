<?php

namespace App\Support;

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

        $modules = collect(config('panel_modules.modules', []))
            ->filter(fn (array $module) => $this->isVisible($module, $effectivePermissions, $scopes, $user))
            ->map(fn (array $module) => $this->shapeModule($module, $effectivePermissions, $scopes))
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
                'manageable_project_ids' => $authorization['contexts']['manageable_project_ids'] ?? [],
                'project_ids_by_special_module' => $authorization['contexts']['project_ids_by_special_module'] ?? [],
                'user_special_modules' => $authorization['contexts']['user_special_modules'] ?? [],
                'manageable_unit' => $authorization['contexts']['manageable_unit'] ?? null,
            ],
        ];
    }

    private function isVisible(array $module, Collection $effectivePermissions, array $scopes, User $user): bool
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

        return $viewPermissions->contains(
            fn (string $permission) => $effectivePermissions->contains($permission)
                && $this->scopeIsUsable($scopes[$permission] ?? null)
        );
    }

    private function shapeModule(array $module, Collection $effectivePermissions, array $scopes): array
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

        return [
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
