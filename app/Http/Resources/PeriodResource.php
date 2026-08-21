<?php

namespace App\Http\Resources;

use App\Services\PeriodLifecycleService;
use App\Services\PermissionResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PeriodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $resolver = app(PermissionResolver::class);
        $projectId = (int) $this->project_id;
        $allowedTransitions = collect($this->transitionPermissions())
            ->filter(fn (string $permission) => $user
                && $resolver->hasPermission($user, $permission)
                && $resolver->canAccessProject($user, $permission, $projectId))
            ->keys()
            ->values()
            ->all();

        $isCurrent = $this->relationLoaded('project')
            && (int) ($this->project?->current_period_id ?? 0) === (int) $this->id;

        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'start_date' => optional($this->start_date)?->toDateString(),
            'end_date' => optional($this->end_date)?->toDateString(),
            'credit_start_amount' => $this->credit_start_amount,
            'credit_threshold' => $this->credit_threshold,
            'status' => $this->status,
            'project' => $this->whenLoaded('project', fn () => $this->project ? [
                'id' => $this->project->id,
                'name' => $this->project->name,
                'current_period_id' => $this->project->current_period_id,
            ] : null),
            'lifecycle' => [
                'status' => $this->status,
                'version' => (int) ($this->lifecycle_version ?? 0),
                'is_current' => $isCurrent,
                'is_archive_mode' => PeriodLifecycleService::isArchiveStatus($this->status),
                'allowed_transitions' => $allowedTransitions,
                'write_capabilities' => PeriodLifecycleService::writeCapabilitiesForStatus($this->status),
                'activated_at' => optional($this->activated_at)?->toIso8601String(),
                'activated_by' => $this->activated_by,
                'closing_started_at' => optional($this->closing_started_at)?->toIso8601String(),
                'closing_started_by' => $this->closing_started_by,
                'completed_at' => optional($this->completed_at)?->toIso8601String(),
                'completed_by' => $this->completed_by,
                'reopened_at' => optional($this->reopened_at)?->toIso8601String(),
                'reopened_by' => $this->reopened_by,
                'cancelled_at' => optional($this->cancelled_at)?->toIso8601String(),
                'cancelled_by' => $this->cancelled_by,
                'archive_integrity_status' => $this->whenLoaded(
                    'latestArchive',
                    fn () => $this->latestArchive?->verification_status ?? ($this->latestArchive ? 'not_verified' : null),
                ),
            ],
            'latest_archive' => $this->whenLoaded('latestArchive', fn () => $this->latestArchive ? [
                'id' => $this->latestArchive->id,
                'archive_version' => $this->latestArchive->archive_version,
                'schema_version' => $this->latestArchive->schema_version,
                'integrity_hash' => $this->latestArchive->integrity_hash,
                'verification_status' => $this->latestArchive->verification_status,
                'closed_at' => optional($this->latestArchive->closed_at)?->toIso8601String(),
            ] : null),
            'lifecycle_events' => $this->whenLoaded('lifecycleEvents', fn () => $this->lifecycleEvents->map(fn ($event) => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'reason' => $event->reason,
                'metadata' => $event->metadata_json,
                'created_at' => optional($event->created_at)?->toIso8601String(),
                'actor' => $event->relationLoaded('actor') && $event->actor ? [
                    'id' => $event->actor->id,
                    'name' => trim($event->actor->name.' '.$event->actor->surname),
                ] : null,
            ])->values()),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }

    private function transitionPermissions(): array
    {
        return match ($this->status) {
            PeriodLifecycleService::PLANNED, PeriodLifecycleService::LEGACY_PASSIVE => [
                'activate' => 'periods.activate',
                'cancel' => 'periods.cancel',
            ],
            PeriodLifecycleService::ACTIVE => [
                'start_closing' => 'periods.closing.start',
            ],
            PeriodLifecycleService::CLOSING => [
                'cancel_closing' => 'periods.closing.cancel',
                'complete' => 'periods.complete',
            ],
            PeriodLifecycleService::COMPLETED => [
                'reopen' => 'periods.reopen',
            ],
            default => [],
        };
    }

}
