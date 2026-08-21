<?php

namespace App\Services;

use App\Enums\PeriodWriteAction;
use App\Models\Period;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PeriodWritePolicy
{
    public function __construct(
        private readonly PermissionResolver $permissionResolver,
        private readonly PeriodLifecycleMonitor $monitor,
    ) {}

    public function assertAllowed(
        ?User $actor,
        Period $period,
        PeriodWriteAction $action,
        string $legacyArchivePermission = 'periods.archive.update',
    ): array {
        $status = (string) $period->status;

        if (in_array($status, [PeriodLifecycleService::COMPLETED, PeriodLifecycleService::CANCELLED], true)) {
            $permission = $action === PeriodWriteAction::ARCHIVE_CORRECTION
                ? $this->archiveCorrectionPermission($actor, $period, $legacyArchivePermission)
                : null;
            if ($permission === null) {
                $this->monitor->writeRejected($actor, $period, $action);
                throw new HttpException(
                    423,
                    $status === PeriodLifecycleService::COMPLETED
                        ? 'Tamamlanmis donem arsiv modundadir. Degisiklik icin arsiv duzeltme yetkisi gerekir.'
                        : 'Iptal edilmis donem salt okunur durumdadir. Degisiklik icin arsiv duzeltme yetkisi gerekir.',
                );
            }

            return [
                'mode' => PeriodWriteAction::ARCHIVE_CORRECTION->value,
                'permission' => $permission,
                'period_id' => (int) $period->id,
                'project_id' => (int) $period->project_id,
            ];
        }

        $allowed = match ($action) {
            PeriodWriteAction::CONFIGURE_PERIOD => in_array($status, [
                PeriodLifecycleService::PLANNED,
                PeriodLifecycleService::LEGACY_PASSIVE,
                PeriodLifecycleService::ACTIVE,
            ], true),
            PeriodWriteAction::CREATE_OPERATION => $status === PeriodLifecycleService::ACTIVE,
            PeriodWriteAction::RESOLVE_OPERATION => in_array($status, [
                PeriodLifecycleService::ACTIVE,
                PeriodLifecycleService::CLOSING,
            ], true),
            PeriodWriteAction::ARCHIVE_CORRECTION => false,
        };

        if ($allowed) {
            return [
                'mode' => $action->value,
                'permission' => null,
                'period_id' => (int) $period->id,
                'project_id' => (int) $period->project_id,
            ];
        }

        $message = match ($status) {
            PeriodLifecycleService::PLANNED, PeriodLifecycleService::LEGACY_PASSIVE => 'Planlanan donemde operasyon kaydi acilamaz. Once donemi aktif edin.',
            PeriodLifecycleService::CLOSING => 'Kapanis hazirligindaki donemde yeni kayit acilamaz; yalniz mevcut isler sonuclandirilabilir.',
            default => 'Bu donemin mevcut durumunda isleme izin verilmiyor.',
        };

        $this->monitor->writeRejected($actor, $period, $action);

        throw new HttpException(423, $message);
    }

    private function archiveCorrectionPermission(
        ?User $actor,
        Period $period,
        string $legacyArchivePermission,
    ): ?string {
        if (! $actor) {
            return null;
        }

        foreach (['periods.archive.correct', $legacyArchivePermission] as $permission) {
            if ($this->permissionResolver->hasPermission($actor, $permission)
                && $this->permissionResolver->canAccessProject($actor, $permission, (int) $period->project_id)) {
                return $permission;
            }
        }

        return null;
    }
}
