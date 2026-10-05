<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DirectMessage;
use App\Models\DirectMessageThread;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Services\PermissionResolver;
use App\Support\ApplicationFileStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DirectMessageController extends Controller
{
    private const ATTACHMENT_DISK = 'application_private';

    public function __construct(private readonly PermissionResolver $permissions) {}

    private function isPanel(Request $request): bool
    {
        return $request->is('api/panel/*', 'api/admin/*');
    }

    /** @return list<int> */
    private function projectIds(Request $request): array
    {
        $user = $request->user();
        if ($this->isPanel($request)) {
            abort_unless($this->permissions->hasPermission($user, 'inbox.view'), 403);

            return array_map('intval', $this->permissions->projectIdsForPermission($user, 'inbox.view'));
        }

        return Participant::query()->where('user_id', $user->id)
            ->pluck('project_id')->unique()->map(fn ($id) => (int) $id)->values()->all();
    }

    private function isValidRecipient(Request $request, User $recipient, int $projectId): bool
    {
        $statusAllowsMessage = $recipient->status === 'active'
            || ($recipient->role === 'alumni' && $recipient->status === 'alumni');
        if ((int) $recipient->id === (int) $request->user()->id
            || ! $statusAllowsMessage || $recipient->trashed()) {
            return false;
        }

        if (in_array($recipient->role, ['coordinator', 'staff', 'super_admin'], true)) {
            return $this->permissions->canAccessProject($recipient, 'inbox.view', $projectId);
        }

        return $this->isPanel($request)
            && in_array($recipient->role, ['student', 'alumni'], true)
            && Participant::query()->where('user_id', $recipient->id)->where('project_id', $projectId)->exists();
    }

    private function accessibleThread(Request $request, int $id): DirectMessageThread
    {
        $thread = DirectMessageThread::query()->findOrFail($id);
        abort_unless(
            in_array((int) $request->user()->id, [(int) $thread->created_by, (int) $thread->recipient_id], true)
                && in_array((int) $thread->project_id, $this->projectIds($request), true),
            403,
            'Bu konusmaya erisim yetkiniz bulunmuyor.'
        );

        return $thread;
    }

    private function threadData(DirectMessageThread $thread, int $userId): array
    {
        $other = (int) $thread->created_by === $userId ? $thread->recipient : $thread->creator;

        return [
            'id' => $thread->id,
            'subject' => $thread->subject,
            'project' => $thread->project ? ['id' => $thread->project->id, 'name' => $thread->project->name] : null,
            'other_user' => $other ? ['id' => $other->id, 'name' => trim($other->name.' '.$other->surname)] : null,
            'last_message_at' => $thread->last_message_at?->toIso8601String(),
            'unread_count' => (int) ($thread->unread_count ?? 0),
        ];
    }

    private function messageData(DirectMessage $message, int $userId): array
    {
        return [
            'id' => $message->id,
            'body' => $message->body,
            'sender' => $message->sender ? [
                'id' => $message->sender->id,
                'name' => trim($message->sender->name.' '.$message->sender->surname),
            ] : null,
            'is_mine' => (int) $message->sender_id === $userId,
            'attachment_name' => $message->attachment_name,
            'read_at' => $message->read_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    public function recipients(Request $request): JsonResponse
    {
        $projectIds = $this->projectIds($request);
        $validated = $request->validate(['project_id' => 'nullable|integer|min:1']);
        $projects = Project::query()->whereIn('id', $projectIds)->orderBy('name')->get(['id', 'name']);
        $projectId = isset($validated['project_id']) ? (int) $validated['project_id'] : null;
        if ($projectId !== null) {
            abort_unless(in_array($projectId, $projectIds, true), 403);
        }

        $recipients = [];
        if ($projectId !== null) {
            $participantIds = $this->isPanel($request)
                ? Participant::query()->where('project_id', $projectId)->pluck('user_id')->all()
                : [];
            $candidates = User::query()->whereIn('status', ['active', 'alumni'])
                ->where(function ($query) use ($participantIds) {
                    $query->whereIn('role', ['coordinator', 'staff', 'super_admin']);
                    if ($participantIds !== []) {
                        $query->orWhereIn('id', $participantIds);
                    }
                })
                ->orderBy('name')->orderBy('surname')->get(['id', 'name', 'surname', 'role', 'status']);
            foreach ($candidates as $candidate) {
                if ($this->isValidRecipient($request, $candidate, $projectId)) {
                    $recipients[] = [
                        'id' => $candidate->id,
                        'name' => trim($candidate->name.' '.$candidate->surname),
                        'role' => $candidate->role,
                    ];
                }
            }
        }

        return response()->json(['projects' => $projects, 'recipients' => $recipients]);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['page' => 'nullable|integer|min:1']);
        $projectIds = $this->projectIds($request);
        $userId = (int) $request->user()->id;
        $threads = DirectMessageThread::query()->whereIn('project_id', $projectIds)
            ->where(fn ($query) => $query->where('created_by', $userId)->orWhere('recipient_id', $userId))
            ->with(['project:id,name', 'creator:id,name,surname', 'recipient:id,name,surname'])
            ->withCount(['messages as unread_count' => fn ($query) => $query
                ->where('sender_id', '!=', $userId)->whereNull('read_at')])
            ->orderByDesc('last_message_at')->simplePaginate(30, ['*'], 'page', (int) ($validated['page'] ?? 1));

        return response()->json([
            'threads' => $threads->getCollection()->map(fn ($thread) => $this->threadData($thread, $userId)),
            'next_page' => $threads->hasMorePages() ? $threads->currentPage() + 1 : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => 'required|integer|exists:projects,id',
            'recipient_id' => 'required|integer|exists:users,id',
            'subject' => 'required|string|max:150',
            'body' => 'required|string|max:10000',
            'attachment' => 'nullable|file|max:5120|mimes:pdf,doc,docx,png,jpg,jpeg',
        ]);
        $this->requireText($validated, ['subject', 'body']);
        $projectId = (int) $validated['project_id'];
        abort_unless(in_array($projectId, $this->projectIds($request), true), 403);
        $recipient = User::query()->findOrFail((int) $validated['recipient_id']);
        abort_unless($this->isValidRecipient($request, $recipient, $projectId), 403, 'Bu aliciya mesaj gonderemezsiniz.');

        $attachment = $this->storeAttachment($request);
        try {
            $thread = DB::transaction(function () use ($request, $validated, $projectId, $recipient, $attachment) {
                $thread = DirectMessageThread::query()->create([
                    'project_id' => $projectId,
                    'created_by' => $request->user()->id,
                    'recipient_id' => $recipient->id,
                    'subject' => trim($validated['subject']),
                    'last_message_at' => now(),
                ]);
                $thread->messages()->create([
                    'sender_id' => $request->user()->id,
                    'body' => trim($validated['body']),
                    ...$attachment,
                ]);

                return $thread;
            });
        } catch (\Throwable $exception) {
            $this->deleteAttachment($attachment);
            throw $exception;
        }

        return response()->json(['message' => 'Mesaj gonderildi.', 'thread_id' => $thread->id], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $thread = $this->accessibleThread($request, $id);
        $validated = $request->validate(['before_id' => 'nullable|integer|min:1']);
        $userId = (int) $request->user()->id;
        $thread->load(['project:id,name', 'creator:id,name,surname', 'recipient:id,name,surname']);
        $query = $thread->messages()->with('sender:id,name,surname');
        if (isset($validated['before_id'])) {
            $query->where('id', '<', (int) $validated['before_id']);
        }
        $page = $query->orderByDesc('id')->limit(51)->get();
        $hasMore = $page->count() > 50;
        $messages = $page->take(50)->reverse()->values();
        $thread->messages()->whereIn('id', $messages->pluck('id'))
            ->where('sender_id', '!=', $userId)->whereNull('read_at')->update(['read_at' => now()]);
        $messages->each(function (DirectMessage $message) use ($userId) {
            if ((int) $message->sender_id !== $userId && $message->read_at === null) {
                $message->read_at = now();
            }
        });

        return response()->json([
            'thread' => $this->threadData($thread, $userId),
            'messages' => $messages->map(fn ($message) => $this->messageData($message, $userId)),
            'has_more' => $hasMore,
        ]);
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $thread = $this->accessibleThread($request, $id);
        $otherId = (int) $thread->created_by === (int) $request->user()->id
            ? (int) $thread->recipient_id : (int) $thread->created_by;
        $other = User::query()->find($otherId);
        abort_unless($other && $this->isValidRecipient($request, $other, (int) $thread->project_id), 422,
            'Diger katilimci artik bu projede mesaj alamiyor.');
        $validated = $request->validate([
            'body' => 'required|string|max:10000',
            'attachment' => 'nullable|file|max:5120|mimes:pdf,doc,docx,png,jpg,jpeg',
        ]);
        $this->requireText($validated, ['body']);
        $attachment = $this->storeAttachment($request);
        try {
            DB::transaction(function () use ($thread, $request, $validated, $attachment) {
                $locked = DirectMessageThread::query()->lockForUpdate()->findOrFail($thread->id);
                $locked->messages()->create([
                    'sender_id' => $request->user()->id,
                    'body' => trim($validated['body']),
                    ...$attachment,
                ]);
                $locked->update(['last_message_at' => now()]);
            });
        } catch (\Throwable $exception) {
            $this->deleteAttachment($attachment);
            throw $exception;
        }

        return response()->json(['message' => 'Yanit gonderildi.'], 201);
    }

    public function attachment(Request $request, int $id, int $messageId)
    {
        $thread = $this->accessibleThread($request, $id);
        $message = $thread->messages()->findOrFail($messageId);
        abort_unless($message->attachment_path !== null
            && str_starts_with($message->attachment_path, 'application-files/')
            && ! str_contains($message->attachment_path, '..')
            && ! str_contains($message->attachment_path, '\\'), 404);
        abort_unless(Storage::disk(self::ATTACHMENT_DISK)->exists($message->attachment_path), 404);

        return Storage::disk(self::ATTACHMENT_DISK)->download($message->attachment_path, basename((string) ($message->attachment_name ?: 'ek')));
    }

    private function storeAttachment(Request $request): array
    {
        if (! $request->hasFile('attachment')) {
            return [];
        }
        $file = $request->file('attachment');
        $path = ApplicationFileStorage::putFile($file);

        return ['attachment_path' => $path, 'attachment_name' => $file->getClientOriginalName()];
    }

    private function deleteAttachment(array $attachment): void
    {
        if (isset($attachment['attachment_path'])) {
            ApplicationFileStorage::delete($attachment['attachment_path']);
        }
    }

    private function requireText(array $validated, array $fields): void
    {
        foreach ($fields as $field) {
            if (trim((string) $validated[$field]) === '') {
                throw ValidationException::withMessages([$field => 'Bu alan bos birakilamaz.']);
            }
        }
    }
}
