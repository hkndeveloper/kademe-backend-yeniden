<?php

namespace App\Support;

use App\Services\PeriodLifecycleService;

final class ProjectPeriodContext
{
    public function __construct(
        public readonly ?int $projectId,
        public readonly ?int $periodId,
        public readonly ?string $periodStatus,
        public readonly array $allowedProjectIds = [],
        public readonly ?string $periodName = null,
    ) {
    }

    public function isArchiveMode(): bool
    {
        return PeriodLifecycleService::isArchiveStatus($this->periodStatus);
    }

    public function projectIdsForQuery(): array
    {
        if ($this->projectId !== null) {
            return [$this->projectId];
        }

        return $this->allowedProjectIds;
    }
}
