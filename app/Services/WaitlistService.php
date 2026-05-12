<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Participant;
use Illuminate\Support\Carbon;

class WaitlistService
{
    public function __construct(
        private readonly NotificationService $notificationService
    ) {
    }

    public function expireOverdueInvitations(Application $scope): int
    {
        return $this->scopeQuery($scope)
            ->where('status', 'waitlisted')
            ->whereNotNull('waitlist_invited_at')
            ->whereNotNull('waitlist_invitation_expires_at')
            ->where('waitlist_invitation_expires_at', '<=', now())
            ->update([
                'waitlist_invited_at' => null,
                'waitlist_invitation_expires_at' => null,
            ]);
    }

    public function inviteSpecific(Application $application, ?int $senderId = null, Carbon|string|null $expiresAt = null): Application
    {
        $application->loadMissing(['project:id,name,quota', 'program:id,title,application_quota', 'user:id,email']);
        $this->expireOverdueInvitations($application);

        if ($this->hasActiveInvitation($application, $application->id)) {
            throw new \RuntimeException('Ayni kapsamda aktif yedek liste daveti zaten mevcut.');
        }

        return $this->markInvited($application, $senderId, $expiresAt);
    }

    public function inviteNextIfSeatAvailable(Application $scope, ?int $senderId = null, Carbon|string|null $expiresAt = null): ?Application
    {
        $scope->loadMissing(['project:id,name,quota', 'program:id,title,application_quota']);
        $this->expireOverdueInvitations($scope);

        if (! $this->hasAvailableSeat($scope) || $this->hasActiveInvitation($scope)) {
            return null;
        }

        $candidate = $this->scopeQuery($scope)
            ->with(['project:id,name,quota', 'program:id,title,application_quota', 'user:id,email'])
            ->where('status', 'waitlisted')
            ->whereNull('waitlist_invited_at')
            ->orderByRaw('waitlist_order IS NULL')
            ->orderBy('waitlist_order')
            ->orderBy('created_at')
            ->first();

        if (! $candidate) {
            return null;
        }

        return $this->markInvited($candidate, $senderId, $expiresAt);
    }

    public function hasAvailableSeat(Application $scope): bool
    {
        $scope->loadMissing(['project:id,quota', 'program:id,application_quota']);
        $quota = $scope->program?->application_quota ?? $scope->project?->quota;
        if ($quota === null || (int) $quota <= 0) {
            return true;
        }

        if ($scope->program_id !== null && $scope->program?->application_quota !== null) {
            $acceptedCount = $this->scopeQuery($scope)
                ->where('status', 'accepted')
                ->count();
        } else {
            $acceptedCount = Participant::query()
                ->where('project_id', $scope->project_id)
                ->where('period_id', $scope->period_id)
                ->where('status', 'active')
                ->count();
        }

        return $acceptedCount < (int) $quota;
    }

    private function hasActiveInvitation(Application $scope, ?int $exceptApplicationId = null): bool
    {
        return $this->scopeQuery($scope)
            ->when($exceptApplicationId, fn ($query) => $query->whereKeyNot($exceptApplicationId))
            ->where('status', 'waitlisted')
            ->whereNotNull('waitlist_invited_at')
            ->where(function ($query) {
                $query
                    ->whereNull('waitlist_invitation_expires_at')
                    ->orWhere('waitlist_invitation_expires_at', '>', now());
            })
            ->exists();
    }

    private function markInvited(Application $application, ?int $senderId, Carbon|string|null $expiresAt): Application
    {
        $expiresAt = $expiresAt ? Carbon::parse($expiresAt) : now()->addDays(3);

        $application->update([
            'waitlist_invited_at' => now(),
            'waitlist_invitation_expires_at' => $expiresAt,
        ]);

        $application->loadMissing(['project:id,name', 'user:id,email']);
        if ($application->user?->email) {
            $this->notificationService->sendEmail(
                [$application->user->email],
                'Yedek listeden davet edildiniz',
                'Proje: '.($application->project?->name ?? '-')."\nYedek listeden davet edildiniz. Son yanit tarihi: {$expiresAt}",
                $application->project_id,
                $senderId
            );
        }

        return $application->fresh(['user:id,name,surname,email', 'project:id,name', 'period:id,name', 'program:id,title']);
    }

    private function scopeQuery(Application $scope)
    {
        return Application::query()
            ->where('project_id', $scope->project_id)
            ->where('period_id', $scope->period_id)
            ->when(
                $scope->program_id,
                fn ($query) => $query->where('program_id', $scope->program_id),
                fn ($query) => $query->whereNull('program_id')
            );
    }
}
