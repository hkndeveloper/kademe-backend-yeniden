<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicProgramResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'location' => $this->location,
            'guest_info' => $this->guest_info,
            'start_at' => optional($this->start_at)?->toIso8601String(),
            'end_at' => optional($this->end_at)?->toIso8601String(),
            'status' => $this->status,
            'project' => $this->whenLoaded('project', function () {
                return [
                    'id' => $this->project?->id,
                    'name' => $this->project?->name,
                    'slug' => $this->project?->slug,
                ];
            }),
            'period' => $this->whenLoaded('period', function () {
                return $this->period ? [
                    'id' => $this->period->id,
                    'name' => $this->period->name,
                ] : null;
            }),
        ];
    }
}
