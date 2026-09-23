<?php

namespace App\Services;

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitMembership;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class CoordinationCutoverReadinessService
{
    private const REQUIRED_TABLES = [
        'coordination_units',
        'coordination_unit_memberships',
        'coordination_unit_permission_rules',
        'coordination_unit_project_responsibilities',
    ];

    public function __construct(
        private readonly CoordinationUnitBackfillService $backfillService,
        private readonly CoordinationUnitPermissionRuleSyncService $permissionRuleSyncService,
        private readonly CoordinationAuthorizationInvariantService $invariantService
    ) {}

    /**
     * Read-only cutover audit. This method never creates units, memberships or rules.
     *
     * @param  list<int>  $requestedUserIds
     */
    public function inspect(string $target = 'shadow', array $requestedUserIds = []): array
    {
        $target = in_array($target, ['shadow', 'pilot', 'enforce'], true) ? $target : 'shadow';
        $missingTables = collect(self::REQUIRED_TABLES)
            ->reject(fn (string $table) => Schema::hasTable($table))
            ->values()
            ->all();
        $schemaReady = $missingTables === [];
        $backfill = $schemaReady ? $this->backfillService->execute(false) : null;
        $permissionSync = $schemaReady ? $this->permissionRuleSyncService->execute(false) : null;
        $invariants = $schemaReady ? $this->invariantService->inspect() : null;

        $technicalBlockers = [];
        if (! $schemaReady) {
            $technicalBlockers[] = ['code' => 'missing_tables', 'items' => $missingTables];
        }
        if (($backfill['summary']['blocker_count'] ?? 0) > 0) {
            $technicalBlockers[] = ['code' => 'unit_backfill_conflicts', 'count' => $backfill['summary']['blocker_count']];
        }
        if (($backfill['summary']['proposed_change_count'] ?? 0) > 0) {
            $technicalBlockers[] = ['code' => 'unit_backfill_not_applied', 'count' => $backfill['summary']['proposed_change_count']];
        }
        if (($permissionSync['summary']['blocker_count'] ?? 0) > 0) {
            $technicalBlockers[] = ['code' => 'permission_rule_conflicts', 'count' => $permissionSync['summary']['blocker_count']];
        }
        if (($invariants['summary']['blocker_count'] ?? 0) > 0) {
            $technicalBlockers[] = [
                'code' => 'coordination_authorization_invariant_violations',
                'count' => $invariants['summary']['blocker_count'],
            ];
        }

        $authorityUsers = User::query()
            ->whereIn('role', ['coordinator', 'staff'])
            ->where('status', 'active')
            ->orderBy('id')
            ->get(['id', 'name', 'surname', 'email', 'role']);
        $configuredPilotIds = collect(config('coordination_authorization.pilot_user_ids', []))
            ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $requestedIds = collect($requestedUserIds)
            ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $pilotIds = $requestedIds->isNotEmpty() ? $requestedIds : $configuredPilotIds;
        $evaluatedUsers = $target === 'pilot'
            ? $authorityUsers->whereIn('id', $pilotIds->all())->values()
            : $authorityUsers;
        $unknownPilotIds = $target === 'pilot'
            ? $pilotIds->diff($authorityUsers->pluck('id')->map(fn ($id) => (int) $id))->values()->all()
            : [];

        $memberships = $schemaReady
            ? CoordinationUnitMembership::query()
                ->active()
                ->whereIn('user_id', $evaluatedUsers->pluck('id'))
                ->with('unit:id,code,name,status')
                ->get()
                ->filter(fn (CoordinationUnitMembership $membership) => $membership->unit?->status === CoordinationUnit::STATUS_ACTIVE)
                ->values()
            : collect();
        $membershipsByUser = $memberships->groupBy('user_id');
        $usersWithoutMembership = $evaluatedUsers
            ->filter(fn (User $user) => $membershipsByUser->get($user->id, collect())->isEmpty())
            ->map(fn (User $user) => $this->userPayload($user))
            ->values();
        $usersWithoutSinglePrimary = $evaluatedUsers
            ->filter(function (User $user) use ($membershipsByUser) {
                $userMemberships = $membershipsByUser->get($user->id, collect());

                return $userMemberships->isNotEmpty() && $userMemberships->where('is_primary', true)->count() !== 1;
            })
            ->map(fn (User $user) => $this->userPayload($user))
            ->values();

        $activeUnits = $schemaReady ? CoordinationUnit::query()->active()->orderBy('id')->get(['id', 'code', 'name']) : collect();
        $coordinatorUnitIds = $schemaReady
            ? CoordinationUnitMembership::query()
                ->active()
                ->where('position', CoordinationUnitMembership::POSITION_COORDINATOR)
                ->whereIn('unit_id', $activeUnits->pluck('id'))
                ->pluck('unit_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
            : collect();
        $unitsWithoutCoordinator = $activeUnits
            ->reject(fn (CoordinationUnit $unit) => $coordinatorUnitIds->contains((int) $unit->id))
            ->map(fn (CoordinationUnit $unit) => ['id' => (int) $unit->id, 'code' => $unit->code, 'name' => $unit->name])
            ->values();
        $evaluatedUnitIds = $memberships->pluck('unit_id')->map(fn ($id) => (int) $id)->unique();
        $evaluatedUnitsWithoutCoordinator = $unitsWithoutCoordinator
            ->whereIn('id', $evaluatedUnitIds->all())
            ->values();

        $dataBlockers = [];
        if ($unknownPilotIds !== []) {
            $dataBlockers[] = ['code' => 'unknown_pilot_users', 'user_ids' => $unknownPilotIds];
        }
        if ($evaluatedUsers->isEmpty()) {
            $dataBlockers[] = ['code' => 'no_authority_users_for_target'];
        }
        if ($usersWithoutMembership->isNotEmpty()) {
            $dataBlockers[] = ['code' => 'users_without_active_membership', 'count' => $usersWithoutMembership->count()];
        }
        if ($usersWithoutSinglePrimary->isNotEmpty()) {
            $dataBlockers[] = ['code' => 'users_without_single_primary_membership', 'count' => $usersWithoutSinglePrimary->count()];
        }
        if ($evaluatedUnitsWithoutCoordinator->isNotEmpty()) {
            $dataBlockers[] = ['code' => 'evaluated_units_without_coordinator', 'count' => $evaluatedUnitsWithoutCoordinator->count()];
        }

        $technicalReady = $technicalBlockers === [];
        $membershipReady = $dataBlockers === [];
        $requestedTargetReady = $target === 'shadow'
            ? $technicalReady
            : $technicalReady && $membershipReady;

        return [
            'meta' => [
                'audit_mode' => 'read_only',
                'configured_authorization_mode' => config('coordination_authorization.mode', 'legacy'),
                'requested_target' => $target,
                'membership_source' => 'coordination_unit_memberships',
                'technical_readiness_policy' => 'security-invariants-not-template-equality',
            ],
            'summary' => [
                'schema_ready' => $schemaReady,
                'technical_ready' => $technicalReady,
                'membership_data_ready' => $membershipReady,
                'requested_target_ready' => $requestedTargetReady,
                'active_authority_user_count' => $authorityUsers->count(),
                'evaluated_user_count' => $evaluatedUsers->count(),
                'active_membership_count' => $memberships->count(),
                'active_unit_count' => $activeUnits->count(),
                'technical_blocker_count' => count($technicalBlockers),
                'data_blocker_count' => count($dataBlockers),
            ],
            'unit_backfill' => $backfill['summary'] ?? null,
            'permission_sync' => $permissionSync['summary'] ?? null,
            'authorization_invariants' => $invariants,
            'membership_audit' => [
                'pilot_user_ids' => $pilotIds->all(),
                'unknown_pilot_user_ids' => $unknownPilotIds,
                'users_without_active_membership' => $usersWithoutMembership->all(),
                'users_without_single_primary_membership' => $usersWithoutSinglePrimary->all(),
                'units_without_coordinator' => $unitsWithoutCoordinator->all(),
                'evaluated_units_without_coordinator' => $evaluatedUnitsWithoutCoordinator->all(),
            ],
            'findings' => [
                'technical_blockers' => $technicalBlockers,
                'data_blockers' => $dataBlockers,
                'warnings' => collect($authorityUsers->isEmpty()
                    ? [['code' => 'no_real_membership_data_yet', 'message' => 'Sentetik testler calisabilir; canli pilot/enforce gercek kullanicilar girilene kadar acilmamalidir.']]
                    : ($unitsWithoutCoordinator->isNotEmpty()
                        ? [['code' => 'units_without_coordinator', 'count' => $unitsWithoutCoordinator->count()]]
                        : []))
                    ->when(
                        ($permissionSync['summary']['proposed_change_count'] ?? 0) > 0,
                        fn ($warnings) => $warnings->push([
                            'code' => 'permission_sync_changes_pending',
                            'count' => $permissionSync['summary']['proposed_change_count'],
                            'message' => 'Guvenlik invariantlari ayrica degerlendirilir; dry-run degisiklikleri uygulanmadan once incelenmelidir.',
                        ])
                    )
                    ->merge($invariants['warnings'] ?? [])
                    ->values()
                    ->all(),
            ],
        ];
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'name' => trim($user->name.' '.$user->surname),
            'email' => $user->email,
            'role' => $user->role,
        ];
    }
}
