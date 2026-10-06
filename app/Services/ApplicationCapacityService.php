<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Participant;
use App\Models\TrainingEnrollment;

class ApplicationCapacityService
{
    public function hasAvailableSeat(Application $application, bool $forApplicant = false): bool
    {
        if ($application->training_id !== null) {
            $quota = $application->training()->value('quota');
            if ($quota === null) {
                return true;
            }
            $enrolled = TrainingEnrollment::where('training_id', $application->training_id)->where('status', 'active')
                ->when($forApplicant && $application->user_id, fn ($query) => $query->where('user_id', '!=', $application->user_id))->count();
            $reserved = Application::where('training_id', $application->training_id)->where('status', 'waitlisted')->whereNotNull('waitlist_invited_at')
                ->when($forApplicant, fn ($query) => $query->whereKeyNot($application->id))
                ->where(function ($query) {
                    $query->whereIn('waitlist_invitation_delivery_status', ['pending', 'failed', 'unknown'])
                        ->orWhere(function ($query) {
                            $query->where(function ($query) {
                                $query->whereNull('waitlist_invitation_delivery_status')->orWhere('waitlist_invitation_delivery_status', 'sent');
                            })
                                ->where(function ($query) {
                                    $query->whereNull('waitlist_invitation_expires_at')->orWhere('waitlist_invitation_expires_at', '>', now());
                                });
                        });
                })->count();

            return $enrolled + $reserved < $quota;
        }
        // Partial relations loaded by list/detail endpoints may omit quota columns.
        // Read current values for every decision rather than trusting those relations.
        $programQuota = $application->program_id !== null
            ? $application->program()->value('application_quota')
            : null;
        $windowQuota = $application->application_window_id !== null
            ? $application->applicationWindow()->value('quota')
            : null;
        $quota = $programQuota ?? $windowQuota ?? $application->project()->value('quota');
        if ($quota === null || (int) $quota <= 0) {
            return true;
        }

        // An explicit program quota overrides the period/project quota, as before.
        $occupants = $programQuota !== null
            ? Application::query()
                ->where('program_id', $application->program_id)
                ->where('status', 'accepted')
            : Participant::query()->where('status', 'active');

        $occupiedCount = $occupants
            ->where('project_id', $application->project_id)
            ->where('period_id', $application->period_id)
            ->when($forApplicant && $application->user_id, fn ($query) => $query->where('user_id', '!=', $application->user_id))
            ->count();

        // A delivered invitation holds the remaining place until it is answered
        // or expires. Failed/uncertain delivery stays reserved for review.
        $invitations = Application::query()
            ->where('project_id', $application->project_id)
            ->where('period_id', $application->period_id)
            ->when($programQuota !== null, fn ($query) => $query->where('program_id', $application->program_id))
            ->where('status', 'waitlisted')
            ->whereNotNull('waitlist_invited_at')
            ->when($forApplicant, fn ($query) => $query->whereKeyNot($application->id))
            ->where(function ($query) {
                $query->whereIn('waitlist_invitation_delivery_status', ['pending', 'failed', 'unknown'])
                    ->orWhere(function ($query) {
                        $query->where(function ($query) {
                            $query->whereNull('waitlist_invitation_delivery_status')
                                ->orWhere('waitlist_invitation_delivery_status', 'sent');
                        })->where(function ($query) {
                            $query->whereNull('waitlist_invitation_expires_at')
                                ->orWhere('waitlist_invitation_expires_at', '>', now());
                        });
                    });
            });

        // Someone already occupying a period place must not also reserve one.
        if ($programQuota === null) {
            $invitations->where(function ($query) use ($application) {
                $query->whereNull('user_id')->orWhereNotIn('user_id', Participant::query()
                    ->select('user_id')
                    ->where('project_id', $application->project_id)
                    ->where('period_id', $application->period_id)
                    ->where('status', 'active'));
            });
        }

        return $occupiedCount + $invitations->count() < (int) $quota;
    }
}
