<?php

namespace App\Http\Resources;

use App\Support\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    private function mediaUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return MediaStorage::url($path);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type,
            'short_description' => $this->short_description,
            'cover_image' => $this->mediaUrl($this->cover_image_path),
            'status' => $this->status,
            'is_application_open' => (bool) $this->application_open,
            'description' => $this->when(! is_null($this->description), $this->description),
            'gallery' => $this->when(
                ! is_null($this->gallery_paths),
                fn () => collect($this->gallery_paths)
                    ->filter()
                    ->map(fn ($path) => $this->mediaUrl($path))
                    ->values()
                    ->all()
            ),
            'next_application_date' => $this->when(
                ! is_null($this->next_application_date),
                fn () => optional($this->next_application_date)->format('Y-m-d')
            ),
            'has_interview' => (bool) $this->has_interview,
            'quota' => $this->quota,
            'active_period' => $this->whenLoaded('periods', function () {
                return $this->periods->where('status', 'active')->first();
            }),
            'active_students' => $this->whenLoaded('participants', function () {
                return $this->participants
                    ->where('status', 'active')
                    ->map(function ($participant) {
                        $user = $participant->user;

                        return [
                            'id' => $participant->id,
                            'name' => trim(($user?->name ?? '') . ' ' . ($user?->surname ?? '')),
                            'university' => $user?->university,
                            'department' => $user?->department,
                            'image' => $this->mediaUrl($user?->profile_photo_path),
                        ];
                    })
                    ->values()
                    ->all();
            }),
            'alumni' => $this->whenLoaded('participants', function () {
                return $this->participants
                    ->filter(fn ($participant) => ! is_null($participant->graduated_at) || $participant->graduation_status === 'graduated')
                    ->map(function ($participant) {
                        $user = $participant->user;

                        return [
                            'id' => $participant->id,
                            'year' => optional($participant->graduated_at)->format('Y') ?? 'Mezun',
                            'name' => trim(($user?->name ?? '') . ' ' . ($user?->surname ?? '')),
                            'university' => $user?->university,
                            'job' => $participant->graduation_note,
                            'image' => $this->mediaUrl($user?->profile_photo_path),
                        ];
                    })
                    ->values()
                    ->all();
            }),
        ];
    }
}
