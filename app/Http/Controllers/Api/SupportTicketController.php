<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\SupportReply;
use App\Models\SupportTicket;
use App\Models\Project;
use App\Models\User;
use App\Support\AdminExportResponder;
use App\Services\PermissionResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SupportTicketController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {
    }

    private function canAccessTicket(User $user, SupportTicket $ticket, string $permission): bool
    {
        if ($this->permissionResolver->hasGlobalScope($user, $permission)) {
            return true;
        }

        if ($ticket->user_id === $user->id) {
            return true;
        }

        if (! $this->permissionResolver->hasPermission($user, $permission)) {
            return false;
        }

        if ($ticket->assigned_to === $user->id) {
            return true;
        }

        if ($ticket->project_id === null) {
            return false;
        }

        return $this->permissionResolver->canAccessProject($user, $permission, $ticket->project_id);
    }

    private function pickLeastLoadedAssignee(array $candidateIds): ?int
    {
        if ($candidateIds === []) {
            return null;
        }

        $counts = SupportTicket::query()
            ->select('assigned_to', DB::raw('COUNT(*) as open_count'))
            ->whereIn('assigned_to', $candidateIds)
            ->whereIn('status', ['open', 'in_progress'])
            ->groupBy('assigned_to')
            ->pluck('open_count', 'assigned_to');

        $bestId = null;
        $bestCount = PHP_INT_MAX;

        foreach ($candidateIds as $candidateId) {
            $count = (int) ($counts[$candidateId] ?? 0);
            if ($count < $bestCount) {
                $bestId = (int) $candidateId;
                $bestCount = $count;
            }
        }

        return $bestId;
    }

    private function candidateIdsByCategory(?string $category): array
    {
        $raw = Str::lower(trim((string) $category));
        if ($raw === '') {
            return [];
        }

        $unitKeywords = match (true) {
            str_contains($raw, 'tasar') || str_contains($raw, 'medya') || str_contains($raw, 'social') => ['medya', 'tasarim', 'icerik', 'content'],
            str_contains($raw, 'evrak') => ['idari', 'operasyon'],
            str_contains($raw, 'konaklama') || str_contains($raw, 'bilet') || str_contains($raw, 'ulas') || str_contains($raw, 'yemek') || str_contains($raw, 'arac') => ['operasyon', 'idari'],
            str_contains($raw, 'teknik') || str_contains($raw, 'hata') => ['it', 'teknik', 'bilisim'],
            default => [],
        };

        if ($unitKeywords === []) {
            return [];
        }

        return User::query()
            ->whereIn('role', ['staff', 'coordinator'])
            ->where('status', 'active')
            ->whereHas('staffProfile', function (Builder $builder) use ($unitKeywords) {
                $builder->where(function (Builder $inner) use ($unitKeywords) {
                    foreach ($unitKeywords as $keyword) {
                        $inner->orWhereRaw('LOWER(unit) LIKE ?', ['%' . $keyword . '%']);
                    }
                });
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function resolveAutoAssignee(?int $projectId, ?string $category): ?int
    {
        if ($projectId) {
            $project = Project::query()
                ->with(['coordinators' => function ($builder) {
                    $builder->where('status', 'active');
                }])
                ->find($projectId);

            $coordinatorIds = $project?->coordinators
                ?->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all() ?? [];

            $bestCoordinator = $this->pickLeastLoadedAssignee($coordinatorIds);
            if ($bestCoordinator) {
                return $bestCoordinator;
            }
        }

        $categoryCandidates = $this->candidateIdsByCategory($category);
        $bestCategoryAssignee = $this->pickLeastLoadedAssignee($categoryCandidates);
        if ($bestCategoryAssignee) {
            return $bestCategoryAssignee;
        }

        $fallbackAdmin = User::query()
            ->where('role', 'super_admin')
            ->where('status', 'active')
            ->orderBy('id')
            ->value('id');

        return $fallbackAdmin ? (int) $fallbackAdmin : null;
    }

    /**
     * Kullanicinin kendi destek kayitlarini listele.
     */
    public function myTickets(Request $request): JsonResponse
    {
        $tickets = SupportTicket::query()
            ->with(['project:id,name', 'replies.user:id,name,surname,role'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['tickets' => $tickets]);
    }

    public function exportMyTickets(Request $request)
    {
        $tickets = SupportTicket::query()
            ->with(['project:id,name'])
            ->where('user_id', $request->user()->id)
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->project_id))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->status))
            ->latest()
            ->get();

        $headings = ['ID', 'Konu', 'Kategori', 'Durum', 'Proje', 'Olusturma Tarihi'];
        $rows = $tickets->map(fn (SupportTicket $ticket) => [
            $ticket->id,
            $ticket->subject,
            $ticket->category,
            $ticket->status,
            $ticket->project?->name ?? '-',
            $ticket->created_at?->format('d.m.Y H:i') ?? '-',
        ])->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'destek_taleplerim_' . now()->format('Ymd_His'),
            'Destek Taleplerim',
            $headings,
            $rows,
        );
    }

    /**
     * Giris yapmis kullanici icin yeni destek kaydi.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, ['student', 'alumni'], true)) {
            $this->abortUnlessAllowed($request, 'support.create');
        }

        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'category' => 'required|string|max:100',
            'project_id' => 'nullable|exists:projects,id',
            'message' => 'required|string',
        ]);

        if (
            ! empty($validated['project_id'])
            && $this->permissionResolver->hasPermission($user, 'support.create')
        ) {
            abort_unless(
                $this->permissionResolver->canAccessProject($user, 'support.create', (int) $validated['project_id']),
                403,
                'Bu proje icin destek talebi olusturma yetkiniz bulunmuyor.'
            );
        }

        $autoAssigneeId = $this->resolveAutoAssignee($validated['project_id'] ?? null, $validated['category'] ?? null);

        $ticket = SupportTicket::create([
            'user_id' => $request->user()->id,
            'name' => trim($request->user()->name . ' ' . $request->user()->surname),
            'email' => $request->user()->email,
            'subject' => $validated['subject'],
            'category' => $validated['category'],
            'project_id' => $validated['project_id'] ?? null,
            'assigned_to' => $autoAssigneeId,
            'message' => $validated['message'],
            'status' => $autoAssigneeId ? 'in_progress' : 'open',
        ]);

        return response()->json([
            'message' => 'Destek talebiniz basariyla alindi.',
            'ticket' => $ticket->fresh(['project:id,name']),
        ], 201);
    }

    /**
     * Ziyaretci iletisim formu.
     */
    public function storePublic(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'subject' => 'required|string|max:255',
            'category' => 'required|string|max:100',
            'project_id' => 'nullable|exists:projects,id',
            'message' => 'required|string',
        ]);

        $autoAssigneeId = $this->resolveAutoAssignee($validated['project_id'] ?? null, $validated['category'] ?? null);

        $ticket = SupportTicket::create([
            'user_id' => null,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'subject' => $validated['subject'],
            'category' => $validated['category'],
            'project_id' => $validated['project_id'] ?? null,
            'assigned_to' => $autoAssigneeId,
            'message' => $validated['message'],
            'status' => $autoAssigneeId ? 'in_progress' : 'open',
        ]);

        return response()->json([
            'message' => 'Mesajiniz basariyla alindi. En kisa surede sizinle iletisime gececegiz.',
            'ticket' => $ticket,
        ], 201);
    }

    /**
     * Mesaja yanit ekle.
     */
    public function reply(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'support.reply');
        $validated = $request->validate([
            'message' => 'required|string',
        ]);

        $ticket = SupportTicket::findOrFail($id);
        abort_unless($this->canAccessTicket($request->user(), $ticket, 'support.reply'), 403, 'Bu ticket icin yanit yetkiniz yok.');

        $reply = SupportReply::create([
            'ticket_id' => $ticket->id,
            'user_id' => $request->user()->id,
            'message' => $validated['message'],
        ]);

        if ($ticket->status === 'open') {
            $ticket->update(['status' => 'in_progress']);
        }

        return response()->json(['reply' => $reply->load('user:id,name,surname,role')]);
    }

    /**
     * Admin tum ticketlari, koordinatör ise kendi proje havuzunu listeler.
     */
    public function index(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'support.view');
        $user = $request->user();

        $query = SupportTicket::query()
            ->with([
                'user:id,name,surname,email,role',
                'assignee:id,name,surname,role',
                'project:id,name',
                'replies.user:id,name,surname,role',
            ])
            ->latest();

        if (! $this->permissionResolver->hasGlobalScope($user, 'support.view')) {
            $manageableProjectIds = $this->permissionResolver->projectIdsForPermission($user, 'support.view');

            $query->where(function (Builder $builder) use ($user, $manageableProjectIds) {
                $builder
                    ->whereIn('project_id', $manageableProjectIds)
                    ->orWhere('assigned_to', $user->id)
                    ->orWhere('user_id', $user->id);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        if ($request->filled('assigned_to')) {
            $query->where('assigned_to', $request->assigned_to);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function (Builder $builder) use ($search) {
                $builder
                    ->where('subject', 'like', '%' . $search . '%')
                    ->orWhere('message', 'like', '%' . $search . '%')
                    ->orWhere('name', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%');
            });
        }

        return response()->json(['tickets' => $query->paginate(20)]);
    }

    public function export(Request $request)
    {
        $this->abortUnlessAllowed($request, 'support.export');
        $user = $request->user();

        $query = SupportTicket::query()
            ->with([
                'user:id,name,surname,email,role',
                'assignee:id,name,surname,role',
                'project:id,name',
            ])
            ->latest();

        if (! $this->permissionResolver->hasGlobalScope($user, 'support.export')) {
            $manageableProjectIds = $this->permissionResolver->projectIdsForPermission($user, 'support.export');

            $query->where(function (Builder $builder) use ($user, $manageableProjectIds) {
                $builder
                    ->whereIn('project_id', $manageableProjectIds)
                    ->orWhere('assigned_to', $user->id)
                    ->orWhere('user_id', $user->id);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function (Builder $builder) use ($search) {
                $builder
                    ->where('subject', 'like', '%' . $search . '%')
                    ->orWhere('message', 'like', '%' . $search . '%')
                    ->orWhere('name', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%');
            });
        }

        $tickets = $query->get();

        $headings = ['ID', 'Konu', 'Kategori', 'Durum', 'Talep Sahibi', 'E-posta', 'Proje', 'Atanan Kisi', 'Olusturma Tarihi'];
        $rows = $tickets->map(fn (SupportTicket $ticket) => [
            $ticket->id,
            $ticket->subject,
            $ticket->category,
            $ticket->status,
            $ticket->user ? trim($ticket->user->name . ' ' . $ticket->user->surname) : $ticket->name,
            $ticket->user?->email ?? $ticket->email ?? '-',
            $ticket->project?->name ?? '-',
            $ticket->assignee ? trim($ticket->assignee->name . ' ' . $ticket->assignee->surname) : '-',
            $ticket->created_at?->format('d.m.Y H:i') ?? '-',
        ])->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'destek_kayitlari_' . now()->format('Ymd_His'),
            'Destek Kayitlari',
            $headings,
            $rows,
        );
    }

    /**
     * Destek atamasi icin sinirli kullanici listesi (users.view olmadan).
     */
    public function assignableUsers(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'support.assign');

        $users = User::query()
            ->select(['id', 'name', 'surname', 'role'])
            ->where('status', 'active')
            ->whereIn('role', ['super_admin', 'coordinator', 'staff'])
            ->orderBy('name')
            ->orderBy('surname')
            ->get();

        return response()->json(['users' => $users]);
    }

    /**
     * Ticket'i personele ata. Sadece super admin.
     */
    public function assign(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'support.assign');

        $request->validate(['assigned_to' => 'required|exists:users,id']);

        $ticket = SupportTicket::findOrFail($id);
        abort_unless($this->canAccessTicket($request->user(), $ticket, 'support.assign'), 403, 'Bu ticket icin atama yetkiniz yok.');
        $ticket->update([
            'assigned_to' => $request->assigned_to,
            'status' => 'in_progress',
        ]);

        return response()->json([
            'message' => 'Ticket atandi.',
            'ticket' => $ticket->fresh('assignee:id,name,surname'),
        ]);
    }

    /**
     * Ticket'i kapat.
     */
    public function close(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'support.close');
        $ticket = SupportTicket::findOrFail($id);
        abort_unless($this->canAccessTicket($request->user(), $ticket, 'support.close'), 403, 'Bu ticketi kapatma yetkiniz yok.');

        $ticket->update(['status' => 'closed']);

        return response()->json([
            'message' => 'Ticket kapatildi.',
            'ticket' => $ticket,
        ]);
    }
}
