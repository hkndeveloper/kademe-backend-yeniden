<?php

namespace App\Services;

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitPermissionRule;
use App\Support\AuthorizationManagementCatalog;
use App\Support\CoordinationUnitExclusivePermissionCatalog;
use App\Support\CoordinationUnitPermissionTemplateCatalog;
use App\Support\ProjectUnitPermissionTemplateCatalog;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class CoordinationUnitPermissionRuleSyncService
{
    /**
     * Admin proje metadata'sini degistirdiginde yalniz o birimin family
     * izinlerini yeni metadata ile uzlastirir. Ortak cekirdek ve admin scope
     * ozellestirmelerine dokunmaz.
     */
    public function reconcileProjectFamily(CoordinationUnit $unit): int
    {
        if ($unit->kind !== CoordinationUnit::KIND_PROJECT || ! $unit->project()->exists()) {
            return 0;
        }

        $unit->load('project');
        $familyUniverse = ProjectUnitPermissionTemplateCatalog::permissionUniverse();
        $expected = collect(CoordinationUnitPermissionTemplateCatalog::rulesFor($unit))
            ->filter(fn (array $rule) => in_array($rule['permission_name'], $familyUniverse, true))
            ->keyBy(fn (array $rule) => $this->ruleKey($rule['position'], $rule['permission_name']));

        return DB::transaction(function () use ($unit, $familyUniverse, $expected): int {
            $active = CoordinationUnitPermissionRule::query()
                ->where('unit_id', $unit->id)
                ->where('status', CoordinationUnitPermissionRule::STATUS_ACTIVE)
                ->whereIn('permission_name', $familyUniverse)
                ->get()
                ->keyBy(fn (CoordinationUnitPermissionRule $rule) => $this->ruleKey($rule->position, $rule->permission_name));
            $changes = 0;

            foreach ($active as $key => $rule) {
                if ($expected->has($key)) {
                    continue;
                }

                $rule->update([
                    'status' => CoordinationUnitPermissionRule::STATUS_PASSIVE,
                    'ends_at' => now(),
                ]);
                $changes++;
            }

            foreach ($expected as $key => $rule) {
                if ($active->has($key)) {
                    continue;
                }

                CoordinationUnitPermissionRule::query()->create([
                    'unit_id' => (int) $unit->id,
                    ...$rule,
                    'effect' => CoordinationUnitPermissionRule::EFFECT_ALLOW,
                    'scope_payload' => null,
                    'status' => CoordinationUnitPermissionRule::STATUS_ACTIVE,
                ]);
                $changes++;
            }

            return $changes;
        });
    }

    /**
     * Varsayilan calisma yalniz hic kural almamis birimleri bootstrap eder ve
     * proje metadata'sina uymayan eski family kurallarini pasiflestirir.
     * Eksik/pasif/ozellestirilmis kurallar admin karari kabul edilerek korunur.
     */
    public function execute(bool $apply, bool $resetDefaults = false): array
    {
        $plan = $this->plan($resetDefaults);

        if (! $apply || $plan['blockers'] !== []) {
            return $this->report($apply, $resetDefaults, $plan, 0);
        }

        $applied = DB::transaction(function () use ($plan): int {
            $changeCount = 0;

            foreach ($plan['rules_to_deactivate'] as $rule) {
                $changeCount += CoordinationUnitPermissionRule::query()
                    ->whereKey($rule['rule_id'])
                    ->where('status', CoordinationUnitPermissionRule::STATUS_ACTIVE)
                    ->update([
                        'status' => CoordinationUnitPermissionRule::STATUS_PASSIVE,
                        'ends_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            foreach ($plan['rules_to_update'] as $rule) {
                $changeCount += CoordinationUnitPermissionRule::query()
                    ->whereKey($rule['rule_id'])
                    ->update([
                        'effect' => CoordinationUnitPermissionRule::EFFECT_ALLOW,
                        'scope_source' => $rule['scope_source'],
                        'service_domain' => $rule['service_domain'],
                        'scope_payload' => null,
                        'starts_at' => null,
                        'ends_at' => null,
                        'updated_at' => now(),
                    ]);
            }

            foreach ($plan['rules_to_create'] as $rule) {
                CoordinationUnitPermissionRule::query()->create([
                    ...$rule,
                    'effect' => CoordinationUnitPermissionRule::EFFECT_ALLOW,
                    'scope_payload' => null,
                    'status' => CoordinationUnitPermissionRule::STATUS_ACTIVE,
                ]);
                $changeCount++;
            }

            return $changeCount;
        });

        $verification = $this->plan($resetDefaults);
        $remainingChanges = $this->proposedChangeCount($verification);
        $report = $this->report(true, $resetDefaults, $plan, $applied);
        $report['verification'] = [
            'healthy' => $verification['blockers'] === [],
            'idempotent' => $remainingChanges === 0,
            'remaining_change_count' => $remainingChanges,
            'blocker_count' => count($verification['blockers']),
            'preserved_customization_count' => count($verification['preserved_customizations']),
        ];

        return $report;
    }

    private function plan(bool $resetDefaults): array
    {
        $rulesToCreate = [];
        $rulesToUpdate = [];
        $rulesToDeactivate = [];
        $preservedCustomizations = [];
        $blockers = [];
        $templatedUnitCount = 0;
        $initializedUnitCount = 0;
        $targetDefaultRuleCount = 0;
        $activeTargetRuleCount = 0;
        $activeExactTargetRuleCount = 0;
        $activeAdditionalRuleCount = 0;
        $familyPermissionUniverse = ProjectUnitPermissionTemplateCatalog::permissionUniverse();
        $transitionDefaultPermissions = CoordinationUnitExclusivePermissionCatalog::transitionDefaultPermissions();

        foreach (CoordinationUnit::query()->active()->with('project')->orderBy('id')->get() as $unit) {
            $expectedRules = CoordinationUnitPermissionTemplateCatalog::rulesFor($unit);
            if ($expectedRules === []) {
                continue;
            }
            $templatedUnitCount++;
            $targetDefaultRuleCount += count($expectedRules);

            $existingRules = CoordinationUnitPermissionRule::withTrashed()
                ->where('unit_id', $unit->id)
                ->orderBy('id')
                ->get();
            $initialized = $existingRules->isNotEmpty();
            if ($initialized) {
                $initializedUnitCount++;
            }

            $activeRules = $existingRules
                ->filter(fn (CoordinationUnitPermissionRule $rule) => ! $rule->trashed()
                    && $rule->status === CoordinationUnitPermissionRule::STATUS_ACTIVE)
                ->keyBy(fn (CoordinationUnitPermissionRule $rule) => $this->ruleKey($rule->position, $rule->permission_name));
            $expectedByKey = collect($expectedRules)
                ->keyBy(fn (array $rule) => $this->ruleKey($rule['position'], $rule['permission_name']));

            foreach ($expectedRules as $expected) {
                $key = $this->ruleKey($expected['position'], $expected['permission_name']);
                /** @var CoordinationUnitPermissionRule|null $active */
                $active = $activeRules->get($key);

                if (! $active) {
                    $hasHistoricalRule = $existingRules->contains(
                        fn (CoordinationUnitPermissionRule $rule) => $this->ruleKey($rule->position, $rule->permission_name) === $key
                    );
                    $isNewProjectFamilyDefault = $unit->kind === CoordinationUnit::KIND_PROJECT
                        && in_array($expected['permission_name'], $familyPermissionUniverse, true)
                        && ! $hasHistoricalRule;
                    $isNewTransitionDefault = in_array($expected['permission_name'], $transitionDefaultPermissions, true)
                        && ! $hasHistoricalRule;

                    if (! $initialized || $resetDefaults || $isNewProjectFamilyDefault || $isNewTransitionDefault) {
                        $rulesToCreate[] = ['unit_id' => (int) $unit->id, ...$expected];
                    } else {
                        $preservedCustomizations[] = [
                            'type' => 'missing_or_inactive_default_preserved',
                            'unit_id' => (int) $unit->id,
                            'position' => $expected['position'],
                            'permission_name' => $expected['permission_name'],
                        ];
                    }

                    continue;
                }

                if (! $this->matchesTemplate($active, $expected)) {
                    $isKnownYf3ScopeMigration = $unit->code === 'service_community_culture'
                        && str_starts_with($expected['permission_name'], 'motivation.')
                        && $active->effect === CoordinationUnitPermissionRule::EFFECT_ALLOW
                        && $active->scope_source === CoordinationUnitPermissionRule::SCOPE_RESPONSIBILITY_PROJECTS
                        && $active->service_domain === 'community_culture'
                        && empty($active->scope_payload)
                        && $active->starts_at === null
                        && $active->ends_at === null
                        && $expected['scope_source'] === CoordinationUnitPermissionRule::SCOPE_ALL;

                    if ($resetDefaults || $isKnownYf3ScopeMigration) {
                        $rulesToUpdate[] = [
                            'rule_id' => (int) $active->id,
                            'unit_id' => (int) $unit->id,
                            ...$expected,
                        ];
                    } else {
                        $preservedCustomizations[] = [
                            'type' => 'customized_default_preserved',
                            'unit_id' => (int) $unit->id,
                            'position' => $expected['position'],
                            'permission_name' => $expected['permission_name'],
                            'rule_id' => (int) $active->id,
                        ];
                    }
                } else {
                    $activeExactTargetRuleCount++;
                }

                $activeTargetRuleCount++;
            }

            foreach ($activeRules as $key => $activeRule) {
                $isWrongExclusiveOwner = ! CoordinationUnitExclusivePermissionCatalog::isOwnedBy(
                    $activeRule->permission_name,
                    $unit->code
                );
                $isInapplicableProjectFamily = $unit->kind === CoordinationUnit::KIND_PROJECT
                    && in_array($activeRule->permission_name, $familyPermissionUniverse, true)
                    && ! $expectedByKey->has($key);

                if (! $isWrongExclusiveOwner && ! $isInapplicableProjectFamily) {
                    if (! $expectedByKey->has($key)) {
                        $activeAdditionalRuleCount++;
                    }

                    continue;
                }

                $rulesToDeactivate[] = [
                    'rule_id' => (int) $activeRule->id,
                    'unit_id' => (int) $unit->id,
                    'position' => $activeRule->position,
                    'permission_name' => $activeRule->permission_name,
                    'reason' => $isWrongExclusiveOwner
                        ? 'exclusive_service_domain_not_owned'
                        : 'project_special_module_not_applicable',
                ];
            }
        }

        return [
            'templated_unit_count' => $templatedUnitCount,
            'initialized_unit_count' => $initializedUnitCount,
            'active_rule_count' => CoordinationUnitPermissionRule::query()->active()->count(),
            'historical_rule_count' => CoordinationUnitPermissionRule::withTrashed()->count(),
            'target_default_rule_count' => $targetDefaultRuleCount,
            'active_target_rule_count' => $activeTargetRuleCount,
            'active_exact_target_rule_count' => $activeExactTargetRuleCount,
            'active_additional_rule_count' => $activeAdditionalRuleCount,
            'rules_to_create' => $rulesToCreate,
            'rules_to_update' => $rulesToUpdate,
            'rules_to_deactivate' => $rulesToDeactivate,
            'preserved_customizations' => $preservedCustomizations,
            'blockers' => $blockers,
        ];
    }

    private function matchesTemplate(CoordinationUnitPermissionRule $rule, array $expected): bool
    {
        return $rule->effect === CoordinationUnitPermissionRule::EFFECT_ALLOW
            && $rule->scope_source === $expected['scope_source']
            && $rule->service_domain === $expected['service_domain']
            && empty($rule->scope_payload)
            && $rule->starts_at === null
            && $rule->ends_at === null;
    }

    private function ruleKey(string $position, string $permissionName): string
    {
        return $position.'|'.$permissionName;
    }

    private function proposedChangeCount(array $plan): int
    {
        return count($plan['rules_to_create'])
            + count($plan['rules_to_update'])
            + count($plan['rules_to_deactivate']);
    }

    private function report(bool $apply, bool $resetDefaults, array $plan, int $applied): array
    {
        return [
            'meta' => [
                'mode' => $apply ? 'apply' : 'dry-run',
                'template_policy' => $resetDefaults ? 'explicit-reset-defaults' : 'bootstrap-preserve-admin-decisions',
                'deletion_policy' => 'Kayit silinmez. Uygulanamaz proje-family ve yanlis hizmet sahibi kurallari pasiflestirilir.',
            ],
            'summary' => [
                'templated_unit_count' => $plan['templated_unit_count'],
                'initialized_unit_count' => $plan['initialized_unit_count'],
                'active_rule_count' => $plan['active_rule_count'],
                'historical_rule_count' => $plan['historical_rule_count'],
                'target_default_rule_count' => $plan['target_default_rule_count'],
                'active_target_rule_count' => $plan['active_target_rule_count'],
                'active_exact_target_rule_count' => $plan['active_exact_target_rule_count'],
                'active_additional_rule_count' => $plan['active_additional_rule_count'],
                'proposed_change_count' => $this->proposedChangeCount($plan),
                'create_count' => count($plan['rules_to_create']),
                'update_count' => count($plan['rules_to_update']),
                'deactivate_count' => count($plan['rules_to_deactivate']),
                'preserved_customization_count' => count($plan['preserved_customizations']),
                'applied_change_count' => $applied,
                'blocker_count' => count($plan['blockers']),
                'can_apply' => $plan['blockers'] === [],
            ],
            'changes' => [
                'create' => $plan['rules_to_create'],
                'update' => $plan['rules_to_update'],
                'deactivate' => $plan['rules_to_deactivate'],
            ],
            'preserved_customizations' => $plan['preserved_customizations'],
            'legacy_global_role_permissions' => $this->legacyGlobalRolePermissions(),
            'rollback' => [
                'deactivated_rule_ids' => collect($plan['rules_to_deactivate'])
                    ->pluck('rule_id')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all(),
                'policy' => 'Apply sonrasi bu ID listesi saklanir; rollback ayri onayla satirlari yeniden active yapar, kayit silmez.',
            ],
            'blockers' => $plan['blockers'],
        ];
    }

    private function legacyGlobalRolePermissions(): array
    {
        return collect(AuthorizationManagementCatalog::protectedAuthorityRoles())
            ->mapWithKeys(function (string $roleName) {
                $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
                $permissionNames = $role
                    ? $role->permissions->pluck('name')
                        ->filter(fn (string $permission) => AuthorizationManagementCatalog::isUnitBusinessPermission($permission))
                        ->sort()
                        ->values()
                        ->all()
                    : [];

                return [$roleName => [
                    'classification' => 'legacy_only_in_enforce',
                    'count' => count($permissionNames),
                    'permission_names' => $permissionNames,
                ]];
            })
            ->all();
    }
}
