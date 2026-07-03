<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ForumPost;
use App\Models\Participant;
use App\Models\Period;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * @group Forum
 */
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

    /** @return int[] */
    private function participantPeriodIds(int $userId, ?int $projectId = null): array
    {
        return Participant::query()
            ->where('user_id', $userId)
            ->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId))
            ->whereNotNull('period_id')
            ->pluck('period_id')
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function resolveParticipantPeriod(int $userId, int $projectId, ?int $periodId, bool $forWrite = false): ?Period
    {
        if ($periodId === null) {
            return null;
        }

        $period = Period::query()->select(['id', 'project_id', 'name', 'status'])->findOrFail($periodId);
        if ((int) $period->project_id !== $projectId) {
            throw ValidationException::withMessages([
                'period_id' => ['Secilen donem bu projeye ait degil.'],
            ]);
        }

        $hasParticipation = Participant::query()
            ->where('user_id', $userId)
            ->where('project_id', $projectId)
            ->where('period_id', $periodId)
            ->exists();
        abort_unless($hasParticipation, 403, 'Bu donem forumuna erisiminiz yok.');

        if ($forWrite && $period->status === 'completed') {
            abort(423, 'Tamamlanmis donem arsiv modundadir. Forum arsivine yeni konu veya yanit eklenemez.');
        }

        return $period;
    }

    /**
     * List forum posts for participant projects.
     *
     * Requires permission: `participant.forum.view`. Returns posts from projects and periods where the current user participates. Completed period archives can be listed but write endpoints are locked.
     *
     * @group Forum
     * @authenticated
     *
     * @queryParam project_id integer Optional project filter. Example: 1
     * @queryParam period_id integer Optional period filter. Example: 1
     * @response 200 {"posts":{"data":[{"id":1,"title":"Tanisma","content":"Merhaba","project":{"id":1,"name":"KADEME"},"replies":[]}]}}
     * @response 403 {"message":"Bu proje forumuna erisiminiz yok."}
     */
    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $projectIds = $this->participantProjectIds((int) $request->user()->id);
        $periodIds = $this->participantPeriodIds($userId);
        $validated = $request->validate([
            'project_id' => 'nullable|integer',
            'period_id' => 'nullable|integer|exists:periods,id',
        ]);

        $query = ForumPost::query()
            ->with([
                'project:id,name',
                'period:id,name,status',
                'author:id,name,surname',
                'replies' => fn ($builder) => $builder
                    ->with('author:id,name,surname')
                    ->latest(),
            ])
            ->whereIn('project_id', $projectIds)
            ->where(function ($builder) use ($periodIds) {
                $builder->whereNull('period_id');
                if ($periodIds !== []) {
                    $builder->orWhereIn('period_id', $periodIds);
                }
            })
            ->orderByDesc('is_pinned')
            ->orderByDesc('created_at');

        if (! empty($validated['project_id'])) {
            $projectId = (int) $validated['project_id'];
            abort_unless(in_array($projectId, $projectIds, true), 403, 'Bu proje forumuna erisiminiz yok.');
            $query->where('project_id', $projectId);
        }
        if (! empty($validated['period_id'])) {
            $period = Period::query()->select(['id', 'project_id'])->findOrFail((int) $validated['period_id']);
            if (! empty($validated['project_id']) && (int) $validated['project_id'] !== (int) $period->project_id) {
                throw ValidationException::withMessages([
                    'period_id' => ['Secilen donem bu projeye ait degil.'],
                ]);
            }
            abort_unless(in_array((int) $period->project_id, $projectIds, true), 403, 'Bu donem forumuna erisiminiz yok.');
            abort_unless(in_array((int) $period->id, $periodIds, true), 403, 'Bu donem forumuna erisiminiz yok.');
            $query->where('project_id', (int) $period->project_id)
                ->where(function ($builder) use ($period) {
                    $builder->whereNull('period_id')->orWhere('period_id', (int) $period->id);
                });
        }

        return response()->json([
            'posts' => $query->paginate(20),
        ]);
    }

    /**
     * Create a forum post.
     *
     * Requires permission: `participant.forum.view`. The current user must participate in the selected project and, when `period_id` is given, that period. Completed period archives cannot receive new posts.
     *
     * @group Forum
     * @authenticated
     *
     * @bodyParam project_id integer required Project id. Example: 1
     * @bodyParam period_id integer Optional period id. Example: 1
     * @bodyParam title string required Post title. Example: Tanisma
     * @bodyParam content string required Post content, max 8000 characters. Example: Merhaba herkese.
     * @response 201 {"message":"Forum konusu olusturuldu.","post":{"id":1,"title":"Tanisma","content":"Merhaba herkese."}}
     * @response 403 {"message":"Bu proje forumuna erisiminiz yok."}
     * @response 423 {"message":"Tamamlanmis donem arsiv modundadir. Forum arsivine yeni konu veya yanit eklenemez."}
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => 'required|integer|exists:projects,id',
            'period_id' => 'nullable|integer|exists:periods,id',
            'title' => 'required|string|max:255',
            'content' => 'required|string|max:8000',
        ]);

        $projectIds = $this->participantProjectIds((int) $request->user()->id);
        abort_unless(in_array((int) $validated['project_id'], $projectIds, true), 403, 'Bu proje forumuna erisiminiz yok.');
        $period = $this->resolveParticipantPeriod(
            (int) $request->user()->id,
            (int) $validated['project_id'],
            isset($validated['period_id']) ? (int) $validated['period_id'] : null,
            true
        );

        $post = ForumPost::query()->create([
            'project_id' => (int) $validated['project_id'],
            'period_id' => $period?->id,
            'user_id' => $request->user()->id,
            'title' => $validated['title'],
            'content' => $validated['content'],
            'is_pinned' => false,
        ]);

        return response()->json([
            'message' => 'Forum konusu olusturuldu.',
            'post' => $post->load(['project:id,name', 'period:id,name,status', 'author:id,name,surname']),
        ], 201);
    }

    /**
     * Reply to a forum post.
     *
     * Requires permission: `participant.forum.view`. The parent post project/period must be accessible to the current participant. Completed period archives cannot receive replies.
     *
     * @group Forum
     * @authenticated
     *
     * @urlParam postId integer required Forum post id. Example: 1
     * @bodyParam content string required Reply content, max 5000 characters. Example: Ben de katiliyorum.
     * @response 201 {"message":"Yanit eklendi.","reply":{"id":1,"content":"Ben de katiliyorum."}}
     * @response 403 {"message":"Bu proje forumuna erisiminiz yok."}
     * @response 422 {"message":"The content field is required.","errors":{"content":["The content field is required."]}}
     */
    public function reply(Request $request, int $postId): JsonResponse
    {
        $validated = $request->validate([
            'content' => 'required|string|max:5000',
        ]);

        $post = ForumPost::query()->with(['project:id', 'period:id,name,status'])->findOrFail($postId);
        $projectIds = $this->participantProjectIds((int) $request->user()->id);
        abort_unless(in_array((int) $post->project_id, $projectIds, true), 403, 'Bu proje forumuna erisiminiz yok.');
        $this->resolveParticipantPeriod(
            (int) $request->user()->id,
            (int) $post->project_id,
            $post->period_id ? (int) $post->period_id : null,
            true
        );

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
