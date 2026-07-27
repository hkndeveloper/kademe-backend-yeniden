<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Concerns\ResolvesProjectPeriodContext;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\CommunicationLog;
use App\Models\Participant;
use App\Models\Period;
use App\Models\User;
use App\Support\AdminExportResponder;
use App\Support\MediaStorage;
use App\Support\IstanbulDateTime;
use App\Services\NotificationService;
use App\Services\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @group Announcements & Inbox
 */
class AnnouncementController extends Controller
{
    use AuthorizesGranularPermissions;
    use ResolvesProjectPeriodContext;

    private const TARGET_UNITS = [
        'media',
        'operations',
        'program',
        'finance',
        'official_affairs',
    ];

    public function __construct(
        private readonly PermissionResolver $permissionResolver,
        private readonly NotificationService $notificationService
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

    private function resolveAnnouncementPeriod(Request $request, array &$validated, string $permission, bool $guardArchiveWrite = false): ?int
    {
        if (empty($validated['period_id'])) {
            return null;
        }

        $period = Period::query()
            ->select(['id', 'project_id', 'status'])
            ->findOrFail((int) $validated['period_id']);

        if (! empty($validated['project_id']) && (int) $validated['project_id'] !== (int) $period->project_id) {
            throw ValidationException::withMessages([
                'period_id' => ['Secilen donem bu projeye ait degil.'],
            ]);
        }

        $validated['project_id'] = (int) $period->project_id;
        $this->assertProjectAnnouncementScope($request, (int) $period->project_id, $permission);

        if ($guardArchiveWrite) {
            $this->assertPeriodWritable($request, (int) $period->id);
        }

        return (int) $period->id;
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
            'attachment_download_url' => $log->attachment_path ? "/panel/announcements/communication-logs/{$log->id}/attachment" : null,
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

    private function targetUnitsForUser(User $user, string $permission): array
    {
        if ($this->permissionResolver->hasGlobalScope($user, $permission)) {
            return self::TARGET_UNITS;
        }

        return $this->permissionResolver->targetUnitsForUser($user, self::TARGET_UNITS);
    }

    private function assertTargetUnitsAllowed(User $user, string $permission, array $targetUnits): void
    {
        if ($targetUnits === []) {
            return;
        }

        $allowedUnits = $this->targetUnitsForUser($user, $permission);

        foreach ($targetUnits as $targetUnit) {
            abort_unless(
                in_array($targetUnit, $allowedUnits, true),
                403,
                'Bu birim hedefi icin yetkiniz bulunmuyor.'
            );
        }
    }

    private function staffUserIdsInTargetUnits(array $targetUnits): array
    {
        if ($targetUnits === []) {
            return [];
        }

        return User::query()
            ->with('staffProfile:id,user_id,unit')
            ->where('status', 'active')
            ->whereIn('role', ['super_admin', 'coordinator', 'staff'])
            ->get(['id', 'role'])
            ->filter(fn (User $user) => collect($targetUnits)->contains(
                fn (string $targetUnit) => $this->permissionResolver->matchesTargetUnit($user->staffProfile?->unit, $targetUnit)
            ))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function applyAnnouncementRecipientScope($query, User $user)
    {
        return $query
            ->where(function ($q) use ($user) {
                $q->whereNull('target_roles')
                    ->orWhereJsonLength('target_roles', 0)
                    ->orWhereJsonContains('target_roles', $user->role);
            })
            ->where(function ($q) use ($user) {
                $targetUnits = $this->targetUnitsForUser($user, 'announcements.view');

                $q->whereNull('target_units')
                    ->orWhereJsonLength('target_units', 0);

                foreach ($targetUnits as $targetUnit) {
                    $q->orWhereJsonContains('target_units', $targetUnit);
                }
            });
    }
    /**
     * List staff-visible announcements.
     *
     * Requires permission: `announcements.view`. Returns active announcements visible to the current staff user by role and target unit, with optional category filtering. Expired and future announcements are hidden.
     *
     * @group Announcements & Inbox
     * @queryParam category string Optional category filter. Example: general
     * @response 200 {"announcements":{"data":[{"id":1,"title":"Toplanti","category":"general","project":{"id":1,"name":"KADEME"}}],"current_page":1}}
     */

    public function myAnnouncements(Request $request)
    {
        $this->abortUnlessAllowed($request, 'announcements.view');
        $user = Auth::user();

        $query = $this->applyAnnouncementRecipientScope(
            Announcement::with(['project:id,name', 'period:id,name,status', 'creator:id,name,surname']),
            $user
        )
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

    /**
     * List announcements visible to the current recipient.
     *
     * Requires permission: `participant.inbox.view`. Filters announcements by the current user role, participant projects/periods, publish window, expiry window, target units and optional category.
     *
     * @group Announcements & Inbox
     * @authenticated
     * @queryParam category string Optional category filter. Example: general
     * @response 200 {"announcements":[{"id":1,"title":"Program duyurusu","content":"Duyuru metni","category":"general","project":{"id":1,"name":"KADEME"}}]}
     * @response 401 {"message":"Unauthenticated."}
     * @response 403 {"message":"This action is unauthorized."}
     */

    public function recipientAnnouncements(Request $request)
    {
        $user = $request->user();

        $participations = Participant::query()
            ->where('user_id', $user->id)
            ->where(function ($query) use ($user) {
                $query->where('status', 'active');

                if ($user->role === 'alumni') {
                    $query->orWhere('graduation_status', 'graduated')
                        ->orWhereNotNull('graduated_at');
                }
            })
            ->get(['project_id', 'period_id']);

        $projectIds = $participations
            ->pluck('project_id')
            ->filter()
            ->values();
        $periodIds = $participations
            ->pluck('period_id')
            ->filter()
            ->unique()
            ->values();

        $query = $this->applyAnnouncementRecipientScope(
            Announcement::with(['project:id,name', 'period:id,name,status', 'creator:id,name,surname']),
            $user
        )
            ->where(function ($q) use ($projectIds) {
                $q->whereNull('project_id')
                    ->orWhereIn('project_id', $projectIds);
            })
            ->where(function ($q) use ($periodIds) {
                $q->whereNull('period_id');

                if ($periodIds->isNotEmpty()) {
                    $q->orWhereIn('period_id', $periodIds);
                }
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

    /**
     * Export staff-visible announcements.
     *
     * Requires permission: `announcements.export`. Applies the same staff recipient visibility as `myAnnouncements` and exports through the shared admin export responder.
     *
     * @group Announcements & Inbox
     * @queryParam category string Optional category filter. Example: general
     * @queryParam format string Optional export format. Allowed values: xlsx, excel, pdf, docx, word, csv. Defaults to csv. Example: xlsx
     * @response 200 {"download":"Staff announcements export file stream"}
     */

    public function exportMyAnnouncements(Request $request)
    {
        $this->abortUnlessAllowed($request, 'announcements.export');
        $user = Auth::user();

        $query = $this->applyAnnouncementRecipientScope(
            Announcement::with(['project:id,name', 'period:id,name,status', 'creator:id,name,surname']),
            $user
        )
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

        $headings = ['ID', 'Baslik', 'Kategori', 'Proje', 'Donem', 'Olusturan', 'Yayin Tarihi', 'Bitis Tarihi'];
        $rows = $announcements->map(fn (Announcement $announcement) => [
            $announcement->id,
            $announcement->title,
            $announcement->category ?? '-',
            $announcement->project?->name ?? '-',
            $announcement->period?->name ?? '-',
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
     * List panel announcements.
     *
     * Requires permission: `announcements.view`. Global scope can list all announcements; project-scoped users see announcements in manageable projects plus their own global announcements. `period_id` resolves and validates the owning project.
     *
     * @group Announcements & Inbox
     * @queryParam category string Optional category filter. Example: general
     * @queryParam project_id integer Optional project filter. Requires access to the project for `announcements.view`. Example: 1
     * @queryParam period_id integer Optional period filter. The period project is used as the announcement project. Example: 3
     * @response 200 {"announcements":{"data":[{"id":1,"title":"Toplanti","project":{"id":1,"name":"KADEME"}}],"current_page":1}}
     * @response 403 {"message":"Bu proje kapsaminda islem yapamazsiniz."}
     */

    public function index(Request $request)
    {
        $this->abortUnlessAllowed($request, 'announcements.view');
        $validated = $request->validate([
            'category' => 'nullable|string|max:100',
            'project_id' => 'nullable|integer|exists:projects,id',
            'period_id' => 'nullable|integer|exists:periods,id',
        ]);
        $periodId = $this->resolveAnnouncementPeriod($request, $validated, 'announcements.view');
        $query = Announcement::with(['project:id,name', 'period:id,name,status', 'creator:id,name,surname'])->latest();
        $query = $this->scopeManageableAnnouncements($request, $query, 'announcements.view');

        if (! empty($validated['category'])) {
            $query->where('category', $validated['category']);
        }
        if (! empty($validated['project_id'])) {
            $this->assertProjectAnnouncementScope($request, (int) $validated['project_id'], 'announcements.view');
            $query->where('project_id', (int) $validated['project_id']);
        }
        if ($periodId !== null) {
            $query->where('period_id', $periodId);
        }

        return response()->json(['announcements' => $query->paginate(20)]);
    }

    /**
     * Export panel announcements.
     *
     * Requires permission: `announcements.export`. Applies the same manageable announcement scope and filters as the list endpoint, then exports through the shared admin export responder.
     *
     * @group Announcements & Inbox
     * @queryParam category string Optional category filter. Example: general
     * @queryParam project_id integer Optional project filter. Example: 1
     * @queryParam period_id integer Optional period filter. Example: 3
     * @queryParam format string Optional export format. Allowed values: xlsx, excel, pdf, docx, word, csv. Defaults to csv. Example: csv
     * @response 200 {"download":"Announcements export file stream"}
     */

    public function export(Request $request)
    {
        $this->abortUnlessAllowed($request, 'announcements.export');
        $validated = $request->validate([
            'category' => 'nullable|string|max:100',
            'project_id' => 'nullable|integer|exists:projects,id',
            'period_id' => 'nullable|integer|exists:periods,id',
        ]);
        $periodId = $this->resolveAnnouncementPeriod($request, $validated, 'announcements.export');
        $query = Announcement::with(['project:id,name', 'period:id,name,status', 'creator:id,name,surname'])->latest();
        $query = $this->scopeManageableAnnouncements($request, $query, 'announcements.export');

        if (! empty($validated['category'])) {
            $query->where('category', $validated['category']);
        }
        if (! empty($validated['project_id'])) {
            $this->assertProjectAnnouncementScope($request, (int) $validated['project_id'], 'announcements.export');
            $query->where('project_id', (int) $validated['project_id']);
        }
        if ($periodId !== null) {
            $query->where('period_id', $periodId);
        }

        $announcements = $query->get();

        $headings = ['ID', 'Baslik', 'Kategori', 'Proje', 'Donem', 'Hedef Roller', 'Hedef Birimler', 'Olusturan', 'Yayin Tarihi', 'Bitis Tarihi'];
        $rows = $announcements->map(fn (Announcement $announcement) => [
            $announcement->id,
            $announcement->title,
            $announcement->category ?? '-',
            $announcement->project?->name ?? '-',
            $announcement->period?->name ?? '-',
            !empty($announcement->target_roles) ? implode(', ', $announcement->target_roles) : 'tum kullanicilar',
            !empty($announcement->target_units) ? implode(', ', $announcement->target_units) : 'tum birimler',
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
     * Create a panel announcement.
     *
     * Requires permission: `announcements.create`. Project and period values are checked against the caller project scope; completed periods require archive update permission. Target roles, target units and direct user IDs are resolved through the sender scope. Optional `send_sms` and `send_email` additionally require their own permissions and create communication logs through the notification service.
     *
     * @group Announcements & Inbox
     * @bodyParam title string required Announcement title. Example: Program duyurusu
     * @bodyParam content string required Announcement content. Example: Yarin toplantimiz vardir.
     * @bodyParam category string Optional category. Example: general
     * @bodyParam target_roles array Optional target roles. Allowed values: super_admin, coordinator, staff, student, alumni. Example: ["student"]
     * @bodyParam target_units array Optional target units. Allowed values: media, operations, program, finance, official_affairs. Example: ["program"]
     * @bodyParam project_id integer Optional project ID. Example: 1
     * @bodyParam period_id integer Optional period ID; sets project_id from the period. Example: 3
     * @bodyParam published_at datetime Optional publish time. Example: 2026-07-01 09:00:00
     * @bodyParam expires_at datetime Optional expiry time. Example: 2026-07-31 23:59:00
     * @bodyParam send_sms boolean Optional send SMS immediately. Requires `announcements.send_sms`. Example: false
     * @bodyParam send_email boolean Optional send email immediately. Requires `announcements.send_email`. Example: true
     * @bodyParam email_attachment file Optional email attachment. Allowed: pdf, jpg, png, docx. Max 10 MB.
     * @response 201 {"message":"Duyuru olusturuldu.","announcement":{"id":1,"title":"Program duyurusu"},"target_count":25,"email_sent_to":20,"sms_sent_to":0}
     * @response 403 {"message":"Bu birim hedefi icin yetkiniz bulunmuyor."}
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
            'target_units' => 'nullable|array',
            'target_units.*' => 'in:media,operations,program,finance,official_affairs',
            'project_id'   => 'nullable|exists:projects,id',
            'period_id'    => 'nullable|exists:periods,id',
            'published_at' => 'nullable|date',
            'expires_at'   => 'nullable|date',
            'send_sms'     => 'boolean',
            'send_email'   => 'boolean',
            'email_attachment' => 'nullable|file|mimes:pdf,jpg,png,docx|max:10240',
        ]);
        $validated = IstanbulDateTime::normalizeFields($validated, ['published_at', 'expires_at']);

        if (! empty($validated['project_id'])) {
            $this->assertProjectAnnouncementScope($request, (int) $validated['project_id'], 'announcements.create');
        }
        if (! empty($validated['send_sms'])) {
            $this->abortUnlessAllowed($request, 'announcements.send_sms');
        }
        if (! empty($validated['send_email'])) {
            $this->abortUnlessAllowed($request, 'announcements.send_email');
        }

        $periodId = $this->resolveAnnouncementPeriod($request, $validated, 'announcements.create', true);
        $this->assertTargetUnitsAllowed($request->user(), 'announcements.create', $validated['target_units'] ?? []);

        $targetUsers = $this->resolveTargetUsers($request->user(), $validated, 'announcements.create');

        $announcement = Announcement::create([
            'title'        => $validated['title'],
            'content'      => $validated['content'],
            'category'     => $validated['category'] ?? null,
            'target_roles' => $validated['target_roles'] ?? [],
            'target_units' => $validated['target_units'] ?? [],
            'project_id'   => $validated['project_id'] ?? null,
            'period_id'    => $periodId,
            'created_by'   => Auth::id(),
            'published_at' => $validated['published_at'] ?? now(),
            'expires_at'   => $validated['expires_at'] ?? null,
        ]);

        $smsSent = 0;
        $emailSent = 0;

        // SMS gönder
        if (!empty($validated['send_sms']) && $validated['send_sms']) {
            $smsSent = $this->dispatchSms(
                $targetUsers,
                $validated['title'] . ': ' . substr($validated['content'], 0, 140),
                $announcement->project_id
            );
        }

        // E-posta gönder
        if (!empty($validated['send_email']) && $validated['send_email']) {
            $attachmentPath = null;
            if ($request->hasFile('email_attachment')) {
                $attachmentPath = MediaStorage::putFile('announcement_attachments', $request->file('email_attachment'));
            }
            $emailSent = $this->dispatchEmail($targetUsers, $announcement, $attachmentPath);
        }

        return response()->json([
            'message'      => (!empty($validated['send_email']) && $validated['send_email'] && $emailSent === 0)
                ? 'Duyuru olusturuldu ancak e-posta alicisi bulunamadi veya gonderim basarisiz oldu.'
                : 'Duyuru oluşturuldu.',
            'announcement' => $announcement->load(['project:id,name', 'period:id,name,status', 'creator:id,name,surname']),
            'target_count' => $targetUsers->count(),
            'email_sent_to' => $emailSent,
            'sms_sent_to' => $smsSent,
        ], 201);
    }

    /**
     * Get panel announcement details.
     *
     * Requires permission: `announcements.view` and access to the announcement. Global users can view all; project-scoped users can view manageable project announcements or their own global announcements.
     *
     * @group Announcements & Inbox
     * @urlParam id integer required Announcement ID. Example: 1
     * @response 200 {"announcement":{"id":1,"title":"Program duyurusu","project":{"id":1,"name":"KADEME"}}}
     * @response 403 {"message":"Bu duyuru icin yetkiniz bulunmuyor."}
     */

    public function show(int $id)
    {
        $announcement = Announcement::with(['project:id,name', 'period:id,name,status', 'creator:id,name,surname'])->findOrFail($id);
        $this->abortUnlessAnnouncementAccessible(request(), $announcement, 'announcements.view');

        return response()->json(['announcement' => $announcement]);
    }

    /**
     * Update a panel announcement.
     *
     * Requires permission: `announcements.update` and access to the announcement. Completed periods require archive update permission. Project changes are checked against the caller project scope; removing the project link is only allowed with global scope. Target units are validated against the caller unit targets.
     *
     * @group Announcements & Inbox
     * @urlParam id integer required Announcement ID. Example: 1
     * @bodyParam title string Optional title. Example: Guncel duyuru
     * @bodyParam content string Optional content. Example: Guncellenen duyuru metni.
     * @bodyParam category string Optional category. Example: general
     * @bodyParam target_roles array Optional target roles. Example: ["student","alumni"]
     * @bodyParam target_units array Optional target units. Example: ["program"]
     * @bodyParam project_id integer Optional project ID or null. Example: 1
     * @bodyParam period_id integer Optional period ID or null. Example: 3
     * @bodyParam published_at datetime Optional publish time. Example: 2026-07-01 09:00:00
     * @bodyParam expires_at datetime Optional expiry time. Example: 2026-07-31 23:59:00
     * @response 200 {"message":"Duyuru guncellendi.","announcement":{"id":1,"title":"Guncel duyuru"}}
     * @response 403 {"message":"Proje baglantisi kaldirma yalnizca ust admin icin yapilabilir."}
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
            'target_roles.*' => 'in:super_admin,coordinator,staff,student,alumni',
            'target_units' => 'nullable|array',
            'target_units.*' => 'in:media,operations,program,finance,official_affairs',
            'project_id'   => 'nullable|exists:projects,id',
            'period_id'    => 'nullable|exists:periods,id',
            'published_at' => 'nullable|date',
            'expires_at'   => 'nullable|date',
        ]);
        $validated = IstanbulDateTime::normalizeFields($validated, ['published_at', 'expires_at']);

        $this->assertPeriodWritable($request, $announcement->period_id);

        if (array_key_exists('project_id', $validated)) {
            $newProjectId = $validated['project_id'];
            if ($newProjectId !== null) {
                $this->assertProjectAnnouncementScope($request, (int) $newProjectId, 'announcements.update');
            } elseif (! $this->permissionResolver->hasGlobalScope($request->user(), 'announcements.update')) {
                abort(403, 'Proje baglantisi kaldirma yalnizca ust admin icin yapilabilir.');
            }

            if (! array_key_exists('period_id', $validated) && (int) ($announcement->project_id ?? 0) !== (int) ($newProjectId ?? 0)) {
                $validated['period_id'] = null;
            }
        }
        if (array_key_exists('period_id', $validated)) {
            $this->resolveAnnouncementPeriod($request, $validated, 'announcements.update', true);
        }
        if (array_key_exists('target_units', $validated)) {
            $this->assertTargetUnitsAllowed($request->user(), 'announcements.update', $validated['target_units'] ?? []);
        }

        $announcement->update($validated);

        return response()->json([
            'message'      => 'Duyuru güncellendi.',
            'announcement' => $announcement->fresh(['project:id,name', 'period:id,name,status', 'creator:id,name,surname']),
        ]);
    }

    /**
     * Delete a panel announcement.
     *
     * Requires permission: `announcements.delete` and access to the announcement. Completed periods require archive update permission before deletion.
     *
     * @group Announcements & Inbox
     * @urlParam id integer required Announcement ID. Example: 1
     * @response 200 {"message":"Duyuru silindi."}
     * @response 403 {"message":"Bu duyuru icin yetkiniz bulunmuyor."}
     */

    public function destroy(int $id)
    {
        $announcement = Announcement::findOrFail($id);
        $this->abortUnlessAnnouncementAccessible(request(), $announcement, 'announcements.delete');
        $this->assertPeriodWritable(request(), $announcement->period_id);
        $announcement->delete();

        return response()->json(['message' => 'Duyuru silindi.']);
    }

    /**
     * Send a standalone SMS announcement.
     *
     * Requires permission: `announcements.send_sms`. Project and unit targets are validated against caller scope. Non-global senders can target participants in manageable projects, staff in allowed units, selected accessible users, or themselves.
     *
     * @group Announcements & Inbox
     * @bodyParam message string required SMS message, max 160 characters. Example: Toplanti saat 10:00
     * @bodyParam target_roles array Optional target roles. Example: ["student"]
     * @bodyParam target_units array Optional target units. Example: ["program"]
     * @bodyParam project_id integer Optional project ID. Example: 1
     * @bodyParam user_ids array Optional direct user IDs. Example: [12,13]
     * @response 200 {"message":"SMS gonderimi tamamlandi.","sent_to":12}
     * @response 403 {"message":"Secilen kullanicilarin bir kismi erisim kapsaminiz disinda."}
     */

    public function sendSms(Request $request)
    {
        $this->abortUnlessAllowed($request, 'announcements.send_sms');
        $validated = $request->validate([
            'message'      => 'required|string|max:160',
            'target_roles' => 'nullable|array',
            'target_roles.*' => 'in:super_admin,coordinator,staff,student,alumni',
            'target_units' => 'nullable|array',
            'target_units.*' => 'in:media,operations,program,finance,official_affairs',
            'project_id'   => 'nullable|exists:projects,id',
            'user_ids'     => 'nullable|array',
            'user_ids.*'   => 'exists:users,id',
        ]);

        if (! empty($validated['project_id'])) {
            $this->assertProjectAnnouncementScope($request, (int) $validated['project_id'], 'announcements.send_sms');
        }
        $this->assertTargetUnitsAllowed($request->user(), 'announcements.send_sms', $validated['target_units'] ?? []);

        $targetUsers = $this->resolveTargetUsers($request->user(), $validated, 'announcements.send_sms');

        $sent = $this->dispatchSms($targetUsers, $validated['message'], $validated['project_id'] ?? null);

        return response()->json([
            'message' => 'SMS gönderimi tamamlandı.',
            'sent_to' => $sent,
        ]);
    }

    /**
     * Send a standalone email announcement.
     *
     * Requires permission: `announcements.send_email`. Project, unit and direct user targets are validated with the same target resolver as SMS. Optional attachment is stored through `MediaStorage` and passed to the notification service.
     *
     * @group Announcements & Inbox
     * @bodyParam subject string required Email subject. Example: Program duyurusu
     * @bodyParam body string required Email body. Example: Merhaba, yeni program duyurusu ektedir.
     * @bodyParam target_roles array Optional target roles. Example: ["student"]
     * @bodyParam target_units array Optional target units. Example: ["program"]
     * @bodyParam project_id integer Optional project ID. Example: 1
     * @bodyParam user_ids array Optional direct user IDs. Example: [12,13]
     * @bodyParam attachment file Optional attachment. Allowed: pdf, jpg, png, docx. Max 10 MB.
     * @response 200 {"message":"E-posta gonderimi tamamlandi.","sent_to":12}
     * @response 200 {"message":"E-posta gonderimi basarisiz veya alici e-postasi bulunamadi.","sent_to":0}
     */

    public function sendEmail(Request $request)
    {
        $this->abortUnlessAllowed($request, 'announcements.send_email');
        $validated = $request->validate([
            'subject'      => 'required|string|max:255',
            'body'         => 'required|string',
            'target_roles' => 'nullable|array',
            'target_roles.*' => 'in:super_admin,coordinator,staff,student,alumni',
            'target_units' => 'nullable|array',
            'target_units.*' => 'in:media,operations,program,finance,official_affairs',
            'project_id'   => 'nullable|exists:projects,id',
            'user_ids'     => 'nullable|array',
            'user_ids.*'   => 'exists:users,id',
            'attachment'   => 'nullable|file|mimes:pdf,jpg,png,docx|max:10240',
        ]);

        if (! empty($validated['project_id'])) {
            $this->assertProjectAnnouncementScope($request, (int) $validated['project_id'], 'announcements.send_email');
        }
        $this->assertTargetUnitsAllowed($request->user(), 'announcements.send_email', $validated['target_units'] ?? []);

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
            'message' => $sent > 0
                ? 'E-posta gonderimi tamamlandi.'
                : 'E-posta gonderimi basarisiz veya alici e-postasi bulunamadi.',
            'sent_to' => $sent,
        ]);
    }

    // ─── YARDIMCI METODLAR ────────────────────────────────────────────────────

    /**
     * List announcement communication logs.
     *
     * Requires permission: `announcements.view`. Global users can see all email/SMS logs; project-scoped users see logs they sent or logs in accessible projects. Supports type, status, project, sender, search and date filters.
     *
     * @group Announcements & Inbox
     * @queryParam type string Optional channel filter. Allowed values: email, sms. Example: email
     * @queryParam status string Optional status filter. Example: sent
     * @queryParam project_id integer Optional project filter. Example: 1
     * @queryParam sender_id integer Optional sender user ID. Example: 8
     * @queryParam search string Optional subject/content search. Example: program
     * @queryParam date_from date Optional start date. Example: 2026-06-01
     * @queryParam date_to date Optional end date. Must be after or equal to date_from. Example: 2026-06-30
     * @queryParam per_page integer Optional page size between 1 and 100. Defaults to 20. Example: 20
     * @response 200 {"logs":{"data":[{"id":1,"type":"email","recipients_count":20,"attachment_download_url":"/panel/announcements/communication-logs/1/attachment"}],"current_page":1}}
     */

    public function communicationLogs(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'announcements.view');
        $validated = $request->validate([
            'type' => 'nullable|in:email,sms',
            'status' => 'nullable|string|max:50',
            'project_id' => 'nullable|integer|exists:projects,id',
            'sender_id' => 'nullable|integer|exists:users,id',
            'search' => 'nullable|string|max:255',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = CommunicationLog::query()
            ->with(['sender:id,name,surname,role', 'project:id,name'])
            ->whereIn('type', ['email', 'sms'])
            ->latest();

        $query = $this->scopeCommunicationLogs($request, $query, 'announcements.view');

        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        if (! empty($validated['project_id'])) {
            $projectId = (int) $validated['project_id'];
            $this->assertProjectAnnouncementScope($request, $projectId, 'announcements.view');
            $query->where('project_id', $projectId);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['sender_id'])) {
            $query->where('sender_id', (int) $validated['sender_id']);
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($builder) use ($search) {
                $builder->where('subject', 'like', '%' . $search . '%')
                    ->orWhere('content', 'like', '%' . $search . '%');
            });
        }

        if (! empty($validated['date_from'])) {
            $query->where('created_at', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->where('created_at', '<=', $validated['date_to']);
        }

        $logs = $query
            ->paginate((int) ($validated['per_page'] ?? 20))
            ->through(fn (CommunicationLog $log) => $this->communicationLogPayload($log));

        return response()->json([
            'logs' => $logs,
        ]);
    }

    /**
     * Export announcement communication logs.
     *
     * Requires permission: `announcements.view`. Applies the same communication log scope and filters as the list endpoint and exports through the shared admin export responder.
     *
     * @group Announcements & Inbox
     * @queryParam type string Optional channel filter. Allowed values: email, sms. Example: email
     * @queryParam status string Optional status filter. Example: sent
     * @queryParam project_id integer Optional project filter. Example: 1
     * @queryParam sender_id integer Optional sender user ID. Example: 8
     * @queryParam search string Optional subject/content search. Example: program
     * @queryParam date_from date Optional start date. Example: 2026-06-01
     * @queryParam date_to date Optional end date. Example: 2026-06-30
     * @queryParam format string Optional export format. Allowed values: xlsx, excel, pdf, docx, word, csv. Defaults to csv. Example: xlsx
     * @response 200 {"download":"Communication logs export file stream"}
     */

    public function exportCommunicationLogs(Request $request)
    {
        $this->abortUnlessAllowed($request, 'announcements.view');
        $validated = $request->validate([
            'type' => 'nullable|in:email,sms',
            'status' => 'nullable|string|max:50',
            'project_id' => 'nullable|integer|exists:projects,id',
            'sender_id' => 'nullable|integer|exists:users,id',
            'search' => 'nullable|string|max:255',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);

        $query = CommunicationLog::query()
            ->with(['sender:id,name,surname,role', 'project:id,name'])
            ->whereIn('type', ['email', 'sms'])
            ->latest();

        $query = $this->scopeCommunicationLogs($request, $query, 'announcements.view');

        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        if (! empty($validated['project_id'])) {
            $projectId = (int) $validated['project_id'];
            $this->assertProjectAnnouncementScope($request, $projectId, 'announcements.view');
            $query->where('project_id', $projectId);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['sender_id'])) {
            $query->where('sender_id', (int) $validated['sender_id']);
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($builder) use ($search) {
                $builder->where('subject', 'like', '%' . $search . '%')
                    ->orWhere('content', 'like', '%' . $search . '%');
            });
        }

        if (! empty($validated['date_from'])) {
            $query->where('created_at', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->where('created_at', '<=', $validated['date_to']);
        }

        $logs = $query->get();
        $headings = ['ID', 'Kanal', 'Durum', 'Alici Sayisi', 'Konu', 'Icerik (kisa)', 'Proje', 'Gonderen', 'Tarih'];
        $rows = $logs->map(fn (CommunicationLog $log) => [
            $log->id,
            $log->type,
            $log->status,
            $log->recipients_count,
            $log->subject ?? '-',
            mb_substr((string) ($log->content ?? ''), 0, 120),
            $log->project?->name ?? '-',
            $log->sender ? trim($log->sender->name . ' ' . $log->sender->surname) : '-',
            $log->created_at?->format('d.m.Y H:i') ?? '-',
        ])->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'iletisim_loglari_' . now()->format('Ymd_His'),
            'Iletisim Loglari',
            $headings,
            $rows,
        );
    }

    /**
     * Download a communication log attachment.
     *
     * Requires permission: `announcements.view`. The caller can download an attachment if they sent the log, have global scope, or can access the log project. Depending on storage configuration the response is a JSON direct URL or streamed file download.
     *
     * @group Announcements & Inbox
     * @urlParam id integer required Communication log ID. Example: 1
     * @response 200 {"download_url":"https://storage.example.com/announcement_attachments/file.pdf"}
     * @response 200 {"download":"Binary attachment file stream"}
     * @response 403 {"message":"Bu ek dosyayi indirme yetkiniz yok."}
     * @response 404 {"message":"Ek dosya storage uzerinde bulunamadi."}
     */

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
        $targetUnits = $validated['target_units'] ?? [];

        if ($this->permissionResolver->hasGlobalScope($sender, $permission)) {
            return $this->resolveTargetUsersAsSuperAdmin($validated, $columns);
        }

        $this->assertTargetUnitsAllowed($sender, $permission, $targetUnits);
        $participantIds = $this->participantUserIdsInManageableProjects($sender, $permission);
        $unitUserIds = $this->staffUserIdsInTargetUnits($targetUnits);

        if (! empty($validated['user_ids'])) {
            foreach ($validated['user_ids'] as $uid) {
                $uid = (int) $uid;
                abort_unless(
                    in_array($uid, $participantIds, true) || in_array($uid, $unitUserIds, true) || $uid === (int) $sender->id,
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
        if ($privileged !== [] && empty($validated['project_id']) && $targetUnits === []) {
            abort(403, 'Bu rollere toplu mesaj icin proje veya birim secilmelidir.');
        }

        $targetIds = collect();

        if ($targetUnits !== []) {
            $unitQuery = User::query()
                ->where('status', 'active')
                ->whereIn('id', $unitUserIds);

            if (! empty($validated['target_roles'])) {
                $unitQuery->whereIn('role', $validated['target_roles']);
            }

            $targetIds = $targetIds->merge($unitQuery->pluck('id'));
        }

        if (! empty($validated['project_id'])) {
            $projectQuery = User::query()
                ->where('status', 'active')
                ->whereHas('participations', fn ($q) =>
                    $q->where('project_id', $validated['project_id'])->where('status', 'active'));

            if (! empty($validated['target_roles'])) {
                $projectQuery->whereIn('role', $validated['target_roles']);
            }

            $targetIds = $targetIds->merge($projectQuery->pluck('id'));
        }

        if ($targetIds->isNotEmpty()) {
            return User::query()
                ->where('status', 'active')
                ->whereIn('id', $targetIds->unique()->values()->all())
                ->get($columns);
        }

        if ($participantIds === []) {
            return User::query()->whereRaw('0 = 1')->get($columns);
        }

        $query = User::query()
            ->where('status', 'active')
            ->whereIn('id', $participantIds);

        if (! empty($validated['target_roles'])) {
            $query->whereIn('role', $validated['target_roles']);
        }

        return $query->get($columns);
    }

    private function resolveTargetUsersAsSuperAdmin(array $validated, array $columns): \Illuminate\Database\Eloquent\Collection
    {
        $targetUnits = $validated['target_units'] ?? [];
        $query = User::query()->where('status', 'active');

        if (! empty($validated['user_ids'])) {
            return $query->whereIn('id', $validated['user_ids'])->get($columns);
        }

        if ($targetUnits !== []) {
            $targetIds = collect($this->staffUserIdsInTargetUnits($targetUnits));

            if (! empty($validated['project_id'])) {
                $projectQuery = User::query()
                    ->where('status', 'active')
                    ->whereHas('participations', fn ($q) =>
                        $q->where('project_id', $validated['project_id'])->where('status', 'active'));

                if (! empty($validated['target_roles'])) {
                    $projectQuery->whereIn('role', $validated['target_roles']);
                }

                $targetIds = $targetIds->merge($projectQuery->pluck('id'));
            }

            $unitQuery = User::query()
                ->where('status', 'active')
                ->whereIn('id', $targetIds->unique()->values()->all());

            if (! empty($validated['target_roles'])) {
                $unitQuery->whereIn('role', $validated['target_roles']);
            }

            return $unitQuery->get($columns);
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

    private function dispatchSms(\Illuminate\Database\Eloquent\Collection $users, string $message, ?int $projectId = null): int
    {
        return $this->notificationService->sendSms(
            $users->pluck('phone')->filter()->values()->all(),
            $message,
            $projectId,
            Auth::id()
        );

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
        return $this->notificationService->sendEmail(
            $users->pluck('email')->filter()->values()->all(),
            (string) ($announcement->title ?? 'Duyuru'),
            (string) ($announcement->content ?? ''),
            $announcement->project_id ?? null,
            Auth::id(),
            $attachmentPath
        );

        $emails = $users
            ->pluck('email')
            ->filter(fn ($email) => is_string($email) && $email !== '')
            ->unique()
            ->values();

        if ($emails->isEmpty()) {
            Log::warning('resend.no_recipients', [
                'subject' => (string) ($announcement->title ?? 'Duyuru'),
                'project_id' => $announcement->project_id ?? null,
                'candidate_user_count' => $users->count(),
            ]);
            CommunicationLog::create([
                'type' => 'email',
                'sender_id' => Auth::id(),
                'recipients_count' => 0,
                'subject' => (string) ($announcement->title ?? 'Duyuru'),
                'content' => mb_substr((string) ($announcement->content ?? ''), 0, 500),
                'attachment_path' => $attachmentPath,
                'status' => 'failed',
                'project_id' => $announcement->project_id ?? null,
            ]);
            return 0;
        }

        Log::info('resend.dispatch.start', [
            'subject' => $subject,
            'project_id' => $announcement->project_id ?? null,
            'recipient_count' => $emails->count(),
        ]);

        $apiKey = (string) config('services.resend.key');
        $fromAddress = (string) config('services.resend.from', config('mail.from.address'));
        $fromName = (string) config('services.resend.from_name', config('mail.from.name'));
        $from = $fromName !== '' ? "{$fromName} <{$fromAddress}>" : $fromAddress;
        $subject = (string) ($announcement->title ?? 'Duyuru');
        $rawContent = (string) ($announcement->content ?? '');
        $textContent = trim(strip_tags($rawContent));
        $htmlContent = nl2br(e($rawContent));

        if ($apiKey === '' || $fromAddress === '') {
            Log::warning('resend.missing_configuration', [
                'has_api_key' => $apiKey !== '',
                'from_address' => $fromAddress,
            ]);

            CommunicationLog::create([
                'type' => 'email',
                'sender_id' => Auth::id(),
                'recipients_count' => 0,
                'subject' => $subject,
                'content' => substr($rawContent, 0, 500),
                'attachment_path' => $attachmentPath,
                'status' => 'failed',
                'project_id' => $announcement->project_id ?? null,
            ]);

            return 0;
        }

        $attachments = [];
        if ($attachmentPath && MediaStorage::exists($attachmentPath)) {
            try {
                $attachments[] = [
                    'filename' => basename($attachmentPath),
                    'content' => base64_encode(MediaStorage::disk()->get($attachmentPath)),
                ];
            } catch (\Throwable $exception) {
                Log::warning('resend.attachment_read_failed', [
                    'path' => $attachmentPath,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $successCount = 0;
        foreach ($emails as $email) {
            $payload = [
                'from' => $from,
                'to' => [$email],
                'subject' => $subject,
                'text' => $textContent,
                'html' => $htmlContent,
            ];
            if ($attachments !== []) {
                $payload['attachments'] = $attachments;
            }

            try {
                $response = Http::withToken($apiKey)
                    ->acceptJson()
                    ->post('https://api.resend.com/emails', $payload);

                if ($response->successful()) {
                    $successCount++;
                } else {
                    Log::warning('resend.send_failed', [
                        'email' => $email,
                        'status' => $response->status(),
                        'body' => $response->json() ?? $response->body(),
                    ]);
                }
            } catch (\Throwable $exception) {
                Log::warning('resend.send_exception', [
                    'email' => $email,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        CommunicationLog::create([
            'type' => 'email',
            'sender_id' => Auth::id(),
            'recipients_count' => $successCount,
            'subject' => $subject,
            'content' => substr($rawContent, 0, 500),
            'attachment_path' => $attachmentPath,
            'status' => $successCount > 0 ? 'sent' : 'failed',
            'project_id' => $announcement->project_id ?? null,
        ]);

        Log::info('resend.dispatch.result', [
            'subject' => $subject,
            'project_id' => $announcement->project_id ?? null,
            'recipient_count' => $emails->count(),
            'success_count' => $successCount,
        ]);

        return $successCount;
    }
}
