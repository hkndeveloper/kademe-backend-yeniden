<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KpdAppointmentResource extends JsonResource
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
            'status' => $this->status,
            'start_at' => optional($this->start_at)?->toIso8601String(),
            'end_at' => optional($this->end_at)?->toIso8601String(),
            'notes' => $this->notes,
            'counselor' => $this->whenLoaded('counselor', function () {
                return [
                    'id' => $this->counselor?->id,
                    'name' => $this->counselor?->name,
                    'surname' => $this->counselor?->surname,
                    'role' => $this->counselor?->role,
                ];
            }),
            'counselee' => $this->whenLoaded('counselee', function () {
                return [
                    'id' => $this->counselee?->id,
                    'name' => $this->counselee?->name,
                    'surname' => $this->counselee?->surname,
                ];
            }),
            'room' => $this->whenLoaded('room', function () {
                return [
                    'id' => $this->room?->id,
                    'name' => $this->room?->name,
                    'description' => $this->room?->description,
                ];
            }),
        ];
    }
}
