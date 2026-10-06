<?php

namespace App\Services;

use App\Models\Program;
use App\Models\ProjectModule;
use App\Models\ProjectTraining;
use App\Models\TrainingEnrollment;
use App\Models\User;

class TrainingAccessService
{
    public function canAccessProgram(User $user, Program $program): bool
    {
        if ($program->project_module_id) {
            $module = ProjectModule::find($program->project_module_id);
            if (! $module || $module->project_id !== $program->project_id || $module->period_id !== $program->period_id) {
                return false;
            }
            if ($module->training_id) {
                return TrainingEnrollment::where('user_id', $user->id)->where('training_id', $module->training_id)->where('status', 'active')->exists();
            }
        }
        $ids = ProjectTraining::where('project_id', $program->project_id)->where('period_id', $program->period_id)->pluck('id');

        return ! TrainingEnrollment::where('user_id', $user->id)->whereIn('training_id', $ids)->where('status', 'active')->exists();
    }
}
