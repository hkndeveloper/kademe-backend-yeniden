<?php

namespace App\Services;

use App\Models\Project;
use App\Models\RolePermissionScope;
use App\Models\User;
use App\Support\AuthorizationManagementCatalog;
use App\Support\ProjectSpecialModuleCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PermissionResolver
{
    /** @var array<string, array<string, mixed>> */
    private array $requestResolutionCache = [];

    private const TARGET_UNIT_ALIASES = [
        'media' => ['media', 'medya', 'icerik', 'content', 'tasarim', 'design'],
        'operations' => ['operations', 'operasyon', 'lojistik', 'logistics'],
        'program' => ['program', 'proje', 'project', 'egitim', 'education'],
        'finance' => ['finance', 'finans', 'mali', 'muhasebe'],
        'official_affairs' => ['official_affairs', 'official affairs', 'resmi', 'evrak', 'idari'],
        'general' => ['general', 'genel'],
    ];

    public function __construct(
        private readonly CoordinationUnitAuthorizationResolver $coordinationUnitAuthorizationResolver,
        private readonly ActiveCoordinationUnitContext $activeCoordinationUnitContext
    ) {}

    public function hasPermission(User $user, string $permissionName): bool
    {
        $resolved = $this->resolve($user);

        return in_array($permissionName, $resolved['effective_permissions']->all(), true);
    }

    public function flushRequestCache(): void
    {
        $this->requestResolutionCache = [];
    }

    public function scopeFor(User $user, string $permissionName): array
    {
        $resolved = $this->resolve($user);

        return $resolved['scopes'][$permissionName] ?? [
            'scope_type' => 'none',
            'scope_payload' => [],
        ];
    }

    public function hasGlobalScope(User $user, string $permissionName): bool
    {
        if (! $this->hasPermission($user, $permissionName)) {
            return false;
        }

        return ($this->scopeFor($user, $permissionName)['scope_type'] ?? 'none') === 'all';
    }

    public function canAccessProject(User $user, string $permissionName, ?int $projectId): bool
    {
        if (! $this->hasPermission($user, $permissionName)) {
            return false;
        }

        $scope = $this->scopeFor($user, $permissionName);
        $scopeType = $scope['scope_type'] ?? 'none';
        $scopePayload = $scope['scope_payload'] ?? [];

        $scopedProjectIds = $this->projectIdsForPermission($user, $permissionName);

        return match ($scopeType) {
            'all' => true,
            'own_projects', 'assigned_projects', 'selected_projects' => $projectId !== null
                && in_array($projectId, $scopedProjectIds, true),
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
            'own_unit' => $this->scopeMatchesUnit($scopePayload, $unit),
            'none' => false,
            default => $this->denyUnitScopeWithOptionalLog($permissionName, $scopeType, $unit),
        };
    }

    public function canAccessCoordinationUnit(User $user, string $permissionName, ?int $unitId): bool
    {
        if ($unitId === null || ! $this->hasPermission($user, $permissionName)) {
            return false;
        }

        if ($this->hasGlobalScope($user, $permissionName)) {
            return true;
        }

        $scope = $this->scopeFor($user, $permissionName);
        $unitIds = collect($scope['scope_payload']['unit_ids'] ?? [])
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->all();

        if (in_array($unitId, $unitIds, true)) {
            return true;
        }

        if ($this->coordinationUnitsAreAuthoritative($user)) {
            return false;
        }

        // Legacy/shadow rollback kipinde eski kayit policy'si korunur; enforce/pilot bunu kullanmaz.
        return $user->coordinationUnitMemberships()
            ->active()
            ->where('unit_id', $unitId)
            ->whereHas('unit', fn ($query) => $query->where('status', 'active'))
            ->exists();
    }

    public function matchesTargetUnit(?string $staffUnit, ?string $targetUnit): bool
    {
        $staffUnit = $this->normalizeUnit($staffUnit);
        $targetUnit = $this->normalizeUnit($targetUnit);

        if (! $staffUnit || ! $targetUnit) {
            return false;
        }

        $aliases = self::TARGET_UNIT_ALIASES[$targetUnit] ?? [$targetUnit];

        foreach ($aliases as $alias) {
            $normalizedAlias = $this->normalizeUnit($alias);
            if ($normalizedAlias && str_contains($staffUnit, $normalizedAlias)) {
                return true;
            }
        }

        return false;
    }

    public function canAccessTargetUnit(User $user, string $permissionName, ?string $targetUnit): bool
    {
        if (! $this->hasPermission($user, $permissionName)) {
            return false;
        }

        if ($this->hasGlobalScope($user, $permissionName)) {
            return true;
        }

        return $this->canAccessTargetUnitWithoutPermission($user, $targetUnit);
    }

    /**
     * @param  list<string>  $candidateTargetUnits
     * @return list<string>
     */
    public function targetUnitsForUser(User $user, array $candidateTargetUnits, ?string $permissionName = null): array
    {
        if ($permissionName !== null && $this->hasGlobalScope($user, $permissionName)) {
            return array_values($candidateTargetUnits);
        }

        return collect($candidateTargetUnits)
            ->filter(fn (string $targetUnit) => $permissionName === null
                ? $this->canAccessTargetUnitWithoutPermission($user, $targetUnit)
                : $this->canAccessTargetUnit($user, $permissionName, $targetUnit))
            ->values()
            ->all();
    }

    /**
     * Duyuru gibi bir hedef-kitle hesabinda kullanicinin secili oturum biriminden
     * bagimsiz olarak tum aktif birim uyeliklerini dikkate alir. Aktif uyeligi
     * bulunan kullanicida eski staff_profiles.unit alani yetki kaynagi olmaz.
     */
    public function userHasActiveMembershipForTargetUnit(User $user, ?string $targetUnit): bool
    {
        $memberships = $user->coordinationUnitMemberships()
            ->active()
            ->whereHas('unit', fn ($query) => $query->where('status', 'active'))
            ->with('unit')
            ->get();

        if ($memberships->isNotEmpty()) {
            return $memberships->contains(
                fn ($membership) => $this->coordinationUnitMatchesTarget($membership->unit, $targetUnit)
            );
        }

        return $this->matchesTargetUnit($user->staffProfile?->unit, $targetUnit);
    }

    /**
     * @return list<int>
     */
    public function coordinationUnitIdsForPermission(User $user, string $permissionName): array
    {
        if (! $this->hasPermission($user, $permissionName)) {
            return [];
        }

        return collect($this->scopeFor($user, $permissionName)['scope_payload']['unit_ids'] ?? [])
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function unitNameForPermission(User $user, string $permissionName): ?string
    {
        if (! $this->hasPermission($user, $permissionName)) {
            return null;
        }

        $payload = $this->scopeFor($user, $permissionName)['scope_payload'] ?? [];
        $unit = trim((string) ($payload['unit'] ?? ''));
        if ($unit !== '') {
            return $unit;
        }

        $first = collect($payload['units'] ?? [])->first(fn ($value) => is_string($value) && trim($value) !== '');
        if (is_string($first)) {
            return $first;
        }

        return $this->activeCoordinationUnitContext->membershipFor($user)?->unit?->name;
    }

    private function canAccessTargetUnitWithoutPermission(User $user, ?string $targetUnit): bool
    {
        $activeMembership = $this->activeCoordinationUnitContext->membershipFor($user);
        if ($this->coordinationUnitsAreAuthoritative($user)) {
            if (! $activeMembership?->unit) {
                return false;
            }

            return $this->coordinationUnitMatchesTarget($activeMembership->unit, $targetUnit);
        }

        return $this->matchesTargetUnit($user->staffProfile?->unit, $targetUnit);
    }

    private function coordinationUnitMatchesTarget($unit, ?string $targetUnit): bool
    {
        if (! $unit) {
            return false;
        }

        $legacyTargetsByUnitCode = [
            'service_media' => ['media'],
            'service_purchase_organization' => ['operations', 'finance', 'official_affairs'],
            'service_community_culture' => ['general', 'program'],
        ];
        $targets = $legacyTargetsByUnitCode[$unit->code] ?? [];
        if (str_starts_with((string) $unit->code, 'project_')) {
            $targets[] = 'program';
        }

        return in_array($targetUnit, $targets, true)
            || $this->matchesTargetUnit($unit->name, $targetUnit)
            || $this->matchesTargetUnit($unit->code, $targetUnit);
    }

    public function resolve(User $user): array
    {
        $requestContext = app()->bound('request')
            ? request()->attributes->get(ActiveCoordinationUnitContext::ATTRIBUTE)
            : null;
        $activeMembershipId = is_array($requestContext)
            && (int) ($requestContext['user_id'] ?? 0) === (int) $user->id
                ? $requestContext['membership_id'] ?? null
                : null;
        $cacheKey = implode(':', [
            spl_object_id($user),
            (int) $user->id,
            (string) $user->role,
            $this->coordinationAuthorizationMode(),
            $activeMembershipId === null ? 'fallback' : (int) $activeMembershipId,
        ]);

        return $this->requestResolutionCache[$cacheKey] ??= $this->resolveUncached($user);
    }

    private function resolveUncached(User $user): array
    {
        $legacy = $this->resolveLegacy($user);
        $mode = $this->coordinationAuthorizationMode();

        if ($mode === 'legacy' || in_array($user->role, ['super_admin', 'student', 'alumni'], true)) {
            return $legacy;
        }

        if ($mode === 'pilot' && ! $this->isPilotUser($user)) {
            $legacy['authorization_meta'] = [
                'mode' => 'pilot',
                'authoritative_source' => 'legacy',
                'unit_result_available' => false,
                'pilot_selected' => false,
            ];

            return $legacy;
        }

        if ($mode === 'shadow') {
            $unit = $this->coordinationUnitAuthorizationResolver->resolve(
                $user,
                collect($legacy['direct_overrides'] ?? [])
            );
            $diff = $this->coordinationAuthorizationDiff($legacy, $unit);
            if (config('coordination_authorization.shadow_log_differences', true) && $diff['has_difference']) {
                Log::info('coordination_authorization.shadow_diff', [
                    'user_id' => (int) $user->id,
                    'legacy_role' => $user->role,
                    'diff' => $diff,
                ]);
            }

            $legacy['contexts'] = array_merge($legacy['contexts'], [
                'unit_memberships' => $unit['contexts']['unit_memberships'],
                'primary_unit_id' => $unit['contexts']['primary_unit_id'],
                'coordinated_unit_ids' => $unit['contexts']['coordinated_unit_ids'],
                'staffed_unit_ids' => $unit['contexts']['staffed_unit_ids'],
                'unit_project_ids_by_permission' => $unit['contexts']['project_ids_by_permission'],
                'unit_manageable_project_ids' => $unit['contexts']['manageable_project_ids'],
            ]);
            $legacy['authorization_meta'] = [
                'mode' => 'shadow',
                'authoritative_source' => 'legacy',
                'unit_result_available' => true,
                'diff' => $diff,
            ];

            return $legacy;
        }

        $activeMembership = $this->activeCoordinationUnitContext->membershipFor($user);
        $globalOverrides = collect($legacy['direct_overrides'] ?? [])
            ->reject(fn ($override) => ($override['effect'] ?? null) === 'allow'
                && AuthorizationManagementCatalog::isUnitBusinessPermission(
                    (string) ($override['permission_name'] ?? '')
                ))
            ->values();
        $membershipOverrides = $activeMembership?->permissionOverrides
            ?->where('status', 'active')
            ->map(fn ($override) => [
                'permission_name' => $override->permission_name,
                'effect' => $override->effect,
                'scope_type' => $override->scope_type,
                'scope_payload' => $override->scope_payload ?? [],
            ])
            ->values() ?? collect();
        $unit = $this->coordinationUnitAuthorizationResolver->resolveForMembership(
            $user,
            $activeMembership,
            $globalOverrides,
            $membershipOverrides
        );
        $activeContext = $this->activeCoordinationUnitContext->metadataFor($user);

        return [
            'role_permissions' => $legacy['role_permissions'],
            'effective_permissions' => $unit['effective_permissions'],
            'direct_overrides' => $legacy['direct_overrides'],
            'scopes' => $unit['scopes'],
            'contexts' => array_merge($legacy['contexts'], $unit['contexts'], [
                'active_coordination_context' => $activeContext,
            ]),
            'authorization_meta' => [
                'mode' => $mode,
                'authoritative_source' => 'coordination_units',
                'unit_result_available' => true,
                'pilot_selected' => $mode === 'pilot',
                'active_coordination_context' => $activeContext,
            ],
        ];
    }

    public function coordinationUnitsAreAuthoritative(User $user): bool
    {
        return $this->activeCoordinationUnitContext->isAuthoritativeFor($user);
    }

    public function resolveCoordinationUnionSnapshot(User $user): array
    {
        $legacy = $this->resolveLegacy($user);

        return $this->coordinationUnitAuthorizationResolver->resolve(
            $user,
            collect($legacy['direct_overrides'] ?? [])
        );
    }

    public function resolveLegacySnapshot(User $user): array
    {
        return $this->resolveLegacy($user);
    }

    private function resolveLegacy(User $user): array
    {
        $user->loadMissing([
            'roles:id,name',
            'roles.permissions:id,name',
            'staffProfile',
            'coordinatedProjects:id',
            'assignedProjects:id',
            'participations:id,user_id,project_id',
        ]);

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
                        if (Str::startsWith($permission, $prefix.'.')) {
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
                'project_ids_by_special_module' => $this->projectIdsBySpecialModule(),
                'user_special_modules' => $this->userSpecialModules($user),
                'manageable_unit' => $user->staffProfile?->unit,
            ],
        ];
    }

    /**
     * @return array<string, list<int>>
     */
    private function projectIdsBySpecialModule(): array
    {
        return Project::query()
            ->get(['id', 'type', 'name', 'slug'])
            ->reduce(function (array $carry, Project $project) {
                foreach (ProjectSpecialModuleCatalog::forProject($project) as $moduleKey) {
                    $carry[$moduleKey] ??= [];
                    $carry[$moduleKey][] = (int) $project->id;
                }

                return $carry;
            }, []);
    }

    /**
     * @return list<string>
     */
    private function userSpecialModules(User $user): array
    {
        if (! in_array($user->role, ['student', 'alumni'], true)) {
            return [];
        }

        return $user->participations()
            ->where(function ($query) use ($user) {
                $query->where('status', 'active');

                if ($user->role === 'alumni') {
                    $query->orWhere('graduation_status', 'graduated')
                        ->orWhereNotNull('graduated_at');
                }
            })
            ->with('project:id,type,name,slug')
            ->get()
            ->flatMap(fn ($participation) => $participation->project
                ? ProjectSpecialModuleCatalog::forProject($participation->project)
                : []
            )
            ->unique()
            ->values()
            ->all();
    }

    private function expandLegacyPermissions(array $permissionNames): Collection
    {
        $legacyMap = collect(config('permission_catalog.legacy_map', []));

        return collect($permissionNames)
            ->flatMap(fn (string $permissionName) => $legacyMap->get($permissionName, []))
            ->unique()
            ->values();
    }

    private function roleGrantsPermission($role, string $permissionName): bool
    {
        $permissionNames = $role->permissions
            ->pluck('name')
            ->filter()
            ->values()
            ->all();

        return in_array($permissionName, $permissionNames, true)
            || $this->expandLegacyPermissions($permissionNames)->contains($permissionName);
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
            $grantingRoleNames = $user->roles
                ->filter(fn ($role) => $this->roleGrantsPermission($role, $permissionName))
                ->pluck('name')
                ->values()
                ->all();
            $roleScope = $this->mergedRoleScope(
                $roleScopeRows
                    ->get($permissionName, collect())
                    ->filter(fn ($row) => in_array($row->role_name, $grantingRoleNames, true))
            );
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
        if ($best->scope_type === 'selected_projects') {
            $projectIds = $scopeRows
                ->filter(fn ($row) => $row->scope_type === 'selected_projects')
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
                'dashboard.',
                'projects.',
                'periods.',
                'programs.',
                'calendar.',
                'applications.',
                'volunteer.',
                'financial.',
                'support.',
                'requests.',
                'announcements.',
                'inbox.',
                'alumni_opportunities.',
                'forum.',
                'content.view',
                'content.blog.',
                'certificates.',
                'digital_bohca.',
                'assignments.',
                'kpd.',
            ])) {
                return [
                    'scope_type' => 'own_projects',
                    'scope_payload' => ['project_ids' => $manageableProjectIds],
                ];
            }

            if ($this->matchesAny($permissionName, ['staff.', 'users.'])) {
                return [
                    'scope_type' => 'own_unit',
                    'scope_payload' => ['unit' => $manageableUnit],
                ];
            }
        }

        if ($user->role === 'staff') {
            if ($this->matchesAny($permissionName, ['dashboard.', 'requests.', 'support.', 'applications.', 'volunteer.', 'projects.', 'programs.', 'periods.', 'calendar.', 'announcements.', 'inbox.', 'alumni_opportunities.', 'forum.', 'certificates.', 'digital_bohca.', 'assignments.', 'kpd.'])) {
                return [
                    'scope_type' => 'assigned_projects',
                    'scope_payload' => ['project_ids' => $manageableProjectIds],
                ];
            }

            if ($this->matchesAny($permissionName, ['content.view', 'content.blog.'])) {
                return [
                    'scope_type' => 'assigned_projects',
                    'scope_payload' => ['project_ids' => $manageableProjectIds],
                ];
            }

            if ($this->matchesAny($permissionName, ['staff.', 'users.'])) {
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

            return $user->assignedProjects->pluck('id')->unique()->values()->all();
        }

        if (in_array($user->role, ['student', 'alumni'], true)) {
            return $user->participations->pluck('project_id')->unique()->values()->all();
        }

        return $user->assignedProjects
            ->pluck('id')
            ->merge($user->coordinatedProjects->pluck('id'))
            ->unique()
            ->values()
            ->all();
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
            'own_projects', 'assigned_projects' => $this->manageableProjectIds($user),
            'selected_projects' => collect($payload['project_ids'] ?? [])
                ->filter(fn ($id) => is_numeric($id))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all(),
            'self' => $user->participations->pluck('project_id')->unique()->values()->all(),
            default => [],
        };
    }

    public function canAccessUser(User $actor, string $permissionName, User $target): bool
    {
        if (! $this->hasPermission($actor, $permissionName)) {
            return false;
        }

        $scope = $this->scopeFor($actor, $permissionName);
        $scopeType = $scope['scope_type'] ?? 'none';

        return match ($scopeType) {
            'all' => true,
            'own_unit' => $this->canAccessUserThroughUnitMembership($actor, $permissionName, $target),
            'self' => $actor->id === $target->id,
            'none' => false,
            default => false,
        };
    }

    public function applyUserScope($query, User $actor, string $permissionName): void
    {
        if (! $this->hasPermission($actor, $permissionName)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $scope = $this->scopeFor($actor, $permissionName);
        $scopeType = $scope['scope_type'] ?? 'none';

        if ($scopeType === 'all') {
            return;
        }

        if ($scopeType === 'own_unit') {
            $unitIds = $this->coordinationUnitIdsForPermission($actor, $permissionName);
            $unit = $this->normalizeUnit($this->unitNameForPermission($actor, $permissionName));
            if ($unitIds === [] && $unit === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->where(function ($builder) use ($unitIds, $unit) {
                if ($unitIds !== []) {
                    $builder->whereHas('coordinationUnitMemberships', fn ($membershipQuery) => $membershipQuery
                        ->active()
                        ->whereIn('unit_id', $unitIds)
                        ->whereHas('unit', fn ($unitQuery) => $unitQuery->where('status', 'active')));
                }

                if ($unit !== null) {
                    $method = $unitIds === [] ? 'where' : 'orWhere';
                    $builder->{$method}(function ($legacyQuery) use ($unit) {
                        $legacyQuery
                            ->whereDoesntHave('coordinationUnitMemberships', fn ($membershipQuery) => $membershipQuery->active())
                            ->whereHas('staffProfile', fn ($profileQuery) => $profileQuery
                                ->whereRaw('LOWER(TRIM(unit)) = ?', [$unit]));
                    });
                }
            });

            return;
        }

        if ($scopeType === 'self') {
            $query->where('id', $actor->id);

            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function canAccessUserThroughUnitMembership(User $actor, string $permissionName, User $target): bool
    {
        $unitIds = $this->coordinationUnitIdsForPermission($actor, $permissionName);
        $targetUnitIds = $target->coordinationUnitMemberships()
            ->active()
            ->whereHas('unit', fn ($query) => $query->where('status', 'active'))
            ->pluck('unit_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($targetUnitIds !== []) {
            return array_intersect($unitIds, $targetUnitIds) !== [];
        }

        $target->loadMissing('staffProfile');

        return $this->canAccessUnit($actor, $permissionName, $target->staffProfile?->unit);
    }

    /**
     * canAccessProject: birim / diger proje-disi scope tipleri burada false doner; yalnizca beklenmeyen scope_type loglanir.
     */
    private function denyProjectScopeWithOptionalLog(string $permissionName, string $scopeType, ?int $projectId): bool
    {
        $knownNonProject = ['own_unit', 'own_record'];

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
        $knownNonUnit = ['own_projects', 'assigned_projects', 'selected_projects', 'own_record', 'self'];

        if ($scopeType !== '' && ! in_array($scopeType, $knownNonUnit, true)) {
            Log::debug('permission_resolver.unhandled_unit_scope', [
                'permission' => $permissionName,
                'scope_type' => $scopeType,
                'unit' => $unit,
            ]);
        }

        return false;
    }

    private function scopeMatchesUnit(array $scopePayload, ?string $unit): bool
    {
        $normalizedTarget = $this->normalizeUnit($unit);
        if ($normalizedTarget === null) {
            return false;
        }

        return collect([
            $scopePayload['unit'] ?? null,
            ...($scopePayload['units'] ?? []),
            ...($scopePayload['unit_codes'] ?? []),
        ])->contains(fn ($candidate) => $this->normalizeUnit($candidate) === $normalizedTarget);
    }

    private function coordinationAuthorizationMode(): string
    {
        $mode = strtolower((string) config('coordination_authorization.mode', 'legacy'));

        return in_array($mode, ['legacy', 'shadow', 'pilot', 'enforce'], true) ? $mode : 'legacy';
    }

    private function isPilotUser(User $user): bool
    {
        return collect(config('coordination_authorization.pilot_user_ids', []))
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->contains((int) $user->id);
    }

    private function coordinationAuthorizationDiff(array $legacy, array $unit): array
    {
        $legacyPermissions = collect($legacy['effective_permissions'] ?? [])->map(fn ($item) => (string) $item);
        $unitPermissions = collect($unit['effective_permissions'] ?? [])->map(fn ($item) => (string) $item);
        $legacyOnly = $legacyPermissions->diff($unitPermissions)->sort()->values()->all();
        $unitOnly = $unitPermissions->diff($legacyPermissions)->sort()->values()->all();
        $commonPermissions = $legacyPermissions->intersect($unitPermissions)->unique();
        $scopeDifferences = $commonPermissions
            ->filter(function (string $permission) use ($legacy, $unit) {
                return ($legacy['scopes'][$permission] ?? null) !== ($unit['scopes'][$permission] ?? null);
            })
            ->sort()
            ->values()
            ->all();

        return [
            'has_difference' => $legacyOnly !== [] || $unitOnly !== [] || $scopeDifferences !== [],
            'legacy_only_permissions' => $legacyOnly,
            'unit_only_permissions' => $unitOnly,
            'scope_differences' => $scopeDifferences,
        ];
    }

    private function normalizeUnit(mixed $unit): ?string
    {
        $normalized = mb_strtolower(trim((string) $unit));
        $normalized = str_replace(
            ['ı', 'ğ', 'ü', 'ş', 'ö', 'ç', 'İ', 'Ğ', 'Ü', 'Ş', 'Ö', 'Ç'],
            ['i', 'g', 'u', 's', 'o', 'c', 'i', 'g', 'u', 's', 'o', 'c'],
            $normalized
        );
        $normalized = preg_replace('/[^a-z0-9_ ]+/', ' ', $normalized) ?: $normalized;
        $normalized = preg_replace('/\s+/', ' ', trim($normalized)) ?: '';

        return $normalized === '' ? null : $normalized;
    }
}
