<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationCandidate;
use App\Models\Period;
use App\Models\Project;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

class ApplicationSubmissionService
{
    public function runLocked(User $user, Project $project, Closure $submission): Application
    {
        return DB::transaction(function () use ($user, $project, $submission) {
            // Use the same project -> period -> user order as application decisions.
            [$currentProject, $period] = $this->lockProjectAndPeriod($project);
            $currentUser = User::query()->lockForUpdate()->findOrFail($user->id);

            return $submission($currentUser, $currentProject, $period);
        });
    }

    public function runLockedForGuest(Project $project, Closure $resolveUser, Closure $submission): Application
    {
        return DB::transaction(function () use ($project, $resolveUser, $submission) {
            // Resolve a verified candidate, not a login account, inside this transaction.
            [$currentProject, $period] = $this->lockProjectAndPeriod($project);
            $user = $resolveUser();
            $currentUser = $user instanceof ApplicationCandidate
                ? ApplicationCandidate::query()->lockForUpdate()->findOrFail($user->id)
                : User::query()->lockForUpdate()->findOrFail($user->id);

            return $submission($currentUser, $currentProject, $period);
        });
    }

    private function lockProjectAndPeriod(Project $project): array
    {
        $currentProject = Project::query()->lockForUpdate()->findOrFail($project->id);

        return [$currentProject, $this->lockCurrentPeriod($currentProject)];
    }

    private function lockCurrentPeriod(Project $project): ?Period
    {
        if ($project->current_period_id !== null) {
            return $project->currentPeriod()->lockForUpdate()->first();
        }

        // Keep the existing currentPeriodOrLegacy fallback and configuration.
        if (config('period_lifecycle.enforce_current_period_pointer', false)) {
            return null;
        }

        return $project->periods()
            ->whereIn('status', ['active', 'closing'])
            ->orderByDesc('start_date')
            ->lockForUpdate()
            ->first();
    }
}
