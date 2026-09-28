<?php

namespace App\Services;

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitMembership;
use App\Models\Project;
use App\Models\User;
use App\Support\AuthorizationManagementCatalog;

class ApplicationNotificationRecipientService
{
    public function __construct(
        private readonly PermissionResolver $permissionResolver,
        private readonly CoordinationUnitAuthorizationResolver $unitAuthorizationResolver,
    ) {}

    /** @return list<string> */
    public function coordinatorEmailsFor(Project $project): array
    {
        $projectId = (int) $project->id;
        $unitIds = CoordinationUnit::query()
            ->active()
            ->where(function ($query) use ($projectId) {
                $query->where(fn ($linked) => $linked
                    ->where('kind', CoordinationUnit::KIND_PROJECT)
                    ->where('project_id', $projectId))
                    ->orWhereHas('projectResponsibilities', fn ($responsibility) => $responsibility
                        ->active()
                        ->where('project_id', $projectId));
            })
            ->pluck('id')
            ->all();

        $unitCoordinatorIds = $unitIds === [] ? [] : CoordinationUnitMembership::query()
            ->active()
            ->whereIn('unit_id', $unitIds)
            ->where('position', CoordinationUnitMembership::POSITION_COORDINATOR)
            ->pluck('user_id')
            ->all();
        $legacyCoordinatorIds = $project->coordinators()->pluck('users.id')->all();
        $candidateIds = collect($unitCoordinatorIds)->merge($legacyCoordinatorIds)->unique()->values()->all();
        if ($candidateIds === []) {
            return [];
        }

        $membershipsByUser = CoordinationUnitMembership::query()
            ->active()
            ->whereIn('user_id', $candidateIds)
            ->whereHas('unit', fn ($unit) => $unit->active())
            ->with(['unit', 'permissionOverrides' => fn ($overrides) => $overrides->active()])
            ->get()
            ->groupBy('user_id');
        $usersWithUnitHistory = CoordinationUnitMembership::query()
            ->withTrashed()
            ->whereIn('user_id', $candidateIds)
            ->pluck('user_id')
            ->all();

        return User::query()
            ->whereIn('id', $candidateIds)
            ->where('status', 'active')
            ->whereNotNull('email')
            ->get(['id', 'email', 'role', 'status'])
            ->filter(function (User $user) use ($projectId, $unitIds, $legacyCoordinatorIds, $membershipsByUser, $usersWithUnitHistory) {
                $memberships = $membershipsByUser->get($user->id, collect());
                if ($memberships->isEmpty()) {
                    return ! in_array($user->id, $usersWithUnitHistory, true)
                        && $user->role === 'coordinator'
                        && in_array($user->id, $legacyCoordinatorIds, true)
                        && $this->permissionResolver->canAccessProject($user, 'applications.view', $projectId);
                }

                $matching = $memberships->filter(fn (CoordinationUnitMembership $membership) =>
                    $membership->position === CoordinationUnitMembership::POSITION_COORDINATOR
                    && in_array($membership->unit_id, $unitIds, true));
                if ($matching->isEmpty()) {
                    return false;
                }

                if (! $this->permissionResolver->coordinationUnitsAreAuthoritative($user)) {
                    return $this->permissionResolver->canAccessProject($user, 'applications.view', $projectId);
                }

                return $matching->contains(fn (CoordinationUnitMembership $membership) =>
                    $this->membershipCanViewProject($user, $membership, $projectId));
            })
            ->pluck('email')
            ->map(fn (string $email) => mb_strtolower(trim($email)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function membershipCanViewProject(User $user, CoordinationUnitMembership $membership, int $projectId): bool
    {
        $globalOverrides = collect($this->permissionResolver->resolveLegacySnapshot($user)['direct_overrides'] ?? [])
            ->reject(fn (array $override) => ($override['effect'] ?? null) === 'allow'
                && AuthorizationManagementCatalog::isUnitBusinessPermission((string) ($override['permission_name'] ?? '')))
            ->values();
        $membershipOverrides = $membership->permissionOverrides
            ->map(fn ($override) => [
                'permission_name' => $override->permission_name,
                'effect' => $override->effect,
                'scope_type' => $override->scope_type,
                'scope_payload' => $override->scope_payload ?? [],
            ])
            ->values();
        $resolved = $this->unitAuthorizationResolver->resolveForMembership(
            $user,
            $membership,
            $globalOverrides,
            $membershipOverrides
        );
        if (! $resolved['effective_permissions']->contains('applications.view')) {
            return false;
        }

        $scope = $resolved['scopes']['applications.view'] ?? [];
        if (($scope['scope_type'] ?? null) === 'all') {
            return true;
        }

        return ($scope['scope_type'] ?? null) === 'selected_projects'
            && in_array($projectId, array_map('intval', $scope['scope_payload']['project_ids'] ?? []), true);
    }
}
