<?php

namespace App\Http\Resources;

use App\Support\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'target_unit' => $this->target_unit,
            'target_unit_id' => $this->target_unit_id === null ? null : (int) $this->target_unit_id,
            'target_membership_id' => $this->target_membership_id === null ? null : (int) $this->target_membership_id,
            'description' => $this->description,
            'status' => $this->status,
            'response_file_path' => $this->response_file_path,
            'response_file_url' => MediaStorage::directDownloadsEnabled() ? MediaStorage::url($this->response_file_path) : null,
            'response_file_download_url' => $this->response_file_path
                ? "/panel/requests/{$this->id}/response-file"
                : null,
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
            'can_update_status' => (bool) ($this->getAttribute('can_update_status') ?? false),
            'can_upload_response' => (bool) ($this->getAttribute('can_upload_response') ?? false),
            'requester' => $this->whenLoaded('requester', function () {
                return [
                    'id' => $this->requester?->id,
                    'name' => $this->requester?->name,
                    'surname' => $this->requester?->surname,
                    'role' => $this->requester?->role,
                ];
            }),
            'target_user' => $this->whenLoaded('targetUser', function () {
                return [
                    'id' => $this->targetUser?->id,
                    'name' => $this->targetUser?->name,
                    'surname' => $this->targetUser?->surname,
                    'role' => $this->targetUser?->role,
                ];
            }),
            'target_coordination_unit' => $this->whenLoaded('targetUnit', function () {
                return [
                    'id' => $this->targetUnit?->id,
                    'code' => $this->targetUnit?->code,
                    'name' => $this->targetUnit?->name,
                    'kind' => $this->targetUnit?->kind,
                    'project_id' => $this->targetUnit?->project_id,
                ];
            }),
            'project' => $this->whenLoaded('project', function () {
                return [
                    'id' => $this->project?->id,
                    'name' => $this->project?->name,
                    'slug' => $this->project?->slug,
                    'type' => $this->project?->type,
                ];
            }),
            'period' => $this->whenLoaded('period', function () {
                return [
                    'id' => $this->period?->id,
                    'name' => $this->period?->name,
                    'status' => $this->period?->status,
                    'start_date' => optional($this->period?->start_date)?->toDateString(),
                    'end_date' => optional($this->period?->end_date)?->toDateString(),
                ];
            }),
        ];
    }
}
