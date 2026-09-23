<?php

namespace App\Services;

use App\Models\WorkflowStatusHistory;
use Illuminate\Database\Eloquent\Model;

class WorkflowStatusHistoryRecorder
{
    public function record(
        Model $subject,
        ?string $fromStatus,
        string $toStatus,
        ?int $changedBy,
        ?int $unitId = null,
        array $metadata = [],
    ): ?WorkflowStatusHistory {
        if ($fromStatus === $toStatus) {
            return null;
        }

        return WorkflowStatusHistory::query()->create([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'changed_by' => $changedBy,
            'unit_id' => $unitId,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
