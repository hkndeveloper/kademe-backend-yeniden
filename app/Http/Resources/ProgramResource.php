<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProgramResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isCoordinator = $request->user()?->hasRole('super_admin|coordinator');

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'location' => $this->location,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'start_at' => $this->start_at,
            'end_at' => $this->end_at,
            'status' => $this->status,
            'credit_deduction' => $this->credit_deduction,
            // Sadece Yetkili Görür:
            'qr_token' => $this->when($isCoordinator, $this->qr_token),
            'qr_expires_at' => $this->when($isCoordinator, $this->qr_expires_at),
            
            // İlişkiler
            'project' => new ProjectResource($this->whenLoaded('project')),
        ];
    }
}
