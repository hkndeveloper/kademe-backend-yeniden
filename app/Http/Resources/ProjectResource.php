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

    private function publicStudentPayload($participant): array
    {
        $user = $participant->user;

        return [
            'id' => $participant->id,
            'name' => trim(($user?->name ?? '') . ' ' . ($user?->surname ?? '')),
            'university' => $user?->university,
            'department' => $user?->department,
            'image' => $this->mediaUrl($user?->profile_photo_path),
        ];
    }

    private function publicAlumniPayload($participant): array
    {
        return array_merge($this->publicStudentPayload($participant), [
            'year' => optional($participant->graduated_at)->format('Y') ?? 'Mezun',
            'job' => $participant->graduation_note,
        ]);
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
                    ->map(fn ($participant) => $this->publicStudentPayload($participant))
                    ->values()
                    ->all();
            }),
            'active_student_groups' => $this->whenLoaded('participants', function () {
                return $this->participants
                    ->where('status', 'active')
                    ->groupBy(fn ($participant) => optional($participant->enrolled_at)->format('Y')
                        ?? optional($participant->created_at)->format('Y')
                        ?? 'Aktif')
                    ->map(fn ($participants, string $year) => [
                        'year' => $year,
                        'students' => $participants
                            ->map(fn ($participant) => $this->publicStudentPayload($participant))
                            ->values()
                            ->all(),
                    ])
                    ->sortByDesc(fn (array $group) => is_numeric($group['year']) ? (int) $group['year'] : 0)
                    ->values()
                    ->all();
            }),
            'alumni' => $this->whenLoaded('participants', function () {
                return $this->participants
                    ->filter(fn ($participant) => ! is_null($participant->graduated_at) || $participant->graduation_status === 'graduated')
                    ->map(fn ($participant) => $this->publicAlumniPayload($participant))
                    ->values()
                    ->all();
            }),
            'alumni_groups' => $this->whenLoaded('participants', function () {
                return $this->participants
                    ->filter(fn ($participant) => ! is_null($participant->graduated_at) || $participant->graduation_status === 'graduated')
                    ->groupBy(fn ($participant) => optional($participant->graduated_at)->format('Y') ?? 'Mezun')
                    ->map(fn ($participants, string $year) => [
                        'year' => $year,
                        'students' => $participants
                            ->map(fn ($participant) => $this->publicAlumniPayload($participant))
                            ->values()
                            ->all(),
                    ])
                    ->sortByDesc(fn (array $group) => is_numeric($group['year']) ? (int) $group['year'] : 0)
                    ->values()
                    ->all();
            }),
        ];
    }
}
