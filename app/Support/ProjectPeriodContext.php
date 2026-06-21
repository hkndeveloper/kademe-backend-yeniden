<?php

namespace App\Support;

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
        return $this->periodStatus !== null && $this->periodStatus !== 'active';
    }

    public function projectIdsForQuery(): array
    {
        if ($this->projectId !== null) {
            return [$this->projectId];
        }

        return $this->allowedProjectIds;
    }
}
