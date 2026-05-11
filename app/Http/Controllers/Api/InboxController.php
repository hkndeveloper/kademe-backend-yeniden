<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AlumniOpportunity;
use App\Models\Announcement;
use App\Models\ForumPost;
use App\Models\InboxMessageState;
use App\Models\Participant;
use App\Models\User;
use App\Services\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InboxController extends Controller
{
    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {}

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
    private function inboxProjectIds(User $user): array
    {
        return collect([
            ...$this->participantProjectIds((int) $user->id),
            ...$this->permissionResolver->projectIdsForPermission($user, 'announcements.view'),
            ...$this->permissionResolver->projectIdsForPermission($user, 'projects.participants.view'),
            ...$this->permissionResolver->projectIdsForPermission($user, 'projects.view'),
        ])
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function userHasPanelAnnouncementView(User $user): bool
    {
        return $this->permissionResolver->hasPermission($user, 'announcements.view');
    }

    private function announcementVisibleToUser(User $user, Announcement $announcement): bool
    {
        if ($this->userHasPanelAnnouncementView($user)) {
            if ($this->permissionResolver->hasGlobalScope($user, 'announcements.view')) {
                return true;
            }

            if ($announcement->project_id !== null) {
                return in_array((int) $announcement->project_id, $this->permissionResolver->projectIdsForPermission($user, 'announcements.view'), true);
            }

            return (int) $announcement->created_by === (int) $user->id;
        }

        $projectIds = $this->participantProjectIds((int) $user->id);
        $role = (string) ($user->role ?? '');

        $roleAllowed = empty($announcement->target_roles)
            || in_array($role, $announcement->target_roles ?? [], true);
        $projectAllowed = $announcement->project_id === null || in_array((int) $announcement->project_id, $projectIds, true);
        $timeAllowed = ($announcement->published_at === null || $announcement->published_at->lte(now()))
            && ($announcement->expires_at === null || $announcement->expires_at->gte(now()));

        return $roleAllowed && $projectAllowed && $timeAllowed;
    }

    private function opportunityVisibleToUser(User $user, AlumniOpportunity $opportunity): bool
    {
        if ($this->userHasPanelAnnouncementView($user)) {
            if ($this->permissionResolver->hasGlobalScope($user, 'announcements.view')) {
                return true;
            }

            if ($opportunity->project_id !== null) {
                return in_array((int) $opportunity->project_id, $this->permissionResolver->projectIdsForPermission($user, 'announcements.view'), true);
            }

            return (int) $opportunity->created_by === (int) $user->id;
        }

        $projectIds = $this->participantProjectIds((int) $user->id);
        $role = (string) ($user->role ?? '');
        $audience = $opportunity->target_audience ?? [];

        $audienceAllowed = $audience === [] || in_array($role, $audience, true);
        $projectAllowed = $opportunity->project_id === null || in_array((int) $opportunity->project_id, $projectIds, true);
        $timeAllowed = ($opportunity->published_at === null || $opportunity->published_at->lte(now()))
            && ($opportunity->expires_at === null || $opportunity->expires_at->gte(now()));

        return $audienceAllowed && $projectAllowed && $timeAllowed;
    }

    private function forumPostVisibleToUser(User $user, ForumPost $post): bool
    {
        return in_array((int) $post->project_id, $this->inboxProjectIds($user), true);
    }

    public function recipientMessages(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => 'nullable|integer',
            'category' => 'nullable|string|max:100',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'type' => 'nullable|in:announcement,opportunity,forum_post',
            'unread_only' => 'nullable|boolean',
            'starred_only' => 'nullable|boolean',
            'pinned_only' => 'nullable|boolean',
        ]);

        $user = $request->user();
        $userId = (int) $user->id;
        $projectIds = $this->inboxProjectIds($user);
        $projectFilter = isset($validated['project_id']) ? (int) $validated['project_id'] : null;
        if ($projectFilter !== null) {
            abort_unless(in_array($projectFilter, $projectIds, true), 403, 'Bu proje inbox filtresi icin yetkiniz yok.');
        }

        $messages = collect();
        $panelCanViewAnnouncements = $this->userHasPanelAnnouncementView($user);

        if (($validated['type'] ?? null) === null || $validated['type'] === 'announcement') {
            $announcementQuery = Announcement::query()
                ->with(['project:id,name'])
                ->where(function ($q) use ($projectIds, $projectFilter) {
                    if ($projectFilter !== null) {
                        $q->where('project_id', $projectFilter)->orWhereNull('project_id');
                    } else {
                        $q->whereIn('project_id', $projectIds)->orWhereNull('project_id');
                    }
                })
                ->where(function ($q) {
                    $q->whereNull('published_at')->orWhere('published_at', '<=', now());
                })
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>=', now());
                });

            if (! empty($validated['category'])) {
                $announcementQuery->where('category', $validated['category']);
            }
            if (! empty($validated['from'])) {
                $announcementQuery->whereDate('published_at', '>=', $validated['from']);
            }
            if (! empty($validated['to'])) {
                $announcementQuery->whereDate('published_at', '<=', $validated['to']);
            }

            if (! $panelCanViewAnnouncements) {
                $announcementQuery->where(function ($q) use ($user) {
                    $q->whereNull('target_roles')
                        ->orWhereJsonLength('target_roles', 0)
                        ->orWhereJsonContains('target_roles', $user->role);
                });
            } elseif (! $this->permissionResolver->hasGlobalScope($user, 'announcements.view')) {
                $allowedProjectIds = $this->permissionResolver->projectIdsForPermission($user, 'announcements.view');
                $announcementQuery->where(function ($q) use ($allowedProjectIds, $user) {
                    $q->whereIn('project_id', $allowedProjectIds)
                        ->orWhere('created_by', $user->id);
                });
            }

            $messages = $messages->concat(
                $announcementQuery->get()->map(function (Announcement $item) {
                    return [
                        'type' => 'announcement',
                        'source_type' => Announcement::class,
                        'source_id' => $item->id,
                        'title' => $item->title,
                        'content' => $item->content,
                        'category' => $item->category,
                        'project' => $item->project ? ['id' => $item->project->id, 'name' => $item->project->name] : null,
                        'timestamp' => optional($item->published_at ?? $item->created_at)?->toIso8601String(),
                    ];
                })
            );
        }

        if (($validated['type'] ?? null) === null || $validated['type'] === 'opportunity') {
            $role = (string) ($user->role ?? '');
            $opportunityQuery = AlumniOpportunity::query()
                ->with(['project:id,name'])
                ->where(function ($q) use ($projectIds, $projectFilter) {
                    if ($projectFilter !== null) {
                        $q->where('project_id', $projectFilter)->orWhereNull('project_id');
                    } else {
                        $q->whereIn('project_id', $projectIds)->orWhereNull('project_id');
                    }
                })
                ->where(function ($q) {
                    $q->whereNull('published_at')->orWhere('published_at', '<=', now());
                })
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>=', now());
                });

            if (! empty($validated['from'])) {
                $opportunityQuery->whereDate('published_at', '>=', $validated['from']);
            }
            if (! empty($validated['to'])) {
                $opportunityQuery->whereDate('published_at', '<=', $validated['to']);
            }

            if (! $panelCanViewAnnouncements) {
                $opportunityQuery->where(function ($q) use ($role) {
                    $q->whereNull('target_audience')
                        ->orWhereJsonLength('target_audience', 0)
                        ->orWhereJsonContains('target_audience', $role);
                });
            } elseif (! $this->permissionResolver->hasGlobalScope($user, 'announcements.view')) {
                $allowedProjectIds = $this->permissionResolver->projectIdsForPermission($user, 'announcements.view');
                $opportunityQuery->where(function ($q) use ($allowedProjectIds, $user) {
                    $q->whereIn('project_id', $allowedProjectIds)
                        ->orWhere('created_by', $user->id);
                });
            }

            $messages = $messages->concat(
                $opportunityQuery->get()->map(function (AlumniOpportunity $item) {
                    return [
                        'type' => 'opportunity',
                        'source_type' => AlumniOpportunity::class,
                        'source_id' => $item->id,
                        'title' => $item->title,
                        'content' => trim((string) ($item->summary ?? '')) !== '' ? $item->summary : $item->body,
                        'category' => $item->kind,
                        'project' => $item->project ? ['id' => $item->project->id, 'name' => $item->project->name] : null,
                        'meta' => [
                            'link_url' => $item->link_url,
                            'starts_at' => optional($item->starts_at)?->toIso8601String(),
                            'ends_at' => optional($item->ends_at)?->toIso8601String(),
                        ],
                        'timestamp' => optional($item->published_at ?? $item->created_at)?->toIso8601String(),
                    ];
                })
            );
        }

        if (($validated['type'] ?? null) === null || $validated['type'] === 'forum_post') {
            $forumQuery = ForumPost::query()
                ->with(['project:id,name', 'author:id,name,surname'])
                ->whereIn('project_id', $projectFilter !== null ? [$projectFilter] : $projectIds)
                ->where('user_id', '!=', $userId);

            if (! empty($validated['from'])) {
                $forumQuery->whereDate('created_at', '>=', $validated['from']);
            }
            if (! empty($validated['to'])) {
                $forumQuery->whereDate('created_at', '<=', $validated['to']);
            }

            $messages = $messages->concat(
                $forumQuery->latest()->limit(200)->get()->map(function (ForumPost $item) {
                    return [
                        'type' => 'forum_post',
                        'source_type' => ForumPost::class,
                        'source_id' => $item->id,
                        'title' => $item->title,
                        'content' => $item->content,
                        'category' => 'forum',
                        'project' => $item->project ? ['id' => $item->project->id, 'name' => $item->project->name] : null,
                        'meta' => [
                            'author' => $item->author ? trim($item->author->name.' '.$item->author->surname) : null,
                        ],
                        'timestamp' => optional($item->created_at)->toIso8601String(),
                    ];
                })
            );
        }

        $stateMap = InboxMessageState::query()
            ->where('user_id', $userId)
            ->where(function ($query) use ($messages) {
                foreach ($messages->groupBy('source_type') as $sourceType => $items) {
                    $ids = $items->pluck('source_id')->unique()->values()->all();
                    $query->orWhere(function ($q) use ($sourceType, $ids) {
                        $q->where('source_type', $sourceType)->whereIn('source_id', $ids);
                    });
                }
            })
            ->get()
            ->keyBy(fn (InboxMessageState $state) => $state->source_type.':'.$state->source_id);

        $withState = $messages->map(function (array $item) use ($stateMap) {
            $key = $item['source_type'].':'.$item['source_id'];
            /** @var InboxMessageState|null $state */
            $state = $stateMap->get($key);
            $item['state'] = [
                'is_read' => $state?->read_at !== null,
                'read_at' => optional($state?->read_at)?->toIso8601String(),
                'is_starred' => (bool) ($state?->is_starred ?? false),
                'is_pinned' => (bool) ($state?->is_pinned ?? false),
            ];

            return $item;
        });

        if (! empty($validated['unread_only'])) {
            $withState = $withState->where('state.is_read', false);
        }
        if (! empty($validated['starred_only'])) {
            $withState = $withState->where('state.is_starred', true);
        }
        if (! empty($validated['pinned_only'])) {
            $withState = $withState->where('state.is_pinned', true);
        }

        $ordered = $withState
            ->sortByDesc(function (array $item) {
                return ($item['state']['is_pinned'] ? '1' : '0').'|'.($item['timestamp'] ?? '');
            })
            ->values();

        return response()->json([
            'messages' => $ordered,
        ]);
    }

    public function upsertState(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_type' => 'required|string|max:128',
            'source_id' => 'required|integer|min:1',
            'is_read' => 'nullable|boolean',
            'is_starred' => 'nullable|boolean',
            'is_pinned' => 'nullable|boolean',
        ]);

        $allowed = [
            Announcement::class,
            AlumniOpportunity::class,
            ForumPost::class,
        ];
        abort_unless(in_array($validated['source_type'], $allowed, true), 422, 'Desteklenmeyen source_type.');
        $user = $request->user();
        $isAllowedSource = match ($validated['source_type']) {
            Announcement::class => ($announcement = Announcement::query()->find((int) $validated['source_id'])) !== null
                && $this->announcementVisibleToUser($user, $announcement),
            AlumniOpportunity::class => ($opportunity = AlumniOpportunity::query()->find((int) $validated['source_id'])) !== null
                && $this->opportunityVisibleToUser($user, $opportunity),
            ForumPost::class => ($post = ForumPost::query()->find((int) $validated['source_id'])) !== null
                && $this->forumPostVisibleToUser($user, $post),
            default => false,
        };
        abort_unless($isAllowedSource, 403, 'Bu mesaj kaydi icin islem yetkiniz bulunmuyor.');

        $state = InboxMessageState::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'source_type' => $validated['source_type'],
                'source_id' => (int) $validated['source_id'],
            ],
            [
                'read_at' => null,
                'is_starred' => false,
                'is_pinned' => false,
            ]
        );

        $updates = [];
        if (array_key_exists('is_read', $validated)) {
            $updates['read_at'] = $validated['is_read'] ? now() : null;
        }
        if (array_key_exists('is_starred', $validated)) {
            $updates['is_starred'] = (bool) $validated['is_starred'];
        }
        if (array_key_exists('is_pinned', $validated)) {
            $updates['is_pinned'] = (bool) $validated['is_pinned'];
        }
        if ($updates !== []) {
            $state->update($updates);
        }

        return response()->json([
            'message' => 'Inbox durumu guncellendi.',
            'state' => [
                'source_type' => $state->source_type,
                'source_id' => $state->source_id,
                'is_read' => $state->read_at !== null,
                'read_at' => optional($state->read_at)->toIso8601String(),
                'is_starred' => (bool) $state->is_starred,
                'is_pinned' => (bool) $state->is_pinned,
            ],
        ]);
    }
}
