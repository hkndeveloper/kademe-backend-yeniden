<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\CreditLog;
use App\Models\KvkkForgetRequest;
use App\Models\User;
use App\Services\PermissionResolver;
use App\Support\AdminExportResponder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {}

    /**
     * GET /admin/users
     * Kullanici listesi.
     */
    /**
     * GET /admin/users/create-options
     * Yeni kullanici formu icin Spatie rol listesi (users.create + global kapsam).
     */
    public function createOptions(Request $request)
    {
        $this->abortUnlessAllowed($request, 'users.create');
        abort_unless(
            $this->permissionResolver->hasGlobalScope($request->user(), 'users.create'),
            403,
            'Kullanici olusturmak icin tum sistem kapsami gerekir.'
        );

        $roles = Role::query()
            ->whereIn('name', ['student', 'alumni'])
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => [
                'name' => $role->name,
                'label' => config('permission_catalog.role_labels.'.$role->name) ?? Str::headline($role->name),
            ])
            ->values();

        return response()->json(['roles' => $roles]);
    }

    /**
     * POST /admin/users
     * Panelden kullanici olusturma. Sifre bos ise guclu rastgele sifre uretilir (yanitta bir kez doner).
     */
    public function storeUser(Request $request)
    {
        $this->abortUnlessAllowed($request, 'users.create');
        abort_unless(
            $this->permissionResolver->hasGlobalScope($request->user(), 'users.create'),
            403,
            'Kullanici olusturmak icin tum sistem kapsami gerekir.'
        );

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'surname' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'phone' => 'nullable|string|max:20',
            'tc_no' => 'nullable|string|size:11',
            'role' => 'required|string|in:student,alumni|exists:roles,name',
        ]);

        $roleName = $validated['role'];

        $passwordPlain = Str::password(24);

        $user = User::create([
            'name' => trim($validated['name']),
            'surname' => trim($validated['surname']),
            'email' => Str::lower(trim($validated['email'])),
            'phone' => isset($validated['phone']) ? trim((string) $validated['phone']) : null,
            'tc_no' => $validated['tc_no'] ?? null,
            'password' => Hash::make($passwordPlain),
            'role' => $roleName,
            'status' => 'active',
            'email_verified_at' => now(),
            'must_change_password' => true,
        ]);

        $user->syncRoles([$roleName]);

        if (in_array($roleName, ['student', 'alumni'], true)) {
            $user->profile()->firstOrCreate(
                ['user_id' => $user->id],
                []
            );
        }

        $linkStatus = Password::sendResetLink(['email' => $user->email]);

        return response()->json([
            'message' => $linkStatus === Password::RESET_LINK_SENT
                ? 'Kullanici olusturuldu. Sifre belirleme baglantisi e-posta ile gonderildi.'
                : 'Kullanici olusturuldu. E-posta gonderilemedi; kullanici "Sifremi unuttum" ile baglanti talep edebilir.',
            'user' => $user->fresh(['roles:id,name']),
            'reset_email_status' => $linkStatus,
        ], 201);
    }

    public function index(Request $request)
    {
        $this->abortUnlessAllowed($request, 'users.view');
        $query = User::with('profile')->withTrashed(false);
        $this->permissionResolver->applyUserScope($query, $request->user(), 'users.view');
        $query->whereIn('role', ['student', 'alumni']);

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        if ($request->filled('status')) {
            $query->where('status', $this->normalizeUserStatus((string) $request->status));
        }

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('name', 'like', "%$search%")
                    ->orWhere('surname', 'like', "%$search%")
                    ->orWhere('email', 'like', "%$search%")
                    ->orWhere('phone', 'like', "%$search%");
            });
        }

        if ($request->filled('university')) {
            $query->where('university', 'like', '%'.$request->university.'%');
        }

        return response()->json([
            'users' => $query->paginate(25),
        ]);
    }

    /**
     * GET /admin/users/{id}
     */
    public function showUser(int $id)
    {
        $user = User::with([
            'profile',
            'staffProfile',
            'participations.project:id,name',
            'applications.applicationForm.project:id,name',
            'attendances.program:id,title,start_at',
            'certificates.project:id,name',
            'coordinatedProjects:id,name',
            'assignedProjects:id,name',
            'roles:id,name',
        ])->findOrFail($id);

        abort_unless(in_array($user->role, ['student', 'alumni'], true), 404);

        abort_unless(
            $this->permissionResolver->canAccessUser(request()->user(), 'users.view', $user),
            403,
            'Bu kullaniciyi goruntuleme yetkiniz bulunmuyor.'
        );

        $documents = $user->staffProfile?->personal_documents ?? [];
        $creditScore = CreditLog::where('user_id', $user->id)->sum('amount');
        $absentCount = Attendance::where('user_id', $user->id)->where('is_valid', false)->count();

        return response()->json([
            'user' => $user,
            'documents' => $documents,
            'credit_score' => $creditScore,
            'absent_count' => $absentCount,
        ]);
    }

    /**
     * PUT /admin/users/{id}
     * Kullanici rolunu ve statusunu gunceller.
     */
    public function updateUser(Request $request, int $id)
    {
        $needsRoleUpdate = $request->has('role');
        $permission = $needsRoleUpdate ? 'users.assign_role' : 'users.update';
        $this->abortUnlessAllowed($request, $permission);

        $user = User::with('staffProfile')->findOrFail($id);
        if ($needsRoleUpdate) {
            abort_unless(
                $this->permissionResolver->hasGlobalScope($request->user(), 'users.assign_role'),
                403,
                'Rol atama islemi icin tum sistem kapsami gerekir.'
            );
        }

        abort_unless(
            $this->permissionResolver->canAccessUser($request->user(), $permission, $user),
            403,
            'Bu kullaniciyi guncelleme yetkiniz bulunmuyor.'
        );

        $validated = $request->validate([
            'role' => 'sometimes|string|in:student,alumni|exists:roles,name',
            'status' => 'sometimes|in:active,passive,blacklisted,alumni,inactive,banned',
        ]);

        if (isset($validated['status'])) {
            $validated['status'] = $this->normalizeUserStatus((string) $validated['status']);
        }

        $columnUpdates = collect($validated)
            ->except(['role'])
            ->toArray();
        if ($columnUpdates !== []) {
            $user->update($columnUpdates);
        }

        if (! empty($validated['role'])) {
            $user->syncRoles([$validated['role']]);
            if (array_key_exists($validated['role'], config('permission_catalog.role_labels', []))) {
                $user->forceFill(['role' => $validated['role']])->save();
            }
        }

        return response()->json([
            'message' => 'Kullanici guncellendi.',
            'user' => $user->fresh('roles'),
        ]);
    }

    /**
     * PUT /admin/users/{id}/coordinated-projects
     * Koordinatorun yonettigi projeleri project_coordinators uzerinden senkronize eder.
     */
    public function syncCoordinatedProjects(Request $request, int $id)
    {
        $this->abortUnlessAllowed($request, 'users.assign_role');
        abort_unless(
            $this->permissionResolver->hasGlobalScope($request->user(), 'users.assign_role'),
            403,
            'Koordinator proje atamasi icin tum sistem kapsami gerekir.'
        );

        $user = User::findOrFail($id);
        abort_unless(
            $this->permissionResolver->canAccessUser($request->user(), 'users.assign_role', $user),
            403,
            'Bu kullaniciyi guncelleme yetkiniz bulunmuyor.'
        );
        abort_unless($user->role === 'coordinator', 422, 'Yalnizca koordinator hesaplarina proje atanabilir.');

        $validated = $request->validate([
            'project_ids' => 'present|array',
            'project_ids.*' => 'integer|exists:projects,id',
        ]);

        $user->coordinatedProjects()->sync($validated['project_ids']);

        return response()->json([
            'message' => 'Koordinator projeleri guncellendi.',
            'coordinated_projects' => $user->coordinatedProjects()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * GET /admin/users/export
     * Kullanici listesini CSV olarak disa aktar.
     */
    public function exportUsers(Request $request)
    {
        $this->abortUnlessAllowed($request, 'users.export');
        $query = User::with('profile');
        $this->permissionResolver->applyUserScope($query, $request->user(), 'users.export');
        $query->whereIn('role', ['student', 'alumni']);

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        if ($request->filled('status')) {
            $query->where('status', $this->normalizeUserStatus((string) $request->status));
        }

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('name', 'like', "%$search%")
                    ->orWhere('surname', 'like', "%$search%")
                    ->orWhere('email', 'like', "%$search%")
                    ->orWhere('phone', 'like', "%$search%");
            });
        }

        $users = $query->get();
        $headings = ['ID', 'Ad', 'Soyad', 'E-posta', 'Telefon', 'Rol', 'Durum', 'Universite', 'Bolum', 'Kayit Tarihi'];
        $rows = $users->map(fn (User $user) => [
            $user->id,
            $user->name,
            $user->surname,
            $user->email,
            $user->phone ?? '-',
            $user->role,
            $user->status ?? 'active',
            $user->university ?? '-',
            $user->department ?? '-',
            $user->created_at?->format('d.m.Y') ?? '-',
        ])->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'kullanici_listesi_'.now()->format('Ymd_His'),
            'Kullanici Listesi',
            $headings,
            $rows,
        );
    }

    /**
     * Kullanici profil bilgilerini getirir.
     */
    public function getProfile(Request $request)
    {
        return response()->json([
            'user' => $request->user()->load('profile'),
        ]);
    }

    /**
     * Kullanici profil bilgilerini gunceller.
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validatedUser = $request->validate([
            'phone' => 'sometimes|nullable|string|max:20',
            'address' => 'sometimes|nullable|string',
            'birth_date' => 'sometimes|nullable|date',
            'university' => 'sometimes|nullable|string|max:255',
            'department' => 'sometimes|nullable|string|max:255',
            'class_year' => 'sometimes|nullable|string|max:50',
            'hometown' => 'sometimes|nullable|string|max:100',
        ]);

        $validatedProfile = $request->validate([
            'motivation_message' => 'sometimes|nullable|string',
            'linkedin_url' => 'sometimes|nullable|string|max:255',
            'github_url' => 'sometimes|nullable|string|max:255',
            'instagram_url' => 'sometimes|nullable|string|max:255',
        ]);

        $user->update($validatedUser);

        $user->profile()->updateOrCreate(
            ['user_id' => $user->id],
            $validatedProfile
        );

        return response()->json([
            'message' => 'Profil basariyla guncellendi.',
            'user' => $user->fresh('profile'),
        ]);
    }

    /**
     * Sifre degistirme.
     */
    public function changePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => 'required',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Mevcut sifreniz hatali.'],
            ]);
        }

        $user->update([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
        ]);

        return response()->json([
            'message' => 'Sifreniz basariyla degistirildi.',
        ]);
    }

    /**
     * KVKK onayini kaydet.
     */
    public function consentKvkk(Request $request)
    {
        $user = $request->user();

        if ($user->kvkk_consent_at) {
            return response()->json(['message' => 'KVKK onayi zaten verilmis.'], 400);
        }

        $user->update([
            'kvkk_consent_at' => now(),
        ]);

        return response()->json([
            'message' => 'KVKK aydinlatma metni basariyla onaylandi.',
        ]);
    }

    public function requestKvkkForget(Request $request)
    {
        $validated = $request->validate([
            'request_note' => 'nullable|string|max:1000',
        ]);

        $user = $request->user();
        if ((bool) $user->kvkk_forgotten) {
            return response()->json([
                'message' => 'Bu hesap icin unutulma hakki islemi daha once tamamlandi.',
            ], 422);
        }

        $pendingExists = KvkkForgetRequest::query()
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->exists();
        if ($pendingExists) {
            return response()->json([
                'message' => 'Bu hesap icin bekleyen bir unutulma talebi zaten mevcut.',
            ], 422);
        }

        $forgetRequest = KvkkForgetRequest::query()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'request_note' => $validated['request_note'] ?? null,
        ]);

        $user->forceFill(['kvkk_forget_requested_at' => now()])->save();
        $request->attributes->set('audit.subject', $forgetRequest);
        $request->attributes->set('audit.event', 'users.kvkk_forget.requested');
        $request->attributes->set('audit.description', 'users.kvkk_forget.requested');

        return response()->json([
            'message' => 'Unutulma hakki talebiniz alindi. Inceleme sonrasinda sonuc bilgilendirilecektir.',
            'forget_request' => $forgetRequest,
        ], 201);
    }

    public function listKvkkForgetRequests(Request $request)
    {
        $this->abortUnlessAllowed($request, 'users.view');
        abort_unless(
            $this->permissionResolver->hasGlobalScope($request->user(), 'users.view'),
            403,
            'Unutulma taleplerini listelemek icin tum sistem kapsami gerekir.'
        );

        $query = KvkkForgetRequest::query()
            ->with(['user:id,name,surname,email,role,status,kvkk_forgotten', 'reviewer:id,name,surname'])
            ->latest();
        if ($request->filled('status')) {
            $query->where('status', (string) $request->status);
        }

        return response()->json([
            'forget_requests' => $query->paginate(20),
        ]);
    }

    public function resolveKvkkForgetRequest(Request $request, int $id)
    {
        $this->abortUnlessAllowed($request, 'users.update');
        abort_unless(
            $this->permissionResolver->hasGlobalScope($request->user(), 'users.update'),
            403,
            'Unutulma talebi islemek icin tum sistem kapsami gerekir.'
        );

        $validated = $request->validate([
            'decision' => 'required|in:approve,reject',
            'reviewer_note' => 'nullable|string|max:2000',
        ]);

        $forgetRequest = KvkkForgetRequest::query()->with('user')->findOrFail($id);
        if ($forgetRequest->status !== 'pending') {
            return response()->json([
                'message' => 'Bu talep zaten sonuclandirilmis.',
            ], 422);
        }

        DB::beginTransaction();
        try {
            if ($validated['decision'] === 'approve') {
                $summary = $this->anonymizeUserData($forgetRequest->user);
                $forgetRequest->update([
                    'status' => 'completed',
                    'reviewer_note' => $validated['reviewer_note'] ?? null,
                    'reviewed_by' => $request->user()->id,
                    'reviewed_at' => now(),
                    'anonymized_at' => now(),
                    'anonymization_summary' => $summary,
                ]);
            } else {
                $forgetRequest->update([
                    'status' => 'rejected',
                    'reviewer_note' => $validated['reviewer_note'] ?? null,
                    'reviewed_by' => $request->user()->id,
                    'reviewed_at' => now(),
                    'anonymized_at' => null,
                    'anonymization_summary' => null,
                ]);
            }

            DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }

        $request->attributes->set('audit.subject', $forgetRequest);
        $request->attributes->set('audit.event', 'users.kvkk_forget.resolved');
        $request->attributes->set('audit.description', 'users.kvkk_forget.resolved');
        $request->attributes->set('audit.properties', [
            'decision' => $validated['decision'],
            'target_user_id' => $forgetRequest->user_id,
            'anonymized_at' => optional($forgetRequest->anonymized_at)?->toISOString(),
        ]);

        return response()->json([
            'message' => $validated['decision'] === 'approve'
                ? 'Unutulma talebi onaylandi ve hesap anonimlestirildi.'
                : 'Unutulma talebi reddedildi.',
            'forget_request' => $forgetRequest->fresh(['user:id,name,surname,email,role,status,kvkk_forgotten', 'reviewer:id,name,surname']),
        ]);
    }

    private function anonymizeUserData(User $user): array
    {
        $hash = substr(hash('sha256', 'kvkk:'.$user->id.':'.now()->timestamp), 0, 16);
        $anonymousEmail = "forgotten-{$user->id}-{$hash}@anon.local";
        $anonymousName = 'Anonim Kullanici';

        $user->tokens()->delete();

        $user->forceFill([
            'name' => $anonymousName,
            'surname' => (string) $user->id,
            'email' => $anonymousEmail,
            'phone' => null,
            'address' => null,
            'tc_no' => null,
            'birth_date' => null,
            'university' => null,
            'department' => null,
            'class_year' => null,
            'hometown' => null,
            'profile_photo_path' => null,
            'password' => Hash::make(Str::random(64)),
            'status' => 'passive',
            'must_change_password' => false,
            'kvkk_forgotten' => true,
            'kvkk_forget_requested_at' => $user->kvkk_forget_requested_at ?? now(),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'remember_token' => null,
        ])->save();

        $user->profile()->delete();
        $user->staffProfile()->delete();

        return [
            'user_id' => $user->id,
            'email_replaced' => true,
            'profile_deleted' => true,
            'staff_profile_deleted' => true,
            'tokens_revoked' => true,
        ];
    }

    private function normalizeUserStatus(string $status): string
    {
        return match ($status) {
            'inactive' => 'passive',
            'banned' => 'blacklisted',
            default => $status,
        };
    }
}
