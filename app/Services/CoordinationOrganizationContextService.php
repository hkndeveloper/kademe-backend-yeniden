<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;

class CoordinationOrganizationContextService
{
    public function __construct(
        private readonly PermissionResolver $permissionResolver,
        private readonly CoordinationUnitAuthorizationResolver $coordinationUnitAuthorizationResolver
    ) {}

    public function forUser(User $user, ?array $legacyAuthorization = null): array
    {
        $authorization = $legacyAuthorization ?? $this->permissionResolver->resolve($user);
        $mode = config('coordination_authorization.mode', 'legacy');
        $authoritative = $this->permissionResolver->coordinationUnitsAreAuthoritative($user);
        $unit = $authoritative
            ? $authorization
            : $this->coordinationUnitAuthorizationResolver->resolve(
                $user,
                collect($authorization['direct_overrides'] ?? [])
            );
        $contexts = $unit['contexts'] ?? [];
        $memberships = $contexts['unit_memberships'] ?? [];
        $manageableProjectIds = $contexts['manageable_project_ids'] ?? [];
        $availableProjectIds = collect($memberships)
            ->flatMap(fn (array $membership) => $membership['manageable_project_ids'] ?? [])
            ->merge($manageableProjectIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        $activeContext = $contexts['active_coordination_context'] ?? [];

        return [
            'schema_version' => 3,
            'available' => $this->coordinationUnitAuthorizationResolver->isAvailable(),
            'authorization_mode' => $mode,
            'authoritative' => $authoritative,
            'context_header' => ActiveCoordinationUnitContext::HEADER,
            'active_unit_id' => $activeContext['active_unit_id'] ?? $contexts['active_unit_id'] ?? null,
            'active_membership_id' => $activeContext['active_membership_id'] ?? $contexts['active_membership_id'] ?? null,
            'active_context_source' => $activeContext['source'] ?? ($authoritative ? 'resolver_fallback' : 'not_authoritative'),
            'selection_required' => (bool) ($activeContext['selection_required'] ?? false),
            'unit_memberships' => $memberships,
            'primary_unit_id' => $contexts['primary_unit_id'] ?? null,
            'coordinated_unit_ids' => $contexts['coordinated_unit_ids'] ?? [],
            'staffed_unit_ids' => $contexts['staffed_unit_ids'] ?? [],
            'project_ids_by_permission' => $contexts['project_ids_by_permission'] ?? [],
            'manageable_project_ids' => $manageableProjectIds,
            'available_project_ids' => $availableProjectIds,
            'projects' => Project::query()
                ->whereIn('id', $availableProjectIds)
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'status'])
                ->map(fn (Project $project) => [
                    'id' => (int) $project->id,
                    'name' => $project->name,
                    'slug' => $project->slug,
                    'status' => $project->status,
                ])
                ->values()
                ->all(),
        ];
    }
}
