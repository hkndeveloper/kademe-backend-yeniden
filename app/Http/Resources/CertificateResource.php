<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CertificateResource extends JsonResource
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
            'verification_code' => $this->verification_code,
            'issued_at' => $this->issued_at,
            'download_url' => $this->certificate_path ? asset('storage/' . $this->certificate_path) : null,
            'project' => $this->whenLoaded('project', function () {
                return [
                    'id' => $this->project?->id,
                    'name' => $this->project?->name,
                    'slug' => $this->project?->slug,
                ];
            }),
            'period' => $this->whenLoaded('period', function () {
                return [
                    'id' => $this->period?->id,
                    'name' => $this->period?->name,
                ];
            }),
        ];
    }
}
