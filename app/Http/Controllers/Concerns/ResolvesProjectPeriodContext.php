<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Period;
use App\Models\Project;
use App\Support\ProjectPeriodContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait ResolvesProjectPeriodContext
{
    protected function assertPeriodWritable(
        Request $request,
        ?int $periodId,
        string $archivePermission = 'periods.archive.update',
    ): void {
        if ($periodId === null) {
            return;
        }

        $period = Period::query()
            ->select(['id', 'project_id', 'name', 'status'])
            ->findOrFail($periodId);

        if ($period->status !== 'completed') {
            return;
        }

        abort_unless(
            $this->permissionResolver->hasPermission($request->user(), $archivePermission)
            && $this->permissionResolver->canAccessProject($request->user(), $archivePermission, (int) $period->project_id),
            423,
            'Tamamlanmis donem arsiv modundadir. Degisiklik icin arsiv duzeltme yetkisi gerekir.'
        );

        $request->attributes->set('archive_write_override', [
            'period_id' => (int) $period->id,
            'project_id' => (int) $period->project_id,
            'permission' => $archivePermission,
        ]);
    }

    protected function resolveProjectPeriodContext(
        Request $request,
        string $permission,
        ?int $projectId = null,
        ?int $periodId = null,
    ): ProjectPeriodContext {
        $user = $request->user();

        abort_unless(
            $this->permissionResolver->hasPermission($user, $permission),
            403,
            'Bu islem icin yetkiniz yok.'
        );

        $period = null;
        if ($periodId !== null) {
            $period = Period::query()
                ->select(['id', 'project_id', 'name', 'status'])
                ->findOrFail($periodId);

            if ($projectId !== null && (int) $period->project_id !== $projectId) {
                throw ValidationException::withMessages([
                    'period_id' => ['Secilen donem bu projeye ait degil.'],
                ]);
            }

            $projectId ??= (int) $period->project_id;
        }

        if ($projectId !== null) {
            abort_unless(
                $this->permissionResolver->canAccessProject($user, $permission, $projectId),
                403,
                'Bu proje icin yetkiniz yok.'
            );

            $allowedProjectIds = [$projectId];
        } elseif ($this->permissionResolver->hasGlobalScope($user, $permission)) {
            $allowedProjectIds = Project::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        } else {
            $allowedProjectIds = collect($this->permissionResolver->projectIdsForPermission($user, $permission))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        $context = new ProjectPeriodContext(
            $projectId,
            $period?->id,
            $period?->status,
            $allowedProjectIds,
            $period?->name,
        );

        $request->attributes->set('permission_checked', $permission);
        $request->attributes->set('permission_scope', [
            'scope_type' => 'project_period',
            'project_id' => $context->projectId,
            'period_id' => $context->periodId,
            'archive_mode' => $context->isArchiveMode(),
        ]);

        return $context;
    }

    protected function resolveProjectPeriodContextForAnyPermission(
        Request $request,
        array $permissions,
        ?int $projectId = null,
        ?int $periodId = null,
    ): ProjectPeriodContext {
        $user = $request->user();
        $effectivePermissions = collect($permissions)
            ->filter(fn (string $permission) => $this->permissionResolver->hasPermission($user, $permission))
            ->values();

        abort_unless($effectivePermissions->isNotEmpty(), 403, 'Bu islem icin yetkiniz yok.');

        $period = null;
        if ($periodId !== null) {
            $period = Period::query()
                ->select(['id', 'project_id', 'name', 'status'])
                ->findOrFail($periodId);

            if ($projectId !== null && (int) $period->project_id !== $projectId) {
                throw ValidationException::withMessages([
                    'period_id' => ['Secilen donem bu projeye ait degil.'],
                ]);
            }

            $projectId ??= (int) $period->project_id;
        }

        if ($projectId !== null) {
            abort_unless(
                $effectivePermissions->contains(
                    fn (string $permission) => $this->permissionResolver->canAccessProject($user, $permission, $projectId)
                ),
                403,
                'Bu proje icin yetkiniz yok.'
            );

            $allowedProjectIds = [$projectId];
        } else {
            $allowedProjectIds = $effectivePermissions
                ->flatMap(fn (string $permission) => $this->permissionResolver->projectIdsForPermission($user, $permission))
                ->filter(fn ($id) => is_numeric($id))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        $context = new ProjectPeriodContext(
            $projectId,
            $period?->id,
            $period?->status,
            $allowedProjectIds,
            $period?->name,
        );

        $request->attributes->set('permission_checked', $effectivePermissions->all());
        $request->attributes->set('permission_scope', [
            'scope_type' => 'project_period',
            'project_id' => $context->projectId,
            'period_id' => $context->periodId,
            'archive_mode' => $context->isArchiveMode(),
        ]);

        return $context;
    }

    protected function applyProjectPeriodContext(
        Builder $query,
        ProjectPeriodContext $context,
        string $projectColumn = 'project_id',
        string $periodColumn = 'period_id',
        bool $includeNullPeriodRows = false,
    ): Builder {
        $projectIds = $context->projectIdsForQuery();
        if ($context->projectId !== null) {
            $query->where($projectColumn, $context->projectId);
        } elseif (! empty($projectIds)) {
            $query->whereIn($projectColumn, $projectIds);
        } else {
            $query->whereRaw('1 = 0');
        }

        if ($context->periodId !== null) {
            $query->where(function (Builder $builder) use ($context, $periodColumn, $includeNullPeriodRows) {
                $builder->where($periodColumn, $context->periodId);

                if ($includeNullPeriodRows) {
                    $builder->orWhereNull($periodColumn);
                }
            });
        }

        return $query;
    }
}
