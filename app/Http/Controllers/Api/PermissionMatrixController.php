<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\RolePermissionScope;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionMatrixController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->abortUnlessGlobalPermission($request, 'permissions.matrix.view');

        [$roles, $permissions] = $this->ensureDefaults();
        $roleLabels = config('permission_catalog.role_labels', []);
        $permissionCatalog = config('permission_catalog.legacy_permissions', []);

        $userCounts = User::query()
            ->selectRaw('role, COUNT(*) as count')
            ->groupBy('role')
            ->pluck('count', 'role');

        $groupedPermissions = collect($permissionCatalog)
            ->groupBy('group')
            ->map(function ($items) use ($permissions) {
                return collect($items)->map(function (array $item) use ($permissions) {
                    $permission = $permissions->firstWhere('name', $item['name']);

                    return [
                        'name' => $item['name'],
                        'label' => $item['label'],
                        'group' => $item['group'],
                        'description' => $item['description'] ?? '',
                        'id' => $permission?->id,
                    ];
                })->values();
            })->toArray();

        $granularMatrixGroups = $this->buildGranularMatrixGroups($permissions);

        return response()->json([
            'roles' => $roles->map(function (Role $role) use ($userCounts, $roleLabels) {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'label' => $roleLabels[$role->name] ?? $role->name,
                    'user_count' => (int) ($userCounts[$role->name] ?? 0),
                    'permissions' => $role->permissions->pluck('name')->values(),
                    'granular_effective' => $this->granularEffectiveForRole($role),
                ];
            })->values(),
            'permission_groups' => $groupedPermissions,
            'granular_matrix_groups' => $granularMatrixGroups,
            'granular_permission_groups' => config('permission_catalog.granular_permissions', []),
            'role_permission_scopes' => $this->groupedRolePermissionScopes(),
            'role_scope_storage_ready' => Schema::hasTable('role_permission_scopes'),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->abortUnlessGlobalPermission($request, 'permissions.matrix.update');

        $validated = $request->validate([
            'matrix' => 'sometimes|array|min:1',
            'matrix.*.role' => 'required|string|exists:roles,name',
            'matrix.*.permissions' => 'array',
            'matrix.*.permissions.*' => 'string',
            'granular_matrix' => 'sometimes|array|min:1',
            'granular_matrix.*.role' => 'required|string|exists:roles,name',
            'granular_matrix.*.permissions' => 'array',
            'granular_matrix.*.permissions.*' => 'string',
            'granular_scopes' => 'sometimes|array',
            'granular_scopes.*.role' => 'required_with:granular_scopes|string|exists:roles,name',
            'granular_scopes.*.scopes' => 'array',
            'granular_scopes.*.scopes.*.permission_name' => 'required|string',
            'granular_scopes.*.scopes.*.scope_type' => 'required|in:all,own_projects,assigned_projects,own_unit,selected_projects,self,none',
            'granular_scopes.*.scopes.*.scope_payload' => 'nullable|array',
        ]);

        $hasGranular = ! empty($validated['granular_matrix'] ?? []);
        $hasLegacy = ! empty($validated['matrix'] ?? []);

        abort_unless($hasGranular xor $hasLegacy, 422, 'Yalnizca granular_matrix veya matrix (legacy) gonderin; ikisini birden gondermeyin.');

        [$roles, $permissions] = $this->ensureDefaults();
        $assignableNames = $permissions->pluck('name')->all();
        $granularCatalog = $this->allGranularPermissionNames();

        if (! empty($validated['granular_matrix'])) {
            foreach ($validated['granular_matrix'] as $row) {
                $role = $roles->firstWhere('name', $row['role']);

                if (! $role) {
                    continue;
                }

                if ($role->name === 'super_admin') {
                    $role->syncPermissions(Permission::query()->pluck('name')->all());
                    continue;
                }

                $allowed = collect($row['permissions'] ?? [])
                    ->filter(fn (string $name) => in_array($name, $granularCatalog, true))
                    ->values()
                    ->all();

                $role->syncPermissions($allowed);
            }

            if (! empty($validated['granular_scopes'])) {
                $this->syncGranularScopes($validated['granular_scopes'], $granularCatalog);
            }

            $this->logPermissionActivity(
                $request,
                null,
                'permission_granular_matrix.updated',
                [
                    'roles' => collect($validated['granular_matrix'])->map(fn (array $row) => [
                        'role' => $row['role'],
                        'granular_permission_count' => count($row['permissions'] ?? []),
                    ])->values()->all(),
                    'scopes' => collect($validated['granular_scopes'] ?? [])->map(fn (array $row) => [
                        'role' => $row['role'],
                        'scope_count' => count($row['scopes'] ?? []),
                    ])->values()->all(),
                ]
            );
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return response()->json([
                'message' => 'Granular yetki matrisi guncellendi.',
            ]);
        }

        foreach ($validated['matrix'] as $row) {
            $role = $roles->firstWhere('name', $row['role']);

            if (! $role) {
                continue;
            }

            if ($role->name === 'super_admin') {
                $role->syncPermissions(Permission::query()->pluck('name')->all());
                continue;
            }

            $allowedPermissions = collect($row['permissions'] ?? [])
                ->filter(fn (string $name) => in_array($name, $assignableNames, true))
                ->values()
                ->all();

            $role->syncPermissions($allowedPermissions);
        }

        $this->logPermissionActivity(
            $request,
            null,
            'permission_role_matrix.updated',
            [
                'roles' => collect($validated['matrix'])->map(fn (array $row) => [
                    'role' => $row['role'],
                    'permission_count' => count($row['permissions'] ?? []),
                ])->values()->all(),
            ]
        );
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json([
            'message' => 'Yetki matrisi guncellendi.',
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $this->abortUnlessGlobalPermission($request, 'permissions.user_override.view');

        $query = User::query()
            ->with(['roles:id,name', 'staffProfile:user_id,unit,title'])
            ->where('status', '!=', 'banned');

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('surname', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'users' => $query
                ->orderBy('role')
                ->orderBy('name')
                ->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'surname' => $user->surname,
                    'email' => $user->email,
                    'role' => $user->role,
                    'roles' => $user->roles->pluck('name')->values(),
                    'unit' => $user->staffProfile?->unit,
                    'title' => $user->staffProfile?->title,
                ])
                ->values(),
        ]);
    }

    public function showUserOverrides(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessGlobalPermission($request, 'permissions.user_override.view');

        $user = User::with(['roles:id,name', 'staffProfile:user_id,unit,title'])->findOrFail($id);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'surname' => $user->surname,
                'email' => $user->email,
                'role' => $user->role,
                'roles' => $user->roles->pluck('name')->values(),
                'unit' => $user->staffProfile?->unit,
                'title' => $user->staffProfile?->title,
            ],
            'overrides' => $user->permissionOverrides()
                ->orderBy('effect')
                ->orderBy('permission_name')
                ->get()
                ->map(fn (UserPermissionOverride $override) => [
                    'id' => $override->id,
                    'permission_name' => $override->permission_name,
                    'effect' => $override->effect,
                    'scope_type' => $override->scope_type,
                    'scope_payload' => $override->scope_payload ?? [],
                ])
                ->values(),
            'resolved' => $this->permissionResolver->resolve($user),
            'granular_permission_groups' => config('permission_catalog.granular_permissions', []),
        ]);
    }

    public function updateUserOverrides(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessGlobalPermission($request, 'permissions.user_override.update');

        $user = User::findOrFail($id);

        $allowedPermissions = collect(config('permission_catalog.granular_permissions', []))
            ->flatMap(fn (array $permissions) => $permissions)
            ->merge(array_keys(config('permission_catalog.legacy_map', [])))
            ->unique()
            ->values()
            ->all();

        $validated = $request->validate([
            'overrides' => 'present|array',
            'overrides.*.permission_name' => 'required|string',
            'overrides.*.effect' => 'required|in:allow,deny',
            'overrides.*.scope_type' => 'nullable|in:all,own_projects,assigned_projects,own_unit,selected_projects,self,none',
            'overrides.*.scope_payload' => 'nullable|array',
        ]);

        foreach ($validated['overrides'] as $override) {
            abort_unless(in_array($override['permission_name'], $allowedPermissions, true), 422, 'Gecersiz permission secildi.');
        }

        $user->permissionOverrides()->delete();

        foreach ($validated['overrides'] as $override) {
            $user->permissionOverrides()->create([
                'permission_name' => $override['permission_name'],
                'effect' => $override['effect'],
                'scope_type' => $override['scope_type'] ?? null,
                'scope_payload' => $override['scope_payload'] ?? [],
            ]);
        }

        $this->logPermissionActivity(
            $request,
            $user->fresh(),
            'user_permission_overrides.updated',
            [
                'target_user_id' => $user->id,
                'target_email' => $user->email,
                'override_count' => count($validated['overrides']),
                'overrides_snapshot' => collect($validated['overrides'])->map(fn (array $o) => [
                    'permission_name' => $o['permission_name'],
                    'effect' => $o['effect'],
                    'scope_type' => $o['scope_type'] ?? null,
                ])->values()->all(),
            ]
        );
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json([
            'message' => 'Kullaniciya ozel yetkiler guncellendi.',
            'resolved' => $this->permissionResolver->resolve($user->fresh()),
        ]);
    }

    public function roleCatalog(Request $request): JsonResponse
    {
        $this->abortUnlessGlobalPermission($request, 'permissions.matrix.view');

        $roles = Role::query()
            ->with('permissions:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => config('permission_catalog.role_labels.' . $role->name) ?? Str::headline($role->name),
                'is_system' => $this->isSystemRole($role->name),
                'permission_count' => $role->permissions->count(),
                'permissions' => $role->permissions->pluck('name')->values(),
                'user_count' => User::query()->role($role->name)->count(),
            ])
            ->values();

        return response()->json([
            'roles' => $roles,
            'scope_templates' => config('permission_catalog.scope_templates', []),
        ]);
    }

    public function createRole(Request $request): JsonResponse
    {
        $this->abortUnlessGlobalPermission($request, 'permissions.matrix.update');
        $request->merge([
            'name' => $this->normalizeRoleName((string) $request->input('name', '')),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_\\-]+$/', 'unique:roles,name'],
            'permissions' => 'nullable|array',
            'permissions.*' => 'string',
        ]);

        abort_if($this->isSystemRole($validated['name']), 422, 'Bu isim sistem rolu olarak ayrildi.');

        $role = Role::create([
            'name' => $validated['name'],
            'guard_name' => 'web',
        ]);

        if (! empty($validated['permissions'])) {
            $role->syncPermissions($this->validGranularPermissions($validated['permissions']));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->logPermissionActivity($request, null, 'role.custom.created', [
            'role' => $role->name,
            'permission_count' => count($validated['permissions'] ?? []),
        ]);

        return response()->json([
            'message' => 'Ozel rol olusturuldu.',
            'role' => $role->load('permissions:id,name'),
        ], 201);
    }

    public function updateRole(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessGlobalPermission($request, 'permissions.matrix.update');

        $role = Role::query()->findOrFail($id);
        abort_if($this->isSystemRole($role->name), 422, 'Sistem rolleri buradan degistirilemez.');

        $validated = $request->validate([
            'permissions' => 'nullable|array',
            'permissions.*' => 'string',
            'scopes' => 'nullable|array',
            'scopes.*.permission_name' => 'required_with:scopes|string',
            'scopes.*.scope_type' => 'required_with:scopes|in:all,own_projects,assigned_projects,own_unit,selected_projects,self,none',
            'scopes.*.scope_payload' => 'nullable|array',
        ]);

        $role->syncPermissions($this->validGranularPermissions($validated['permissions'] ?? []));
        if (array_key_exists('scopes', $validated)) {
            $this->syncGranularScopes([[
                'role' => $role->name,
                'scopes' => $validated['scopes'] ?? [],
            ]], $this->allGranularPermissionNames());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->logPermissionActivity($request, null, 'role.custom.updated', [
            'role' => $role->name,
            'permission_count' => count($validated['permissions'] ?? []),
        ]);

        return response()->json([
            'message' => 'Rol yetkileri guncellendi.',
            'role' => $role->load('permissions:id,name'),
        ]);
    }

    public function deleteRole(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessGlobalPermission($request, 'permissions.matrix.update');

        $role = Role::query()->findOrFail($id);
        abort_if($this->isSystemRole($role->name), 422, 'Sistem rolleri silinemez.');

        $assignedCount = User::query()->role($role->name)->count();
        abort_if($assignedCount > 0, 422, 'Bu role atanmis kullanicilar var. Once atamalari kaldirin.');

        $name = $role->name;
        $role->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->logPermissionActivity($request, null, 'role.custom.deleted', ['role' => $name]);

        return response()->json(['message' => 'Rol silindi.']);
    }

    public function assignUserRoles(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessGlobalPermission($request, 'permissions.user_override.update');

        $user = User::query()->with('roles:id,name')->findOrFail($id);
        $validated = $request->validate([
            'roles' => 'required|array|min:1',
            'roles.*' => 'required|string|exists:roles,name',
            'primary_role' => 'nullable|string',
        ]);

        $roleNames = collect($validated['roles'])->unique()->values()->all();
        $user->syncRoles($roleNames);

        $primaryRole = $validated['primary_role'] ?? $roleNames[0];
        if (in_array($primaryRole, $roleNames, true)) {
            try {
                $user->forceFill(['role' => $primaryRole])->save();
            } catch (QueryException) {
                // Eski enum role kolonuna sahip ortamlarda Spatie rol atamasi yine gecerlidir.
                $user->refresh();
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->logPermissionActivity($request, $user, 'user_roles.updated', [
            'target_user_id' => $user->id,
            'roles' => $roleNames,
            'primary_role' => $user->role,
        ]);

        return response()->json([
            'message' => 'Kullanici rolleri guncellendi.',
            'user' => [
                'id' => $user->id,
                'role' => $user->role,
                'roles' => $user->fresh('roles')->roles->pluck('name')->values(),
            ],
            'resolved' => $this->permissionResolver->resolve($user->fresh()),
        ]);
    }

    /**
     * Son rol matrisi ve kullanici override degisiklikleri (Spatie activity_log, log_name=permissions).
     */
    public function audit(Request $request): JsonResponse
    {
        abort_unless(
            $this->hasGlobalPermission($request, 'permissions.matrix.view')
                || $this->hasGlobalPermission($request, 'logs.view'),
            403,
            'Bu global kayitlari goruntuleme yetkiniz bulunmuyor.'
        );

        try {
            $logs = Activity::query()
                ->where('log_name', 'permissions')
                ->with('causer:id,name,surname,role')
                ->latest()
                ->take(50)
                ->get();

            return response()->json([
                'logs' => $logs->map(function (Activity $log) {
                    $props = $log->properties;
                    if ($props instanceof \Illuminate\Support\Collection) {
                        $props = $props->toArray();
                    }

                    return [
                        'id' => $log->id,
                        'description' => $log->description,
                        'created_at' => $log->created_at?->toIso8601String(),
                        'causer' => $log->causer ? [
                            'id' => $log->causer->id,
                            'name' => trim($log->causer->name.' '.$log->causer->surname),
                            'role' => $log->causer->role,
                        ] : null,
                        'subject_id' => $log->subject_id,
                        'subject_type' => $log->subject_type ? class_basename((string) $log->subject_type) : null,
                        'properties' => is_array($props) ? $props : [],
                    ];
                })->values(),
            ]);
        } catch (\Throwable) {
            return response()->json(['logs' => [], 'warning' => 'Activity log okunamadi.']);
        }
    }

    private function logPermissionActivity(Request $request, ?User $subject, string $description, array $properties): void
    {
        $actor = $request->user();
        if (! $actor) {
            return;
        }

        try {
            $logger = activity()
                ->useLog('permissions')
                ->causedBy($actor)
                ->withProperties($properties);

            if ($subject !== null) {
                $logger->performedOn($subject);
            }

            $logger->log($description);
        } catch (\Throwable) {
            // activity_log tablosu veya paket yoksa panel islemi yine tamamlanir
        }
    }

    private function abortUnlessGlobalPermission(Request $request, string $permission): void
    {
        $this->abortUnlessAllowed($request, $permission);

        abort_unless(
            $this->permissionResolver->hasGlobalScope($request->user(), $permission),
            403,
            'Bu islem icin tum sistem kapsami gerekir.'
        );
    }

    private function hasGlobalPermission(Request $request, string $permission): bool
    {
        $user = $request->user();

        return $user !== null
            && $this->permissionResolver->hasPermission($user, $permission)
            && $this->permissionResolver->hasGlobalScope($user, $permission);
    }

    private function ensureDefaults(): array
    {
        $permissionCatalog = config('permission_catalog.legacy_permissions', []);
        $roleLabels = config('permission_catalog.role_labels', []);
        $defaultRolePermissions = config('permission_catalog.default_role_permissions', []);
        $legacyMap = config('permission_catalog.legacy_map', []);

        foreach ($permissionCatalog as $permissionDefinition) {
            Permission::findOrCreate($permissionDefinition['name'], 'web');
        }

        foreach ($this->allGranularPermissionNames() as $granularName) {
            Permission::findOrCreate($granularName, 'web');
        }

        foreach (array_keys($roleLabels) as $roleName) {
            $role = Role::findOrCreate($roleName, 'web');

            $defaultPermissions = $defaultRolePermissions[$roleName] ?? [];

            if ($role->permissions()->count() === 0) {
                if ($defaultPermissions === '*') {
                    $role->syncPermissions(Permission::query()->pluck('name')->all());
                } else {
                    $expanded = collect($defaultPermissions)
                        ->flatMap(function (string $name) use ($legacyMap) {
                            return $legacyMap[$name] ?? [$name];
                        })
                        ->unique()
                        ->values()
                        ->all();

                    $role->syncPermissions($expanded);
                }
            }
        }

        $systemRoleOrder = array_keys($roleLabels);
        $roles = Role::query()
            ->with('permissions:id,name')
            ->get()
            ->sortBy(function (Role $role) use ($systemRoleOrder) {
                $pos = array_search($role->name, $systemRoleOrder, true);
                if ($pos === false) {
                    return 999 + crc32($role->name);
                }

                return $pos;
            })
            ->values();

        $allAssignableNames = collect($permissionCatalog)
            ->pluck('name')
            ->merge($this->allGranularPermissionNames())
            ->unique()
            ->values()
            ->all();

        $permissions = Permission::query()
            ->whereIn('name', $allAssignableNames)
            ->orderBy('name')
            ->get(['id', 'name']);

        return [$roles, $permissions];
    }

    private function isSystemRole(string $roleName): bool
    {
        return array_key_exists($roleName, config('permission_catalog.role_labels', []));
    }

    private function normalizeRoleName(string $name): string
    {
        return Str::of($name)
            ->trim()
            ->lower()
            ->slug('_')
            ->trim('_')
            ->toString();
    }

    private function validGranularPermissions(array $permissions): array
    {
        $granularCatalog = $this->allGranularPermissionNames();

        return collect($permissions)
            ->filter(fn (string $name) => in_array($name, $granularCatalog, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function allGranularPermissionNames(): array
    {
        return collect(config('permission_catalog.granular_permissions', []))
            ->flatMap(fn (array $names) => $names)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param \Illuminate\Support\Collection<int, Permission> $permissions
     * @return array<string, array<int, array{name: string, label: string, group: string, description: string, id: int|null}>>
     */
    private function buildGranularMatrixGroups($permissions): array
    {
        $catalog = config('permission_catalog.granular_permissions', []);
        $out = [];
        foreach ($catalog as $groupName => $permNames) {
            $out[$groupName] = collect($permNames)->map(function (string $name) use ($permissions, $groupName) {
                $permission = $permissions->firstWhere('name', $name);

                return [
                    'name' => $name,
                    'label' => $name,
                    'group' => $groupName,
                    'description' => '',
                    'id' => $permission?->id,
                ];
            })->values()->all();
        }

        return $out;
    }

    /**
     * Rolde kayitli legacy + granular isimlerinden efektif granular liste (legacy_map genisletmesi).
     */
    private function granularEffectiveForRole(Role $role): array
    {
        $legacyMap = config('permission_catalog.legacy_map', []);

        return $role->permissions
            ->pluck('name')
            ->flatMap(function (string $name) use ($legacyMap) {
                return $legacyMap[$name] ?? [$name];
            })
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function groupedRolePermissionScopes(): array
    {
        if (! Schema::hasTable('role_permission_scopes')) {
            return [];
        }

        return RolePermissionScope::query()
            ->get(['role_name', 'permission_name', 'scope_type', 'scope_payload'])
            ->groupBy('role_name')
            ->map(function ($items) {
                return $items->mapWithKeys(function (RolePermissionScope $item) {
                    return [
                        $item->permission_name => [
                            'scope_type' => $item->scope_type,
                            'scope_payload' => $item->scope_payload ?? [],
                        ],
                    ];
                })->all();
            })
            ->all();
    }

    private function syncGranularScopes(array $rows, array $granularCatalog): void
    {
        if (! Schema::hasTable('role_permission_scopes')) {
            return;
        }

        $known = collect($rows)->pluck('role')->unique()->values()->all();
        if (! empty($known)) {
            RolePermissionScope::query()->whereIn('role_name', $known)->delete();
        }

        foreach ($rows as $row) {
            $roleName = $row['role'] ?? null;
            if (! is_string($roleName) || $roleName === '') {
                continue;
            }

            foreach (($row['scopes'] ?? []) as $scopeRow) {
                $permissionName = $scopeRow['permission_name'] ?? null;
                $scopeType = $scopeRow['scope_type'] ?? null;
                if (! is_string($permissionName) || ! in_array($permissionName, $granularCatalog, true)) {
                    continue;
                }
                if (! is_string($scopeType)) {
                    continue;
                }

                RolePermissionScope::query()->create([
                    'role_name' => $roleName,
                    'permission_name' => $permissionName,
                    'scope_type' => $scopeType,
                    'scope_payload' => $scopeRow['scope_payload'] ?? [],
                ]);
            }
        }
    }
}
