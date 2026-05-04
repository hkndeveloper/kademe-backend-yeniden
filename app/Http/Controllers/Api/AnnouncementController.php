<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\CommunicationLog;
use App\Models\Participant;
use App\Models\User;
use App\Support\AdminExportResponder;
use App\Support\MediaStorage;
use App\Services\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnnouncementController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {
    }

    private function abortUnlessAnnouncementAccessible(Request $request, Announcement $announcement, string $permission): void
    {
        $this->abortUnlessAllowed($request, $permission);
        $user = $request->user();
        if ($this->permissionResolver->hasGlobalScope($user, $permission)) {
            return;
        }
        if ($announcement->project_id !== null) {
            abort_unless(
                $this->permissionResolver->canAccessProject($user, $permission, (int) $announcement->project_id),
                403,
                'Bu duyuru icin yetkiniz bulunmuyor.'
            );

            return;
        }

        abort_unless(
            (int) $announcement->created_by === (int) $user->id,
            403,
            'Bu duyuru icin yetkiniz bulunmuyor.'
        );
    }

    private function assertProjectAnnouncementScope(Request $request, ?int $projectId, string $permission): void
    {
        if ($projectId === null) {
            return;
        }

        abort_unless(
            $this->permissionResolver->canAccessProject($request->user(), $permission, $projectId),
            403,
            'Bu proje kapsaminda islem yapamazsiniz.'
        );
    }

    private function participantUserIdsInManageableProjects(User $sender, string $permission): array
    {
        $projectIds = $this->permissionResolver->projectIdsForPermission($sender, $permission);
        if ($projectIds === []) {
            return [];
        }

        return Participant::query()
            ->whereIn('project_id', $projectIds)
            ->where('status', 'active')
            ->pluck('user_id')
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function scopeManageableAnnouncements(Request $request, $query, string $permission)
    {
        $user = $request->user();

        if ($this->permissionResolver->hasGlobalScope($user, $permission)) {
            return $query;
        }

        $manageableProjectIds = $this->permissionResolver->projectIdsForPermission($user, $permission);

        if ($manageableProjectIds === []) {
            return $query->where('created_by', $user->id);
        }

        return $query->where(function ($builder) use ($user, $manageableProjectIds) {
            $builder
                ->whereIn('project_id', $manageableProjectIds)
                ->orWhere('created_by', $user->id);
        });
    }

    private function communicationLogPayload(CommunicationLog $log): array
    {
        return [
            'id' => $log->id,
            'type' => $log->type,
            'sender_id' => $log->sender_id,
            'recipients_count' => $log->recipients_count,
            'subject' => $log->subject,
            'content' => $log->content,
            'attachment_path' => $log->attachment_path,
            'attachment_download_url' => $log->attachment_path ? "/announcements/communication-logs/{$log->id}/attachment" : null,
            'status' => $log->status,
            'project_id' => $log->project_id,
            'created_at' => optional($log->created_at)?->toIso8601String(),
            'sender' => $log->relationLoaded('sender') ? $log->sender : null,
            'project' => $log->relationLoaded('project') ? $log->project : null,
        ];
    }

    private function scopeCommunicationLogs(Request $request, $query, string $permission)
    {
        $user = $request->user();

        if ($this->permissionResolver->hasGlobalScope($user, $permission)) {
            return $query;
        }

        $projectIds = $this->permissionResolver->projectIdsForPermission($user, $permission);

        return $query->where(function ($builder) use ($user, $projectIds) {
            $builder->where('sender_id', $user->id);

            if ($projectIds !== []) {
                $builder->orWhereIn('project_id', $projectIds);
            }
        });
    }

    private function streamCommunicationAttachment(CommunicationLog $log): JsonResponse|StreamedResponse
    {
        if (! $log->attachment_path) {
            return response()->json(['message' => 'Ek dosya bulunamadi.'], 404);
        }

        if ($this->isUrl($log->attachment_path) || (MediaStorage::directDownloadsEnabled() && MediaStorage::publicUrlConfigured())) {
            return response()->json(['download_url' => MediaStorage::url($log->attachment_path)]);
        }

        if (! MediaStorage::exists($log->attachment_path)) {
            return response()->json(['message' => 'Ek dosya storage uzerinde bulunamadi.'], 404);
        }

        $extension = pathinfo($log->attachment_path, PATHINFO_EXTENSION);
        $filename = 'duyuru_eki_' . $log->id;

        return MediaStorage::disk()->download(
            $log->attachment_path,
            $filename . ($extension ? ".{$extension}" : '')
        );
    }

    private function isUrl(string $path): bool
    {
        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://');
    }
    /**
     * GET /staff/announcements
     * Staff kullanicisinin gorebilecegi aktif duyurulari listele.
     */
    public function myAnnouncements(Request $request)
    {
        $this->abortUnlessAllowed($request, 'announcements.view');
        $user = Auth::user();

        $query = Announcement::with(['project:id,name', 'creator:id,name,surname'])
            ->where(function ($q) use ($user) {
                $q->whereNull('target_roles')
                    ->orWhereJsonLength('target_roles', 0)
                    ->orWhereJsonContains('target_roles', $user->role);
            })
            ->where(function ($q) {
                $q->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', now());
            })
            ->latest();

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        return response()->json([
            'announcements' => $query->paginate(20),
        ]);
    }

    public function recipientAnnouncements(Request $request)
    {
        $user = $request->user();

        $projectIds = Participant::query()
            ->where('user_id', $user->id)
            ->where(function ($query) use ($user) {
                $query->where('status', 'active');

                if ($user->role === 'alumni') {
                    $query->orWhere('graduation_status', 'graduated')
                        ->orWhereNotNull('graduated_at');
                }
            })
            ->pluck('project_id')
            ->filter()
            ->values();

        $query = Announcement::with(['project:id,name', 'creator:id,name,surname'])
            ->where(function ($q) use ($user) {
                $q->whereNull('target_roles')
                    ->orWhereJsonLength('target_roles', 0)
                    ->orWhereJsonContains('target_roles', $user->role);
            })
            ->where(function ($q) use ($projectIds) {
                $q->whereNull('project_id')
                    ->orWhereIn('project_id', $projectIds);
            })
            ->where(function ($q) {
                $q->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', now());
            })
            ->latest();

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        return response()->json([
            'announcements' => $query->limit(50)->get(),
        ]);
    }

    public function exportMyAnnouncements(Request $request)
    {
        $this->abortUnlessAllowed($request, 'announcements.export');
        $user = Auth::user();

        $query = Announcement::with(['project:id,name', 'creator:id,name,surname'])
            ->where(function ($q) use ($user) {
                $q->whereNull('target_roles')
                    ->orWhereJsonLength('target_roles', 0)
                    ->orWhereJsonContains('target_roles', $user->role);
            })
            ->where(function ($q) {
                $q->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', now());
            })
            ->latest();

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        $announcements = $query->get();

        $headings = ['ID', 'Baslik', 'Kategori', 'Proje', 'Olusturan', 'Yayin Tarihi', 'Bitis Tarihi'];
        $rows = $announcements->map(fn (Announcement $announcement) => [
            $announcement->id,
            $announcement->title,
            $announcement->category ?? '-',
            $announcement->project?->name ?? '-',
            $announcement->creator ? trim($announcement->creator->name . ' ' . $announcement->creator->surname) : '-',
            $announcement->published_at?->format('d.m.Y H:i') ?? '-',
            $announcement->expires_at?->format('d.m.Y H:i') ?? '-',
        ])->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'personel_duyurulari_' . now()->format('Ymd_His'),
            'Personel Duyurulari',
            $headings,
            $rows,
        );
    }

    /**
     * GET /admin/announcements
     * Tüm duyuruları listele.
     */
    public function index(Request $request)
    {
        $this->abortUnlessAllowed($request, 'announcements.view');
        $query = Announcement::with(['project:id,name', 'creator:id,name,surname'])->latest();
        $query = $this->scopeManageableAnnouncements($request, $query, 'announcements.view');

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        if ($request->filled('project_id')) {
            $this->assertProjectAnnouncementScope($request, (int) $request->project_id, 'announcements.view');
            $query->where('project_id', (int) $request->project_id);
        }

        return response()->json(['announcements' => $query->paginate(20)]);
    }

    public function export(Request $request)
    {
        $this->abortUnlessAllowed($request, 'announcements.export');
        $query = Announcement::with(['project:id,name', 'creator:id,name,surname'])->latest();
        $query = $this->scopeManageableAnnouncements($request, $query, 'announcements.export');

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        if ($request->filled('project_id')) {
            $this->assertProjectAnnouncementScope($request, (int) $request->project_id, 'announcements.export');
            $query->where('project_id', (int) $request->project_id);
        }

        $announcements = $query->get();

        $headings = ['ID', 'Baslik', 'Kategori', 'Proje', 'Hedef Roller', 'Olusturan', 'Yayin Tarihi', 'Bitis Tarihi'];
        $rows = $announcements->map(fn (Announcement $announcement) => [
            $announcement->id,
            $announcement->title,
            $announcement->category ?? '-',
            $announcement->project?->name ?? '-',
            !empty($announcement->target_roles) ? implode(', ', $announcement->target_roles) : 'tum kullanicilar',
            $announcement->creator ? trim($announcement->creator->name . ' ' . $announcement->creator->surname) : '-',
            $announcement->published_at?->format('d.m.Y H:i') ?? '-',
            $announcement->expires_at?->format('d.m.Y H:i') ?? '-',
        ])->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'duyurular_' . now()->format('Ymd_His'),
            'Duyurular',
            $headings,
            $rows,
        );
    }

    /**
     * POST /admin/announcements
     * Yeni duyuru oluştur.
     */
    public function store(Request $request)
    {
        $this->abortUnlessAllowed($request, 'announcements.create');
        $validated = $request->validate([
            'title'        => 'required|string|max:255',
            'content'      => 'required|string',
            'category'     => 'nullable|string|max:100',
            'target_roles' => 'nullable|array',
            'target_roles.*' => 'in:super_admin,coordinator,staff,student,alumni',
            'project_id'   => 'nullable|exists:projects,id',
            'published_at' => 'nullable|date',
            'expires_at'   => 'nullable|date',
            'send_sms'     => 'boolean',
            'send_email'   => 'boolean',
            'email_attachment' => 'nullable|file|mimes:pdf,jpg,png,docx|max:10240',
        ]);

        if (! empty($validated['project_id'])) {
            $this->assertProjectAnnouncementScope($request, (int) $validated['project_id'], 'announcements.create');
        }

        $targetUsers = $this->resolveTargetUsers($request->user(), $validated, 'announcements.create');

        $announcement = Announcement::create([
            'title'        => $validated['title'],
            'content'      => $validated['content'],
            'category'     => $validated['category'] ?? null,
            'target_roles' => $validated['target_roles'] ?? [],
            'project_id'   => $validated['project_id'] ?? null,
            'created_by'   => Auth::id(),
            'published_at' => $validated['published_at'] ?? now(),
            'expires_at'   => $validated['expires_at'] ?? null,
        ]);

        // SMS gönder
        if (!empty($validated['send_sms']) && $validated['send_sms']) {
            $this->dispatchSms($targetUsers, $validated['title'] . ': ' . substr($validated['content'], 0, 140));
        }

        // E-posta gönder
        if (!empty($validated['send_email']) && $validated['send_email']) {
            $attachmentPath = null;
            if ($request->hasFile('email_attachment')) {
                $attachmentPath = MediaStorage::putFile('announcement_attachments', $request->file('email_attachment'));
            }
            $this->dispatchEmail($targetUsers, $announcement, $attachmentPath);
        }

        return response()->json([
            'message'      => 'Duyuru oluşturuldu.',
            'announcement' => $announcement->load(['project:id,name', 'creator:id,name,surname']),
            'target_count' => $targetUsers->count(),
        ], 201);
    }

    /**
     * GET /admin/announcements/{id}
     */
    public function show(int $id)
    {
        $announcement = Announcement::with(['project:id,name', 'creator:id,name,surname'])->findOrFail($id);
        $this->abortUnlessAnnouncementAccessible(request(), $announcement, 'announcements.view');

        return response()->json(['announcement' => $announcement]);
    }

    /**
     * PUT /admin/announcements/{id}
     */
    public function update(Request $request, int $id)
    {
        $announcement = Announcement::findOrFail($id);
        $this->abortUnlessAnnouncementAccessible($request, $announcement, 'announcements.update');

        $validated = $request->validate([
            'title'        => 'sometimes|string|max:255',
            'content'      => 'sometimes|string',
            'category'     => 'nullable|string|max:100',
            'target_roles' => 'nullable|array',
            'project_id'   => 'nullable|exists:projects,id',
            'published_at' => 'nullable|date',
            'expires_at'   => 'nullable|date',
        ]);

        if (array_key_exists('project_id', $validated)) {
            $newProjectId = $validated['project_id'];
            if ($newProjectId !== null) {
                $this->assertProjectAnnouncementScope($request, (int) $newProjectId, 'announcements.update');
            } elseif (! $this->permissionResolver->hasGlobalScope($request->user(), 'announcements.update')) {
                abort(403, 'Proje baglantisi kaldirma yalnizca ust admin icin yapilabilir.');
            }
        }

        $announcement->update($validated);

        return response()->json([
            'message'      => 'Duyuru güncellendi.',
            'announcement' => $announcement->fresh(['project:id,name', 'creator:id,name,surname']),
        ]);
    }

    /**
     * DELETE /admin/announcements/{id}
     */
    public function destroy(int $id)
    {
        $announcement = Announcement::findOrFail($id);
        $this->abortUnlessAnnouncementAccessible(request(), $announcement, 'announcements.delete');
        $announcement->delete();

        return response()->json(['message' => 'Duyuru silindi.']);
    }

    /**
     * POST /admin/announcements/send-sms
     * Bağımsız SMS gönderim endpoint'i.
     */
    public function sendSms(Request $request)
    {
        $this->abortUnlessAllowed($request, 'announcements.send_sms');
        $validated = $request->validate([
            'message'      => 'required|string|max:160',
            'target_roles' => 'nullable|array',
            'project_id'   => 'nullable|exists:projects,id',
            'user_ids'     => 'nullable|array',
            'user_ids.*'   => 'exists:users,id',
        ]);

        if (! empty($validated['project_id'])) {
            $this->assertProjectAnnouncementScope($request, (int) $validated['project_id'], 'announcements.send_sms');
        }

        $targetUsers = $this->resolveTargetUsers($request->user(), $validated, 'announcements.send_sms');

        $sent = $this->dispatchSms($targetUsers, $validated['message']);

        return response()->json([
            'message' => 'SMS gönderimi tamamlandı.',
            'sent_to' => $sent,
        ]);
    }

    /**
     * POST /admin/announcements/send-email
     * Bağımsız e-posta gönderim endpoint'i.
     */
    public function sendEmail(Request $request)
    {
        $this->abortUnlessAllowed($request, 'announcements.send_email');
        $validated = $request->validate([
            'subject'      => 'required|string|max:255',
            'body'         => 'required|string',
            'target_roles' => 'nullable|array',
            'project_id'   => 'nullable|exists:projects,id',
            'user_ids'     => 'nullable|array',
            'user_ids.*'   => 'exists:users,id',
            'attachment'   => 'nullable|file|mimes:pdf,jpg,png,docx|max:10240',
        ]);

        if (! empty($validated['project_id'])) {
            $this->assertProjectAnnouncementScope($request, (int) $validated['project_id'], 'announcements.send_email');
        }

        $targetUsers = $this->resolveTargetUsers($request->user(), $validated, 'announcements.send_email');
        $attachmentPath = null;

        if ($request->hasFile('attachment')) {
            $attachmentPath = MediaStorage::putFile('email_attachments', $request->file('attachment'));
        }

        $sent = $this->dispatchEmail($targetUsers, (object) [
            'title'   => $validated['subject'],
            'content' => $validated['body'],
            'project_id' => $validated['project_id'] ?? null,
        ], $attachmentPath);

        return response()->json([
            'message' => 'E-posta gönderimi tamamlandı.',
            'sent_to' => $sent,
        ]);
    }

    // ─── YARDIMCI METODLAR ────────────────────────────────────────────────────

    public function communicationLogs(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'announcements.view');

        $query = CommunicationLog::query()
            ->with(['sender:id,name,surname,role', 'project:id,name'])
            ->whereIn('type', ['email', 'sms'])
            ->latest();

        $query = $this->scopeCommunicationLogs($request, $query, 'announcements.view');

        if ($request->filled('type')) {
            $query->where('type', $request->string('type')->toString());
        }

        if ($request->filled('project_id')) {
            $projectId = (int) $request->project_id;
            $this->assertProjectAnnouncementScope($request, $projectId, 'announcements.view');
            $query->where('project_id', $projectId);
        }

        return response()->json([
            'logs' => $query->limit(30)->get()->map(fn (CommunicationLog $log) => $this->communicationLogPayload($log))->values(),
        ]);
    }

    public function downloadCommunicationAttachment(Request $request, int $id): JsonResponse|StreamedResponse
    {
        $this->abortUnlessAllowed($request, 'announcements.view');

        $log = CommunicationLog::query()->findOrFail($id);
        $user = $request->user();

        $canAccess = (int) $log->sender_id === (int) $user->id
            || $this->permissionResolver->hasGlobalScope($user, 'announcements.view')
            || ($log->project_id !== null && $this->permissionResolver->canAccessProject($user, 'announcements.view', (int) $log->project_id));

        abort_unless($canAccess, 403, 'Bu ek dosyayi indirme yetkiniz yok.');

        return $this->streamCommunicationAttachment($log);
    }

    private function resolveTargetUsers(User $sender, array $validated, string $permission): \Illuminate\Database\Eloquent\Collection
    {
        $columns = ['id', 'name', 'surname', 'email', 'phone'];

        if ($this->permissionResolver->hasGlobalScope($sender, $permission)) {
            return $this->resolveTargetUsersAsSuperAdmin($validated, $columns);
        }

        $participantIds = $this->participantUserIdsInManageableProjects($sender, $permission);

        if (! empty($validated['user_ids'])) {
            foreach ($validated['user_ids'] as $uid) {
                $uid = (int) $uid;
                abort_unless(
                    in_array($uid, $participantIds, true) || $uid === (int) $sender->id,
                    403,
                    'Secilen kullanicilarin bir kismi erisim kapsaminiz disinda.'
                );
            }

            return User::query()
                ->where('status', 'active')
                ->whereIn('id', $validated['user_ids'])
                ->get($columns);
        }

        $privileged = array_intersect($validated['target_roles'] ?? [], ['super_admin', 'coordinator', 'staff']);
        if ($privileged !== [] && empty($validated['project_id'])) {
            abort(403, 'Bu rollere toplu mesaj icin proje secilmelidir.');
        }

        $query = User::query()->where('status', 'active');

        if (! empty($validated['target_roles'])) {
            $query->whereIn('role', $validated['target_roles']);
        }

        if (! empty($validated['project_id'])) {
            $query->whereHas('participations', fn ($q) =>
                $q->where('project_id', $validated['project_id'])->where('status', 'active'));

            return $query->get($columns);
        }

        if ($participantIds === []) {
            return User::query()->whereRaw('0 = 1')->get($columns);
        }

        $query->whereIn('id', $participantIds);

        return $query->get($columns);
    }

    private function resolveTargetUsersAsSuperAdmin(array $validated, array $columns): \Illuminate\Database\Eloquent\Collection
    {
        $query = User::query()->where('status', 'active');

        if (! empty($validated['user_ids'])) {
            return $query->whereIn('id', $validated['user_ids'])->get($columns);
        }

        if (! empty($validated['target_roles'])) {
            $query->whereIn('role', $validated['target_roles']);
        }

        if (! empty($validated['project_id'])) {
            $query->whereHas('participations', fn ($q) =>
                $q->where('project_id', $validated['project_id'])->where('status', 'active'));
        }

        return $query->get($columns);
    }

    private function dispatchSms(\Illuminate\Database\Eloquent\Collection $users, string $message): int
    {
        // SMS entegrasyonu (Faz 5'te Netgsm API'si eklenecek)
        // Şimdilik CommunicationLog'a kaydediyoruz
        $sent = 0;
        foreach ($users as $user) {
            if ($user->phone) {
                $sent++;
            }
        }
        // Toplu log kaydı
        if ($sent > 0) {
            \App\Models\CommunicationLog::create([
                'type'             => 'sms',
                'sender_id'        => Auth::id(),
                'recipients_count' => $sent,
                'subject'          => 'SMS',
                'content'          => $message,
                'status'           => 'queued',
                'project_id'       => null,
            ]);
        }
        return $sent;
    }

    private function dispatchEmail(\Illuminate\Database\Eloquent\Collection $users, object $announcement, ?string $attachmentPath): int
    {
        // E-posta entegrasyonu (Faz 5'te Laravel Mail + queue eklenecek)
        $sent = $users->count();
        if ($sent > 0) {
            \App\Models\CommunicationLog::create([
                'type'             => 'email',
                'sender_id'        => Auth::id(),
                'recipients_count' => $sent,
                'subject'          => $announcement->title,
                'content'          => substr($announcement->content, 0, 500),
                'attachment_path'  => $attachmentPath,
                'status'           => 'queued',
                'project_id'       => $announcement->project_id ?? null,
            ]);
        }
        return $sent;
    }
}
