<?php

namespace App\Services;

use App\Models\ApplicationForm;
use App\Models\Period;
use App\Models\Program;
use App\Models\Project;

class ApplicationFormResolver
{
    public function forApplication(Project $project, ?Period $period, ?Program $program = null): ?ApplicationForm
    {
        if ($program) {
            $form = ApplicationForm::query()
                ->where('project_id', $project->id)
                ->where('program_id', $program->id)
                ->where('period_id', $period?->id)
                ->where('is_active', true)
                ->latest('id')
                ->first();

            if ($form) {
                return $form;
            }
        }

        if ($period) {
            $form = ApplicationForm::query()
                ->where('project_id', $project->id)
                ->where('period_id', $period->id)
                ->whereNull('program_id')
                ->where('is_active', true)
                ->latest('id')
                ->first();

            if ($form) {
                return $form;
            }
        }

        return ApplicationForm::query()
            ->where('project_id', $project->id)
            ->whereNull('period_id')
            ->whereNull('program_id')
            ->where('is_active', true)
            ->latest('id')
            ->first();
    }
}
