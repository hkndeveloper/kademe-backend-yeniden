<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\ApplicationForm;
use App\Models\Project;

class ProjectController extends Controller
{
    /**
     * Ziyaretcilerin ve ogrencilerin gorebilecegi acik projeleri listeler.
     */
    public function index()
    {
        $projects = Project::where('status', 'active')
            ->with(['periods' => function ($query) {
                $query->where('status', 'active');
            }])
            ->get();

        return response()->json([
            'projects' => ProjectResource::collection($projects),
        ]);
    }

    /**
     * Proje detayini ve basvuru formunu getirir.
     */
    public function show($slug)
    {
        $project = Project::where('slug', $slug)
            ->where('status', 'active')
            ->with([
                'periods',
                'participants.user',
            ])
            ->firstOrFail();

        $applicationForm = null;
        if ($project->application_open) {
            $applicationForm = ApplicationForm::where('project_id', $project->id)
                ->where('is_active', true)
                ->latest()
                ->first();
        }

        $currentPeriod = $project->periods->where('status', 'active')->first();

        return response()->json([
            'project' => new ProjectResource($project),
            'current_period' => $currentPeriod,
            'application_form' => $applicationForm,
        ]);
    }
}
