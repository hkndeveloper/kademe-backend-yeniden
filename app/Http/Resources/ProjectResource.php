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

    private function galleryItems(): array
    {
        $periods = $this->relationLoaded('periods')
            ? $this->periods->keyBy('id')
            : collect();

        return collect($this->gallery_paths ?? [])
            ->map(function ($item) use ($periods) {
                if (is_string($item)) {
                    $item = ['path' => $item];
                }

                if (! is_array($item)) {
                    return null;
                }

                $path = trim((string) ($item['path'] ?? $item['url'] ?? ''));
                if ($path === '') {
                    return null;
                }

                $periodId = isset($item['period_id']) && is_numeric($item['period_id'])
                    ? (int) $item['period_id']
                    : null;

                return [
                    'path' => $path,
                    'url' => $this->mediaUrl($path),
                    'caption' => $item['caption'] ?? null,
                    'year' => $item['year'] ?? null,
                    'period_id' => $periodId,
                    'period_name' => $periodId ? ($periods->get($periodId)?->name) : null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function publicStudentPayload($participant): array
    {
        $user = $participant->user;
        $photoVisible = (bool) ($user?->public_photo_visible ?? false);
        $period = $participant->relationLoaded('period') ? $participant->period : null;

        return [
            'id' => $participant->id,
            'name' => trim(($user?->name ?? '') . ' ' . ($user?->surname ?? '')),
            'university' => $user?->university,
            'department' => $user?->department,
            'class_year' => $user?->class_year,
            'image' => $photoVisible ? $this->mediaUrl($user?->profile_photo_path) : null,
            'period_name' => $period?->name,
            'period' => $period ? [
                'id' => $period->id,
                'name' => $period->name,
                'status' => $period->status,
            ] : null,
        ];
    }

    private function publicAlumniPayload($participant): array
    {
        return array_merge($this->publicStudentPayload($participant), [
            'year' => optional($participant->graduated_at)->format('Y') ?? 'Mezun',
            'job' => $participant->graduation_note,
        ]);
    }

    private function activeStudentGroupLabel($participant): string
    {
        $period = $participant->relationLoaded('period') ? $participant->period : null;

        return $period?->name
            ?? optional($participant->enrolled_at)->format('Y')
            ?? optional($participant->created_at)->format('Y')
            ?? 'Aktif';
    }

    private function isGraduatedParticipant($participant): bool
    {
        return $participant->status === 'graduated'
            || $participant->graduation_status === 'graduated'
            || ! is_null($participant->graduated_at);
    }

    private function managementParticipantSummary(): array
    {
        $participants = $this->relationLoaded('participants') ? $this->participants : collect();
        $activePeriod = $this->relationLoaded('periods') ? $this->periods->where('status', 'active')->first() : null;

        $activeAll = $participants
            ->where('status', 'active')
            ->reject(fn ($participant) => $this->isGraduatedParticipant($participant));

        $activeCurrentPeriod = $activePeriod
            ? $activeAll->where('period_id', $activePeriod->id)->count()
            : 0;

        return [
            'total' => $participants->count(),
            'active' => $activeCurrentPeriod,
            'active_all_periods' => $activeAll->count(),
            'graduates' => $participants
                ->filter(fn ($participant) => $this->isGraduatedParticipant($participant))
                ->count(),
        ];
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
                fn () => collect($this->galleryItems())
                    ->pluck('url')
                    ->filter()
                    ->values()
                    ->all()
            ),
            'gallery_items' => $this->when(
                ! is_null($this->gallery_paths),
                fn () => $this->galleryItems()
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
            'periods' => $this->whenLoaded('periods', function () {
                return $this->periods
                    ->sortByDesc(fn ($period) => optional($period->start_date)->timestamp ?? 0)
                    ->map(fn ($period) => [
                        'id' => $period->id,
                        'name' => $period->name,
                        'status' => $period->status,
                        'start_date' => optional($period->start_date)->format('Y-m-d'),
                        'end_date' => optional($period->end_date)->format('Y-m-d'),
                    ])
                    ->values()
                    ->all();
            }),
            'participant_summary' => $this->whenLoaded('participants', fn () => $this->managementParticipantSummary()),
            'active_students' => $this->whenLoaded('participants', function () {
                return $this->participants
                    ->where('status', 'active')
                    ->filter(fn ($participant) => (bool) ($participant->user?->public_profile_visible ?? false))
                    ->map(fn ($participant) => $this->publicStudentPayload($participant))
                    ->values()
                    ->all();
            }),
            'active_student_groups' => $this->whenLoaded('participants', function () {
                return $this->participants
                    ->where('status', 'active')
                    ->filter(fn ($participant) => (bool) ($participant->user?->public_profile_visible ?? false))
                    ->groupBy(fn ($participant) => $this->activeStudentGroupLabel($participant))
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
                    ->filter(fn ($participant) =>
                        (bool) ($participant->user?->public_alumni_visible ?? false)
                        && (! is_null($participant->graduated_at) || $participant->graduation_status === 'graduated')
                    )
                    ->map(fn ($participant) => $this->publicAlumniPayload($participant))
                    ->values()
                    ->all();
            }),
            'alumni_groups' => $this->whenLoaded('participants', function () {
                return $this->participants
                    ->filter(fn ($participant) =>
                        (bool) ($participant->user?->public_alumni_visible ?? false)
                        && (! is_null($participant->graduated_at) || $participant->graduation_status === 'graduated')
                    )
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
