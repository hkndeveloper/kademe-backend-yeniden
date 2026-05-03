<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\ApplicationForm;
use App\Models\Project;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    /**
     * Ziyaretcilerin ve ogrencilerin gorebilecegi acik projeleri listeler.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:100',
            'type' => 'nullable|string|max:100',
        ]);

        $projects = Project::where('status', 'active')
            ->with(['periods' => function ($query) {
                $query->where('status', 'active');
            }])
            ->when(! empty($validated['search']), function ($query) use ($validated) {
                $search = $validated['search'];
                $query->where(function ($builder) use ($search) {
                    $builder
                        ->where('name', 'like', '%' . $search . '%')
                        ->orWhere('type', 'like', '%' . $search . '%')
                        ->orWhere('short_description', 'like', '%' . $search . '%')
                        ->orWhere('description', 'like', '%' . $search . '%');
                });
            })
            ->when(! empty($validated['type']), fn ($query) => $query->where('type', $validated['type']))
            ->orderBy('name')
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
