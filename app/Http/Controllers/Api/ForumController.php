<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ForumPost;
use App\Models\Participant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ForumController extends Controller
{
    /** @return int[] */
    private function participantProjectIds(int $userId): array
    {
        return Participant::query()
            ->where('user_id', $userId)
            ->pluck('project_id')
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public function index(Request $request): JsonResponse
    {
        $projectIds = $this->participantProjectIds((int) $request->user()->id);
        $validated = $request->validate([
            'project_id' => 'nullable|integer',
        ]);

        $query = ForumPost::query()
            ->with([
                'project:id,name',
                'author:id,name,surname',
                'replies' => fn ($builder) => $builder
                    ->with('author:id,name,surname')
                    ->latest(),
            ])
            ->whereIn('project_id', $projectIds)
            ->orderByDesc('is_pinned')
            ->orderByDesc('created_at');

        if (! empty($validated['project_id'])) {
            $projectId = (int) $validated['project_id'];
            abort_unless(in_array($projectId, $projectIds, true), 403, 'Bu proje forumuna erisiminiz yok.');
            $query->where('project_id', $projectId);
        }

        return response()->json([
            'posts' => $query->paginate(20),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => 'required|integer|exists:projects,id',
            'title' => 'required|string|max:255',
            'content' => 'required|string|max:8000',
        ]);

        $projectIds = $this->participantProjectIds((int) $request->user()->id);
        abort_unless(in_array((int) $validated['project_id'], $projectIds, true), 403, 'Bu proje forumuna erisiminiz yok.');

        $post = ForumPost::query()->create([
            'project_id' => (int) $validated['project_id'],
            'user_id' => $request->user()->id,
            'title' => $validated['title'],
            'content' => $validated['content'],
            'is_pinned' => false,
        ]);

        return response()->json([
            'message' => 'Forum konusu olusturuldu.',
            'post' => $post->load(['project:id,name', 'author:id,name,surname']),
        ], 201);
    }

    public function reply(Request $request, int $postId): JsonResponse
    {
        $validated = $request->validate([
            'content' => 'required|string|max:5000',
        ]);

        $post = ForumPost::query()->with('project:id')->findOrFail($postId);
        $projectIds = $this->participantProjectIds((int) $request->user()->id);
        abort_unless(in_array((int) $post->project_id, $projectIds, true), 403, 'Bu proje forumuna erisiminiz yok.');

        $reply = $post->replies()->create([
            'user_id' => $request->user()->id,
            'content' => $validated['content'],
        ]);

        return response()->json([
            'message' => 'Yanıt eklendi.',
            'reply' => $reply->load('author:id,name,surname'),
        ], 201);
    }
}
