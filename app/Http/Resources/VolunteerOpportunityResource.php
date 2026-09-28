<?php

namespace App\Http\Resources;

use App\Services\ApplicationConsentService;
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
            'application_consent_text' => app(ApplicationConsentService::class)->textWithAdditional($this->consent_text),
            'project_id' => $this->project_id,
            'period_id' => $this->period_id,
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
            'period' => $this->whenLoaded('period', function () {
                return [
                    'id' => $this->period?->id,
                    'name' => $this->period?->name,
                    'status' => $this->period?->status,
                ];
            }),
            'my_application' => $application ? [
                'id' => $application->id,
                'status' => $application->status,
                'motivation_text' => $application->motivation_text,
                'notes' => $application->notes,
                'evaluation_note' => $application->evaluation_note,
                'consent_text_snapshot' => $application->consent_text_snapshot,
                'consent_accepted_at' => optional($application->consent_accepted_at)?->toIso8601String(),
                'receipt_email_status' => $application->receipt_email_status,
                'created_at' => optional($application->created_at)?->toIso8601String(),
            ] : null,
        ];
    }
}
