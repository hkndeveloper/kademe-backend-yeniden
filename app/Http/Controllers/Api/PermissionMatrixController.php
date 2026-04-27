<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;
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
        $this->abortUnlessAllowed($request, 'permissions.matrix.view');

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
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'permissions.matrix.update');

        $validated = $request->validate([
            'matrix' => 'sometimes|array|min:1',
            'matrix.*.role' => 'required|string|in:super_admin,coordinator,staff,student,alumni,visitor',
            'matrix.*.permissions' => 'array',
            'matrix.*.permissions.*' => 'string',
            'granular_matrix' => 'sometimes|array|min:1',
            'granular_matrix.*.role' => 'required|string|in:super_admin,coordinator,staff,student,alumni,visitor',
            'granular_matrix.*.permissions' => 'array',
            'granular_matrix.*.permissions.*' => 'string',
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

            $this->logPermissionActivity(
                $request,
                null,
                'permission_granular_matrix.updated',
                [
                    'roles' => collect($validated['granular_matrix'])->map(fn (array $row) => [
                        'role' => $row['role'],
                        'granular_permission_count' => count($row['permissions'] ?? []),
                    ])->values()->all(),
                ]
            );

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

        return response()->json([
            'message' => 'Yetki matrisi guncellendi.',
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'permissions.user_override.view');

        $query = User::query()
            ->with(['roles:id,name', 'staffProfile:user_id,unit,title'])
            ->whereIn('role', ['super_admin', 'coordinator', 'staff']);

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
        $this->abortUnlessAllowed($request, 'permissions.user_override.view');

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
        $this->abortUnlessAllowed($request, 'permissions.user_override.update');

        $user = User::findOrFail($id);

        $allowedPermissions = collect(config('permission_catalog.granular_permissions', []))
            ->flatMap(fn (array $permissions) => $permissions)
            ->values()
            ->all();

        $validated = $request->validate([
            'overrides' => 'required|array',
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

        return response()->json([
            'message' => 'Kullaniciya ozel yetkiler guncellendi.',
            'resolved' => $this->permissionResolver->resolve($user->fresh()),
        ]);
    }

    /**
     * Son rol matrisi ve kullanici override degisiklikleri (Spatie activity_log, log_name=permissions).
     */
    public function audit(Request $request): JsonResponse
    {
        abort_unless($request->user()?->role === 'super_admin', 403, 'Bu kayitlar yalnizca ust admin icindir.');

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

        $roles = Role::query()
            ->whereIn('name', array_keys($roleLabels))
            ->with('permissions:id,name')
            ->get()
            ->sortBy(fn (Role $role) => array_search($role->name, array_keys($roleLabels), true))
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
}
