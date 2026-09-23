<?php

namespace App\Services;

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

        $query->where(function (Builder $visibility) use ($user, $projectIds, $unitIds) {
            // Kaydi baslatan kisi, birim sorumlulugu sonradan degisse de kendi kaydini izleyebilir.
            $visibility->where('submitted_by', $user->id);

            if ($unitIds !== [] && $projectIds !== []) {
                $visibility->orWhere(function (Builder $assigned) use ($unitIds, $projectIds) {
                    $assigned
                        ->whereIn('processing_unit_id', $unitIds)
                        ->whereIn('project_id', $projectIds);
                });
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

        if ($allowSubmitter && (int) $transaction->submitted_by === (int) $user->id) {
            return true;
        }

        if ($transaction->processing_unit_id === null) {
            return $this->canAccessLegacyProject($user, $permissionName, $transaction->project_id);
        }

        if ($transaction->project_id === null
            || ! $this->permissionResolver->canAccessProject($user, $permissionName, (int) $transaction->project_id)) {
            return false;
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
