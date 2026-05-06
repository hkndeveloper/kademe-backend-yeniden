<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AdminExportResponder;
use App\Services\PermissionResolver;
use Illuminate\Http\Request;
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
    ) {
    }

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
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => [
                'name' => $role->name,
                'label' => config('permission_catalog.role_labels.' . $role->name) ?? Str::headline($role->name),
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
            'role' => 'required|string|exists:roles,name',
        ]);

        $roleName = $validated['role'];
        if ($roleName === 'super_admin') {
            abort_unless(
                $request->user()->hasRole('super_admin'),
                403,
                'Ust admin hesabi yalnizca ust admin tarafindan olusturulabilir.'
            );
        }

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

        if (in_array($roleName, ['coordinator', 'staff'], true)) {
            $user->staffProfile()->firstOrCreate(
                ['user_id' => $user->id],
                [
                    'title' => $roleName === 'coordinator' ? 'coordinator' : 'specialist',
                    'unit' => 'Genel',
                    'contract_type' => 'full_time',
                    'start_date' => now()->toDateString(),
                ]
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

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
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
            $query->where('university', 'like', '%' . $request->university . '%');
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
            'roles:id,name',
        ])->findOrFail($id);

        abort_unless(
            $this->permissionResolver->canAccessUser(request()->user(), 'users.view', $user),
            403,
            'Bu kullaniciyi goruntuleme yetkiniz bulunmuyor.'
        );

        $documents = $user->staffProfile?->personal_documents ?? [];
        $creditScore = \App\Models\CreditLog::where('user_id', $user->id)->sum('amount');
        $absentCount = \App\Models\Attendance::where('user_id', $user->id)->where('is_valid', false)->count();

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
            'role' => 'sometimes|string|exists:roles,name',
            'status' => 'sometimes|in:active,inactive,banned',
        ]);

        $columnUpdates = collect($validated)
            ->except(['role'])
            ->toArray();
        if ($columnUpdates !== []) {
            $user->update($columnUpdates);
        }

        if (!empty($validated['role'])) {
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
     * GET /admin/users/export
     * Kullanici listesini CSV olarak disa aktar.
     */
    public function exportUsers(Request $request)
    {
        $this->abortUnlessAllowed($request, 'users.export');
        $query = User::with('profile');
        $this->permissionResolver->applyUserScope($query, $request->user(), 'users.export');

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
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
            'kullanici_listesi_' . now()->format('Ymd_His'),
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
            'phone' => 'sometimes|string|max:20',
            'address' => 'sometimes|string',
            'birth_date' => 'sometimes|date',
            'university' => 'sometimes|string|max:255',
            'department' => 'sometimes|string|max:255',
            'class_year' => 'sometimes|string|max:50',
            'hometown' => 'sometimes|string|max:100',
        ]);

        $validatedProfile = $request->validate([
            'motivation_message' => 'sometimes|string',
            'linkedin_url' => 'sometimes|url|nullable',
            'github_url' => 'sometimes|url|nullable',
            'instagram_url' => 'sometimes|url|nullable',
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

        if (!Hash::check($validated['current_password'], $user->password)) {
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
}
