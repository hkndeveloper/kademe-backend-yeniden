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
            'description' => $this->description,
            'status' => $this->status,
            'response_file_path' => $this->response_file_path,
            'response_file_url' => MediaStorage::url($this->response_file_path),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
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
            'project' => $this->whenLoaded('project', function () {
                return [
                    'id' => $this->project?->id,
                    'name' => $this->project?->name,
                    'slug' => $this->project?->slug,
                    'type' => $this->project?->type,
                ];
            }),
        ];
    }
}
