<?php

namespace App\Services;

use App\Models\ApplicationWindow;
use App\Models\Period;
use App\Models\Project;
use Illuminate\Support\Carbon;

class ApplicationIntakeService
{
    public function windowFor(Project $project, ?Period $period): ?ApplicationWindow
    {
        if (! $period) {
            return null;
        }

        if ($project->relationLoaded('applicationWindows')) {
            return $project->applicationWindows->firstWhere('period_id', $period->id);
        }

        return ApplicationWindow::query()
            ->where('project_id', $project->id)
            ->where('period_id', $period->id)
            ->first();
    }

    public function isOpen(Project $project, ?Period $period, ?ApplicationWindow $window = null, ?Carbon $at = null): bool
    {
        if (! $period || $period->status !== 'active' || $project->status !== 'active') {
            return false;
        }

        $at ??= now();
        $window ??= $this->windowFor($project, $period);

        if (! $window) {
            return (bool) $project->application_open
                && (! $project->application_start_at || $project->application_start_at->lte($at))
                && (! $project->application_end_at || $project->application_end_at->gte($at));
        }

        return $window->is_open
            && (! $window->starts_at || $window->starts_at->lte($at))
            && (! $window->ends_at || $window->ends_at->gte($at));
    }

    public function status(Project $project, ?Period $period, ?ApplicationWindow $window = null): string
    {
        if ($project->status !== 'active') {
            return 'project_inactive';
        }
        if (! $period || $period->status !== 'active') {
            return 'period_inactive';
        }

        $window ??= $this->windowFor($project, $period);
        if (! $window) {
            return $this->isOpen($project, $period) ? 'open' : 'closed';
        }
        if (! $window->is_open) {
            return 'closed';
        }
        if ($window->starts_at && $window->starts_at->isFuture()) {
            return 'scheduled';
        }
        if ($window->ends_at && $window->ends_at->isPast()) {
            return 'expired';
        }

        return 'open';
    }

    public function hasInterview(Project $project, ?ApplicationWindow $window): bool
    {
        return (bool) ($window?->has_interview ?? $project->has_interview);
    }

    public function quota(Project $project, ?ApplicationWindow $window): ?int
    {
        $quota = $window?->quota ?? $project->quota;

        return $quota === null ? null : (int) $quota;
    }
}
