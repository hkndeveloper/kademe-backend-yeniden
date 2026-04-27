<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VolunteerOpportunityResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $application = $this->whenLoaded('applications', fn () => $this->applications->first());

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'location' => $this->location,
            'start_at' => optional($this->start_at)?->toIso8601String(),
            'end_at' => optional($this->end_at)?->toIso8601String(),
            'quota' => $this->quota,
            'status' => $this->status,
            'project' => $this->whenLoaded('project', function () {
                return [
                    'id' => $this->project?->id,
                    'name' => $this->project?->name,
                    'slug' => $this->project?->slug,
                    'type' => $this->project?->type,
                ];
            }),
            'my_application' => $application ? [
                'id' => $application->id,
                'status' => $application->status,
                'motivation_text' => $application->motivation_text,
                'notes' => $application->notes,
                'evaluation_note' => $application->evaluation_note,
                'created_at' => optional($application->created_at)?->toIso8601String(),
            ] : null,
        ];
    }
}
