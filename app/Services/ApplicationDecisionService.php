<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationCandidate;
use App\Models\Period;
use App\Models\Project;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

class ApplicationDecisionService
{
    public function runLocked(Application $application, Closure $decision): Application
    {
        return DB::transaction(function () use ($application, $decision) {
            // Match the project -> period order used by period lifecycle operations.
            // The project lock also covers programs sharing the same period quota.
            Project::query()->lockForUpdate()->findOrFail($application->project_id);
            if ($application->period_id !== null) {
                Period::query()->lockForUpdate()->findOrFail($application->period_id);
            }

            // Serialize this applicant's acceptances even across different projects.
            if ($application->candidate_id) {
                ApplicationCandidate::query()->lockForUpdate()->findOrFail($application->candidate_id);
            }
            if ($application->user_id) {
                User::query()->lockForUpdate()->findOrFail($application->user_id);
            }
            $current = Application::query()->lockForUpdate()->findOrFail($application->id);
            $current->load([
                'project:id,name,has_interview,quota',
                'period',
                'applicationWindow:id,has_interview,quota',
                'program:id,title,project_id,period_id,start_at,end_at,status,target_audience,application_quota',
            ]);

            $decision($current);

            return $current;
        });
    }
}
