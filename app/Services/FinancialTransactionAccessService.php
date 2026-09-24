<?php

namespace App\Services;

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitMembership;
use App\Models\CoordinationUnitProjectResponsibility;
use App\Models\FinancialTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class FinancialTransactionAccessService
{
    public const SERVICE_DOMAIN = 'finance_procurement';

    /** @var array<int, array<string, list<int>>> */
    private array $membershipUnitIds = [];

    /** @var array<int, array<string, list<int>>> */
    private array $membershipProjectIds = [];

    public function __construct(
        private readonly PermissionResolver $permissionResolver,
    ) {}

    public function resolveProcessingUnitId(?int $projectId): ?int
    {
        if ($projectId === null) {
            return null;
        }

        $unitId = CoordinationUnitProjectResponsibility::query()
            ->active()
            ->where('project_id', $projectId)
            ->where('service_domain', self::SERVICE_DOMAIN)
            ->where('is_primary', true)
            ->whereHas('unit', fn (Builder $query) => $query->where('status', 'active'))
            ->value('unit_id');

        return $unitId === null ? null : (int) $unitId;
    }

    public function applyVisibleScope(Builder $query, User $user, string $permissionName): void
    {
        if (! $this->permissionResolver->hasPermission($user, $permissionName)) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($this->permissionResolver->hasGlobalScope($user, $permissionName)) {
            return;
        }

        $projectIds = $this->permissionResolver->projectIdsForPermission($user, $permissionName);
        $unitIds = $this->activeUnitIds($user);
        $projectUnitProjectIds = $this->projectUnitProjectIds($user, $permissionName, $projectIds);

        $query->where(function (Builder $visibility) use ($user, $projectIds, $unitIds, $projectUnitProjectIds) {
            // Kaydi baslatan kisi, birim sorumlulugu sonradan degisse de kendi kaydini izleyebilir.
            $visibility->where(function (Builder $submitted) use ($user, $projectIds) {
                $submitted->where('submitted_by', $user->id)->whereIn('project_id', $projectIds);
            });

            if ($unitIds !== [] && $projectIds !== []) {
                $visibility->orWhere(function (Builder $assigned) use ($unitIds, $projectIds) {
                    $assigned
                        ->whereIn('processing_unit_id', $unitIds)
                        ->whereIn('project_id', $projectIds);
                });
            }

            if ($projectUnitProjectIds !== []) {
                $visibility->orWhereIn('project_id', $projectUnitProjectIds);
            }

            // Gecis uyumlulugu: normalize edilmemis eski kayitlar proje scope'u ile calismaya devam eder.
            if ($projectIds !== []) {
                $visibility->orWhere(function (Builder $legacy) use ($projectIds) {
                    $legacy
                        ->whereNull('processing_unit_id')
                        ->whereIn('project_id', $projectIds);
                });
            }
        });
    }

    public function canView(User $user, FinancialTransaction $transaction): bool
    {
        return $this->canAccessRecord($user, $transaction, 'financial.view', true);
    }

    public function canDownloadInvoice(User $user, FinancialTransaction $transaction): bool
    {
        return $this->canAccessRecord($user, $transaction, 'financial.invoice.download', true);
    }

    public function canProcess(
        User $user,
        FinancialTransaction $transaction,
        string $permissionName,
        bool $coordinatorOnly = false,
    ): bool {
        return $this->canAccessRecord($user, $transaction, $permissionName, false, $coordinatorOnly);
    }

    /**
     * @return array<string, bool>
     */
    public function capabilities(User $user, FinancialTransaction $transaction): array
    {
        return [
            'view' => $this->canView($user, $transaction),
            'download_invoice' => $this->canDownloadInvoice($user, $transaction),
            'delete' => $this->canProcess($user, $transaction, 'financial.delete', true),
            'approve' => $this->canProcess($user, $transaction, 'financial.approve', true),
            'reject' => $this->canProcess($user, $transaction, 'financial.reject', true),
            'mark_paid' => $this->canProcess($user, $transaction, 'financial.mark_paid', true),
        ];
    }

    private function canAccessRecord(
        User $user,
        FinancialTransaction $transaction,
        string $permissionName,
        bool $allowSubmitter,
        bool $coordinatorOnly = false,
    ): bool {
        if (! $this->permissionResolver->hasPermission($user, $permissionName)) {
            return false;
        }

        if ($this->permissionResolver->hasGlobalScope($user, $permissionName)) {
            return true;
        }

        if ($allowSubmitter
            && $transaction->project_id !== null
            && (int) $transaction->submitted_by === (int) $user->id
            && $this->permissionResolver->canAccessProject($user, $permissionName, (int) $transaction->project_id)) {
            return true;
        }

        if ($transaction->processing_unit_id === null) {
            if ($coordinatorOnly && ! CoordinationUnitMembership::query()
                ->active()
                ->where('user_id', $user->id)
                ->where('position', CoordinationUnitMembership::POSITION_COORDINATOR)
                ->whereHas('unit', fn (Builder $query) => $query
                    ->where('code', 'service_purchase_organization')
                    ->where('status', 'active'))
                ->exists()) {
                return false;
            }

            return $this->canAccessLegacyProject($user, $permissionName, $transaction->project_id);
        }

        if ($transaction->project_id === null
            || ! $this->permissionResolver->canAccessProject($user, $permissionName, (int) $transaction->project_id)) {
            return false;
        }

        if ($allowSubmitter && in_array(
            (int) $transaction->project_id,
            $this->projectUnitProjectIds($user, $permissionName, [(int) $transaction->project_id]),
            true
        )) {
            return true;
        }

        $position = $coordinatorOnly
            ? CoordinationUnitMembership::POSITION_COORDINATOR
            : 'any';

        return in_array((int) $transaction->processing_unit_id, $this->activeUnitIds($user, $position), true);
    }

    private function canAccessLegacyProject(User $user, string $permissionName, ?int $projectId): bool
    {
        if ($projectId === null) {
            return $this->permissionResolver->hasGlobalScope($user, $permissionName);
        }

        return $this->permissionResolver->canAccessProject($user, $permissionName, $projectId);
    }

    /** @param list<int> $allowedProjectIds
     *  @return list<int>
     */
    private function projectUnitProjectIds(User $user, string $permissionName, array $allowedProjectIds): array
    {
        if ($allowedProjectIds === []) {
            return [];
        }

        $scopeUnitIds = $this->permissionResolver->scopeFor($user, $permissionName)['scope_payload']['unit_ids'] ?? [];

        $projectIds = $this->membershipProjectIds[$user->id][$permissionName] ??= CoordinationUnitMembership::query()
            ->active()
            ->where('user_id', $user->id)
            ->when($scopeUnitIds !== [], fn (Builder $query) => $query->whereIn('unit_id', $scopeUnitIds))
            ->whereHas('unit', fn (Builder $query) => $query
                ->where('kind', CoordinationUnit::KIND_PROJECT)
                ->where('status', 'active'))
            ->with('unit:id,project_id')
            ->get()
            ->pluck('unit.project_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        return array_values(array_intersect($projectIds, $allowedProjectIds));
    }

    /**
     * @return list<int>
     */
    private function activeUnitIds(User $user, string $position = 'any'): array
    {
        if (isset($this->membershipUnitIds[$user->id][$position])) {
            return $this->membershipUnitIds[$user->id][$position];
        }

        $query = CoordinationUnitMembership::query()
            ->active()
            ->where('user_id', $user->id)
            ->whereHas('unit', fn (Builder $builder) => $builder->where('status', 'active'));

        if ($position !== 'any') {
            $query->where('position', $position);
        }

        return $this->membershipUnitIds[$user->id][$position] = $query
            ->pluck('unit_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
