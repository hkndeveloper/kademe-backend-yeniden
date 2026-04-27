<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VolunteerOpportunityResource;
use App\Models\VolunteerApplication;
use App\Models\VolunteerOpportunity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VolunteerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $opportunities = VolunteerOpportunity::query()
            ->with([
                'project:id,name,slug,type',
                'applications' => fn ($query) => $query
                    ->where('user_id', $user->id)
                    ->select([
                        'id',
                        'volunteer_opportunity_id',
                        'status',
                        'motivation_text',
                        'notes',
                        'evaluation_note',
                        'created_at',
                    ]),
            ])
            ->where('status', 'open')
            ->orderBy('start_at')
            ->get();

        $myApplications = VolunteerApplication::query()
            ->with(['opportunity.project:id,name,slug,type'])
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(function (VolunteerApplication $application) {
                return [
                    'id' => $application->id,
                    'status' => $application->status,
                    'motivation_text' => $application->motivation_text,
                    'notes' => $application->notes,
                    'evaluation_note' => $application->evaluation_note,
                    'created_at' => optional($application->created_at)?->toIso8601String(),
                    'opportunity' => [
                        'id' => $application->opportunity?->id,
                        'title' => $application->opportunity?->title,
                        'project' => $application->opportunity?->project ? [
                            'id' => $application->opportunity->project->id,
                            'name' => $application->opportunity->project->name,
                            'slug' => $application->opportunity->project->slug,
                            'type' => $application->opportunity->project->type,
                        ] : null,
                    ],
                ];
            })
            ->values();

        return response()->json([
            'opportunities' => VolunteerOpportunityResource::collection($opportunities),
            'my_applications' => $myApplications,
        ]);
    }

    public function apply(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'motivation_text' => 'required|string|min:20|max:4000',
            'notes' => 'nullable|string|max:2000',
        ]);

        $opportunity = VolunteerOpportunity::query()
            ->with('project:id,name,slug,type')
            ->where('status', 'open')
            ->findOrFail($id);

        $existingApplication = VolunteerApplication::query()
            ->where('volunteer_opportunity_id', $opportunity->id)
            ->where('user_id', $request->user()->id)
            ->first();

        if ($existingApplication) {
            return response()->json([
                'message' => 'Bu gonullu ilanina daha once basvurdun.',
            ], 422);
        }

        $acceptedCount = VolunteerApplication::query()
            ->where('volunteer_opportunity_id', $opportunity->id)
            ->where('status', 'accepted')
            ->count();

        if ($opportunity->quota !== null && $acceptedCount >= $opportunity->quota) {
            return response()->json([
                'message' => 'Bu gonullu ilani icin kontenjan dolu.',
            ], 422);
        }

        $application = VolunteerApplication::create([
            'volunteer_opportunity_id' => $opportunity->id,
            'user_id' => $request->user()->id,
            'motivation_text' => $validated['motivation_text'],
            'notes' => $validated['notes'] ?? null,
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Gonullu basvurun alindi.',
            'application' => [
                'id' => $application->id,
                'status' => $application->status,
                'motivation_text' => $application->motivation_text,
                'notes' => $application->notes,
                'evaluation_note' => $application->evaluation_note,
                'created_at' => optional($application->created_at)?->toIso8601String(),
                'opportunity' => [
                    'id' => $opportunity->id,
                    'title' => $opportunity->title,
                    'project' => $opportunity->project ? [
                        'id' => $opportunity->project->id,
                        'name' => $opportunity->project->name,
                        'slug' => $opportunity->project->slug,
                        'type' => $opportunity->project->type,
                    ] : null,
                ],
            ],
        ], 201);
    }
}
