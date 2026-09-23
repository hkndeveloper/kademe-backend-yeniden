<?php

namespace App\Services;

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitMembership;
use App\Models\Program;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class CommunityProgramAccessService
{
    public const UNIT_CODE = 'service_community_culture';

    public const SERVICE_DOMAIN = 'community_culture';

    public function __construct(private readonly PermissionResolver $permissionResolver) {}

    public function managingUnitFor(User $user, string $permission, int $projectId): ?CoordinationUnit
    {
        if (! $this->permissionResolver->canAccessProject($user, $permission, $projectId)) {
            return null;
        }

        $query = CoordinationUnit::query()
            ->active()
            ->where('code', self::UNIT_CODE)
            ->whereHas('projectResponsibilities', fn (Builder $builder) => $builder
                ->active()
                ->where('project_id', $projectId)
                ->where('service_domain', self::SERVICE_DOMAIN));

        if (! $this->permissionResolver->hasGlobalScope($user, $permission)) {
            $query->whereHas('memberships', fn (Builder $builder) => $builder
                ->active()
                ->where('user_id', $user->id));
        }

        return $query->first();
    }

    public function canAccess(User $user, string $permission, Program $program): bool
    {
        if (! $program->isCommunityEvent()
            || ! $this->permissionResolver->canAccessProject($user, $permission, (int) $program->project_id)) {
            return false;
        }

        if ($this->permissionResolver->hasGlobalScope($user, $permission)) {
            return true;
        }

        if ($program->managing_unit_id === null) {
            return false;
        }

        return CoordinationUnitMembership::query()
            ->active()
            ->where('user_id', $user->id)
            ->where('unit_id', $program->managing_unit_id)
            ->whereHas('unit', fn (Builder $builder) => $builder
                ->active()
                ->where('code', self::UNIT_CODE))
            ->exists();
    }

    public function constrainToAccessibleEvents(Builder $query, User $user, string $permission): Builder
    {
        $query->where('program_kind', Program::KIND_COMMUNITY_EVENT);

        if ($this->permissionResolver->hasGlobalScope($user, $permission)) {
            return $query;
        }

        $unitIds = CoordinationUnitMembership::query()
            ->active()
            ->where('user_id', $user->id)
            ->whereHas('unit', fn (Builder $builder) => $builder
                ->active()
                ->where('code', self::UNIT_CODE))
            ->pluck('unit_id');

        return $query->whereIn('managing_unit_id', $unitIds);
    }
}
