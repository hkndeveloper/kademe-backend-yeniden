<?php

namespace App\Services;

use App\Models\Project;
use App\Models\RolePermissionScope;
use App\Models\Request as SupportRequest;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PermissionResolver
{
    public function hasPermission(User $user, string $permissionName): bool
    {
        $resolved = $this->resolve($user);

        return in_array($permissionName, $resolved['effective_permissions']->all(), true);
    }

    public function scopeFor(User $user, string $permissionName): array
    {
        $resolved = $this->resolve($user);

        return $resolved['scopes'][$permissionName] ?? [
            'scope_type' => 'none',
            'scope_payload' => [],
        ];
    }

    public function canAccessProject(User $user, string $permissionName, ?int $projectId): bool
    {
        if (! $this->hasPermission($user, $permissionName)) {
            return false;
        }

        $scope = $this->scopeFor($user, $permissionName);
        $scopeType = $scope['scope_type'] ?? 'none';
        $scopePayload = $scope['scope_payload'] ?? [];

        return match ($scopeType) {
            'all' => true,
            'own_projects', 'assigned_projects', 'selected_projects' => $projectId !== null
                && in_array($projectId, $scopePayload['project_ids'] ?? [], true),
            'self' => $projectId !== null && $user->participations
                ->where('project_id', $projectId)
                ->isNotEmpty(),
            'none' => false,
            default => $this->denyProjectScopeWithOptionalLog($permissionName, $scopeType, $projectId),
        };
    }

    public function canAccessUnit(User $user, string $permissionName, ?string $unit): bool
    {
        if (! $this->hasPermission($user, $permissionName)) {
            return false;
        }

        $scope = $this->scopeFor($user, $permissionName);
        $scopeType = $scope['scope_type'] ?? 'none';
        $scopePayload = $scope['scope_payload'] ?? [];

        return match ($scopeType) {
            'all' => true,
            'own_unit' => $unit !== null && ($scopePayload['unit'] ?? null) === $unit,
            'none' => false,
            default => $this->denyUnitScopeWithOptionalLog($permissionName, $scopeType, $unit),
        };
    }

    public function resolve(User $user): array
    {
        $user->loadMissing(['roles:id,name', 'staffProfile', 'coordinatedProjects:id', 'participations:id,user_id,project_id']);

        $basePermissions = $user->getAllPermissions()->pluck('name')->values()->all();
        $effectivePermissions = collect($basePermissions)
            ->merge($this->expandLegacyPermissions($basePermissions))
            ->unique()
            ->values();

        $overrides = Schema::hasTable('user_permission_overrides')
            ? $user->permissionOverrides()->get()
            : collect();

        $allowedPermissions = $overrides
            ->where('effect', 'allow')
            ->pluck('permission_name')
            ->filter()
            ->values()
            ->all();

        $deniedPermissions = $overrides
            ->where('effect', 'deny')
            ->pluck('permission_name')
            ->filter()
            ->values()
            ->all();

        $effectivePermissions = $effectivePermissions
            ->merge($allowedPermissions)
            ->merge($this->expandLegacyPermissions($allowedPermissions))
            ->reject(function (string $permission) use ($deniedPermissions) {
                if (in_array($permission, $deniedPermissions, true)) {
                    return true;
                }

                foreach ($deniedPermissions as $deniedPermission) {
                    if (Str::endsWith($deniedPermission, '.*')) {
                        $prefix = Str::beforeLast($deniedPermission, '.*');
                        if (Str::startsWith($permission, $prefix . '.')) {
                            return true;
                        }
                    }
                }

                return false;
            })
            ->unique()
            ->sort()
            ->values();

        return [
            'role_permissions' => collect($basePermissions)->sort()->values(),
            'effective_permissions' => $effectivePermissions,
            'direct_overrides' => $overrides->map(fn ($override) => [
                'permission_name' => $override->permission_name,
                'effect' => $override->effect,
                'scope_type' => $override->scope_type,
                'scope_payload' => $override->scope_payload,
            ])->values(),
            'scopes' => $this->resolveScopes($user, $effectivePermissions, $overrides),
            'contexts' => [
                'manageable_project_ids' => $this->manageableProjectIds($user),
                'manageable_unit' => $user->staffProfile?->unit,
            ],
        ];
    }

    private function expandLegacyPermissions(array $permissionNames): Collection
    {
        $legacyMap = collect(config('permission_catalog.legacy_map', []));

        return collect($permissionNames)
            ->flatMap(fn (string $permissionName) => $legacyMap->get($permissionName, []))
            ->unique()
            ->values();
    }

    private function resolveScopes(User $user, Collection $effectivePermissions, Collection $overrides): array
    {
        $scopes = [];
        $manageableProjectIds = $this->manageableProjectIds($user);
        $manageableUnit = $user->staffProfile?->unit;
        $roleNames = $user->roles->pluck('name')->filter()->values()->all();
        $roleScopeRows = empty($roleNames) || ! Schema::hasTable('role_permission_scopes')
            ? collect()
            : RolePermissionScope::query()
                ->whereIn('role_name', $roleNames)
                ->get()
                ->groupBy('permission_name');

        foreach ($effectivePermissions as $permissionName) {
            $scope = $this->defaultScopeFor($user, $permissionName, $manageableProjectIds, $manageableUnit);
            $roleScope = $this->mergedRoleScope($roleScopeRows->get($permissionName, collect()));
            if ($roleScope !== null) {
                $scope = $roleScope;
            }

            $override = $overrides
                ->where('effect', 'allow')
                ->firstWhere('permission_name', $permissionName);

            if ($override && $override->scope_type) {
                $scope = [
                    'scope_type' => $override->scope_type,
                    'scope_payload' => $override->scope_payload ?? [],
                ];
            }

            $scopes[$permissionName] = $scope;
        }

        return $scopes;
    }

    private function mergedRoleScope(Collection $scopeRows): ?array
    {
        if ($scopeRows->isEmpty()) {
            return null;
        }

        $byPriority = [
            'all' => 100,
            'selected_projects' => 90,
            'own_projects' => 80,
            'assigned_projects' => 70,
            'own_unit' => 60,
            'self' => 50,
            'none' => 10,
        ];

        $best = $scopeRows
            ->sortByDesc(function ($row) use ($byPriority) {
                return $byPriority[$row->scope_type] ?? 0;
            })
            ->first();

        if (! $best) {
            return null;
        }

        $payload = (array) ($best->scope_payload ?? []);
        if (in_array($best->scope_type, ['selected_projects', 'own_projects', 'assigned_projects'], true)) {
            $projectIds = $scopeRows
                ->filter(fn ($row) => in_array($row->scope_type, ['selected_projects', 'own_projects', 'assigned_projects'], true))
                ->flatMap(fn ($row) => (array) (($row->scope_payload ?? [])['project_ids'] ?? []))
                ->filter(fn ($id) => is_numeric($id))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
            if (! empty($projectIds)) {
                $payload['project_ids'] = $projectIds;
            }
        }

        return [
            'scope_type' => $best->scope_type,
            'scope_payload' => $payload,
        ];
    }

    private function defaultScopeFor(User $user, string $permissionName, array $manageableProjectIds, ?string $manageableUnit): array
    {
        if ($user->role === 'super_admin') {
            return [
                'scope_type' => 'all',
                'scope_payload' => [],
            ];
        }

        /**
         * Takvim / program / başvuru / proje görünürlüğü: koordinator ve personel listelerinde çakışma kontrolü için tum projeler.
         * Ogrenci / mezun icin uygulanmaz (self kapsam asagida).
         */
        if (
            in_array($user->role, ['coordinator', 'staff'], true)
            && $this->permissionHasOrganizationWideViewScope($permissionName)
        ) {
            return [
                'scope_type' => 'all',
                'scope_payload' => [],
            ];
        }

        if ($user->role === 'coordinator') {
            if ($this->matchesAny($permissionName, [
                'projects.',
                'periods.',
                'programs.',
                'calendar.',
                'applications.',
                'financial.',
                'support.',
                'requests.',
                'announcements.',
                'content.',
                'certificates.',
            ])) {
                return [
                    'scope_type' => 'own_projects',
                    'scope_payload' => ['project_ids' => $manageableProjectIds],
                ];
            }

            if ($this->matchesAny($permissionName, ['staff.'])) {
                return [
                    'scope_type' => 'own_unit',
                    'scope_payload' => ['unit' => $manageableUnit],
                ];
            }
        }

        if ($user->role === 'staff') {
            if ($this->matchesAny($permissionName, ['requests.', 'support.', 'applications.', 'projects.', 'calendar.'])) {
                return [
                    'scope_type' => 'assigned_projects',
                    'scope_payload' => ['project_ids' => $manageableProjectIds],
                ];
            }

            if ($this->matchesAny($permissionName, ['staff.'])) {
                return [
                    'scope_type' => 'own_unit',
                    'scope_payload' => ['unit' => $manageableUnit],
                ];
            }
        }

        if (in_array($user->role, ['student', 'alumni'], true)) {
            return [
                'scope_type' => 'self',
                'scope_payload' => ['user_id' => $user->id],
            ];
        }

        return [
            'scope_type' => 'none',
            'scope_payload' => [],
        ];
    }

    private function manageableProjectIds(User $user): array
    {
        if ($user->role === 'super_admin') {
            return Project::query()->pluck('id')->all();
        }

        if ($user->role === 'coordinator') {
            return $user->coordinatedProjects->pluck('id')->values()->all();
        }

        if ($user->role === 'staff') {
            $unit = mb_strtolower((string) $user->staffProfile?->unit);
            $markers = array_map(
                static fn (string $m): string => mb_strtolower($m),
                config('permission_catalog.media_unit_markers', ['medya', 'media'])
            );

            foreach ($markers as $marker) {
                if ($marker !== '' && str_contains($unit, $marker)) {
                    return Project::query()->where('status', 'active')->pluck('id')->all();
                }
            }

            return collect()
                ->merge(
                    SupportRequest::query()
                        ->where(function ($builder) use ($user) {
                            $builder
                                ->where('requester_id', $user->id)
                                ->orWhere('target_user_id', $user->id);
                        })
                        ->whereNotNull('project_id')
                        ->pluck('project_id')
                )
                ->merge(
                    SupportTicket::query()
                        ->where(function ($builder) use ($user) {
                            $builder
                                ->where('user_id', $user->id)
                                ->orWhere('assigned_to', $user->id);
                        })
                        ->whereNotNull('project_id')
                        ->pluck('project_id')
                )
                ->unique()
                ->values()
                ->all();
        }

        if (in_array($user->role, ['student', 'alumni'], true)) {
            return $user->participations->pluck('project_id')->unique()->values()->all();
        }

        return [];
    }

    private function matchesAny(string $permissionName, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (Str::startsWith($permissionName, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sadece görüntüleme; canAccessProject bu izinlerde tüm projeleri kabul eder (backend + frontend uyumu).
     */
    private function permissionHasOrganizationWideViewScope(string $permissionName): bool
    {
        return in_array($permissionName, [
            'calendar.view',
            'programs.view',
            'applications.view',
            'projects.view',
            'periods.view',
        ], true);
    }

    /**
     * Panel ve listelerde kullanılacak proje kimlikleri (rol + kullanıcı override ile tutarlı).
     */
    public function manageableProjectIdsForUser(User $user): array
    {
        return $this->resolve($user)['contexts']['manageable_project_ids'] ?? [];
    }

    /**
     * Permission kapsamına göre erişilebilir proje listesi.
     * Endpoint bazlı filtrelemelerde context yerine bunu kullanın.
     */
    public function projectIdsForPermission(User $user, string $permissionName): array
    {
        if (! $this->hasPermission($user, $permissionName)) {
            return [];
        }

        $scope = $this->scopeFor($user, $permissionName);
        $scopeType = $scope['scope_type'] ?? 'none';
        $payload = $scope['scope_payload'] ?? [];

        return match ($scopeType) {
            'all' => Project::query()->pluck('id')->all(),
            'own_projects', 'assigned_projects', 'selected_projects' => collect($payload['project_ids'] ?? [])
                ->filter(fn ($id) => is_numeric($id))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all(),
            'self' => $user->participations->pluck('project_id')->unique()->values()->all(),
            default => [],
        };
    }

    /**
     * canAccessProject: birim / diger proje-disi scope tipleri burada false doner; yalnizca beklenmeyen scope_type loglanir.
     */
    private function denyProjectScopeWithOptionalLog(string $permissionName, string $scopeType, ?int $projectId): bool
    {
        $knownNonProject = ['own_unit'];

        if ($scopeType !== '' && ! in_array($scopeType, $knownNonProject, true)) {
            Log::debug('permission_resolver.unhandled_project_scope', [
                'permission' => $permissionName,
                'scope_type' => $scopeType,
                'project_id' => $projectId,
            ]);
        }

        return false;
    }

    private function denyUnitScopeWithOptionalLog(string $permissionName, string $scopeType, ?string $unit): bool
    {
        $knownNonUnit = ['own_projects', 'assigned_projects', 'selected_projects', 'self'];

        if ($scopeType !== '' && ! in_array($scopeType, $knownNonUnit, true)) {
            Log::debug('permission_resolver.unhandled_unit_scope', [
                'permission' => $permissionName,
                'scope_type' => $scopeType,
                'unit' => $unit,
            ]);
        }

        return false;
    }
}
