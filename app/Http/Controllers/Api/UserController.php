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

/**
 * @group Users
 */
class UserController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {}

    /**
     * Get panel user creation options.
     *
     * Requires permission: `users.create` with global `all` scope. Returns the Spatie roles that can be used when creating student/alumni users from the panel. Exposed under both `/api/admin/users/create-options` and `/api/panel/users/create-options`.
     *
     * @group Users
     * @response 200 {"roles":[{"name":"student","label":"Ogrenci"},{"name":"alumni","label":"Mezun"}]}
     * @response 403 {"message":"Kullanici olusturmak icin tum sistem kapsami gerekir."}
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
     * Create a student or alumni user from the panel.
     *
     * Requires permission: `users.create` with global `all` scope. Creates an active student/alumni account, syncs the selected Spatie role, creates an empty participant profile, marks the password as pending change and sends the password reset/setup email.
     *
     * @group Users
     * @bodyParam name string required User first name. Example: Ayse
     * @bodyParam surname string required User surname. Example: Yilmaz
     * @bodyParam email string required Unique email address. Example: ayse@example.com
     * @bodyParam phone string Optional phone number. Max 20 characters. Example: 05550000000
     * @bodyParam tc_no string Optional Turkish identity number, exactly 11 characters. Example: 12345678901
     * @bodyParam role string required Role name. Allowed values: student, alumni. Example: student
     * @response 201 {"message":"Kullanici olusturuldu. Sifre belirleme baglantisi e-posta ile gonderildi.","user":{"id":55,"email":"ayse@example.com","role":"student","status":"active"},"reset_email_status":"passwords.sent"}
     * @response 403 {"message":"Kullanici olusturmak icin tum sistem kapsami gerekir."}
     * @response 422 {"message":"The email has already been taken."}
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

    /**
     * List student and alumni users.
     *
     * Requires permission: `users.view`. The query is filtered by `PermissionResolver::applyUserScope`, so `all`, `own_unit`, `self` or other supported user scopes determine which student/alumni rows are visible. Soft-deleted users are excluded.
     *
     * @group Users
     * @queryParam role string Optional role filter. Allowed values: student, alumni. Example: student
     * @queryParam status string Optional status filter. `inactive` is normalized to `passive`; `banned` is normalized to `blacklisted`. Example: active
     * @queryParam search string Optional name, surname, email or phone search. Example: ayse
     * @queryParam university string Optional university search. Example: Istanbul
     * @response 200 {"users":{"data":[{"id":55,"name":"Ayse","surname":"Yilmaz","email":"ayse@example.com","role":"student","status":"active"}],"current_page":1}}
     * @response 403 {"message":"This action is unauthorized."}
     */

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
     * Get panel user details.
     *
     * Requires permission: `users.view` and `PermissionResolver::canAccessUser` for the target student/alumni user. Returns profile, participations, applications, attendance, certificates, coordinated/assigned project metadata, documents, credit score and absence count.
     *
     * @group Users
     * @urlParam id integer required Student/alumni user ID. Example: 55
     * @response 200 {"user":{"id":55,"name":"Ayse","surname":"Yilmaz","role":"student","participations":[{"id":7,"project":{"id":1,"name":"KADEME"}}]},"documents":[],"credit_score":12,"absent_count":1}
     * @response 403 {"message":"Bu kullaniciyi goruntuleme yetkiniz bulunmuyor."}
     * @response 404 {"message":"No query results for model [App\\Models\\User] 55"}
     */

    public function showUser(int $id)
    {
        $user = User::with([
            'profile',
            'staffProfile',
            'participations.project:id,name',
            'applications.form.project:id,name',
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
     * Update a student or alumni user.
     *
     * Requires `users.update` for status-only changes and `users.assign_role` when `role` is present. Role assignment additionally requires global `all` scope. Target access is checked with `PermissionResolver::canAccessUser`; status aliases are normalized before saving.
     *
     * @group Users
     * @urlParam id integer required Student/alumni user ID. Example: 55
     * @bodyParam role string Optional new role. Allowed values: student, alumni. Requires `users.assign_role` with global scope. Example: alumni
     * @bodyParam status string Optional status. Allowed values: active, passive, blacklisted, alumni, inactive, banned. Example: passive
     * @response 200 {"message":"Kullanici guncellendi.","user":{"id":55,"role":"alumni","status":"passive"}}
     * @response 403 {"message":"Rol atama islemi icin tum sistem kapsami gerekir."}
     * @response 422 {"message":"The selected status is invalid."}
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
     * Sync coordinator managed projects.
     *
     * Requires permission: `users.assign_role` with global `all` scope. The target user must be a coordinator and accessible to the caller; project IDs are synced through the coordinator-project relation.
     *
     * @group Users
     * @urlParam id integer required Coordinator user ID. Example: 8
     * @bodyParam project_ids array required Project IDs to assign. Send an empty array to clear assignments. Example: [1,2]
     * @bodyParam project_ids.* integer Project ID. Example: 1
     * @response 200 {"message":"Koordinator projeleri guncellendi.","coordinated_projects":[{"id":1,"name":"Diplomasi360"}]}
     * @response 403 {"message":"Koordinator proje atamasi icin tum sistem kapsami gerekir."}
     * @response 422 {"message":"Yalnizca koordinator hesaplarina proje atanabilir."}
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
     * Export student and alumni users.
     *
     * Requires permission: `users.export`. The export query is filtered with the same user scope behavior as the list endpoint and supports CSV, Excel/XLSX, PDF and Word/DOCX through the shared admin export responder.
     *
     * @group Users
     * @queryParam role string Optional role filter. Example: student
     * @queryParam status string Optional status filter. Example: active
     * @queryParam search string Optional name, surname, email or phone search. Example: ayse
     * @queryParam format string Optional export format. Allowed values: xlsx, excel, pdf, docx, word, csv. Defaults to csv. Example: xlsx
     * @response 200 {"download":"User export file stream"}
     * @response 403 {"message":"This action is unauthorized."}
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
     * Get the authenticated user profile.
     *
     * Requires a valid Sanctum token, completed password setup, and KVKK consent. Returns the current user with the related profile record.
     *
     * @group Users
     * @authenticated
     *
     * @response 200 {"user":{"id":1,"name":"Hakan","surname":"Kekec","email":"hakan@example.com","phone":"05551234567","role":"student","status":"active","profile":{"motivation_message":"Kariyer hedefim sosyal etki uretmek."}}}
     * @response 401 {"message":"Unauthenticated."}
     * @response 403 {"message":"KVKK onayi gereklidir."}
     */
    public function getProfile(Request $request)
    {
        return response()->json([
            'user' => $request->user()->load('profile'),
        ]);
    }

    /**
     * Update the authenticated user profile.
     *
     * Requires KVKK consent. Updates editable account fields and the related profile/social fields for the current student or alumni user.
     *
     * @group Users
     * @authenticated
     *
     * @bodyParam phone string Optional phone number. Example: 05551234567
     * @bodyParam address string Optional address. Example: Istanbul
     * @bodyParam birth_date date Optional birth date. Example: 2000-01-01
     * @bodyParam university string Optional university. Example: Istanbul Universitesi
     * @bodyParam department string Optional department. Example: Bilgisayar Muhendisligi
     * @bodyParam class_year string Optional class year. Example: 3
     * @bodyParam hometown string Optional hometown. Example: Istanbul
     * @bodyParam motivation_message string Optional profile summary/motivation text. Example: Sosyal etki odakli projelerde yer almak istiyorum.
     * @bodyParam linkedin_url string Optional LinkedIn URL. Example: https://linkedin.com/in/hakan
     * @bodyParam github_url string Optional GitHub URL. Example: https://github.com/hakan
     * @bodyParam instagram_url string Optional Instagram URL. Example: https://instagram.com/hakan
     * @response 200 {"message":"Profil basariyla guncellendi.","user":{"id":1,"phone":"05551234567","profile":{"linkedin_url":"https://linkedin.com/in/hakan"}}}
     * @response 401 {"message":"Unauthenticated."}
     * @response 422 {"message":"The birth date field must be a valid date.","errors":{"birth_date":["The birth date field must be a valid date."]}}
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
     * Change the authenticated user password.
     *
     * Requires KVKK consent and current password confirmation. Also clears `must_change_password` when successful.
     *
     * @group Users
     * @authenticated
     *
     * @bodyParam current_password string required Current password. Example: secret123
     * @bodyParam password string required New password, minimum 8 characters, must be confirmed. Example: newsecret123
     * @bodyParam password_confirmation string required New password confirmation. Example: newsecret123
     * @response 200 {"message":"Sifreniz basariyla degistirildi."}
     * @response 401 {"message":"Unauthenticated."}
     * @response 422 {"message":"The given data was invalid.","errors":{"current_password":["Mevcut sifreniz hatali."]}}
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
     * Confirm KVKK consent for the authenticated user.
     *
     * This endpoint is intentionally available before the KVKK middleware gate. After consent, protected participant endpoints can be used.
     *
     * @group Users
     * @authenticated
     *
     * @response 200 {"message":"KVKK aydinlatma metni basariyla onaylandi."}
     * @response 400 {"message":"KVKK onayi zaten verilmis."}
     * @response 401 {"message":"Unauthenticated."}
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

    /**
     * Request KVKK right-to-be-forgotten review.
     *
     * Creates a pending forget/anonymization request for the current user. The actual anonymization is reviewed from the authorized panel flow.
     *
     * @group Users
     * @authenticated
     *
     * @bodyParam request_note string Optional explanation for the reviewer. Example: Hesabimin silinmesini talep ediyorum.
     * @response 201 {"message":"Unutulma hakki talebiniz alindi. Inceleme sonrasinda sonuc bilgilendirilecektir.","forget_request":{"id":1,"status":"pending","request_note":"Hesabimin silinmesini talep ediyorum."}}
     * @response 401 {"message":"Unauthenticated."}
     * @response 422 {"message":"Bu hesap icin bekleyen bir unutulma talebi zaten mevcut."}
     */
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

    /**
     * List KVKK forget requests for the panel.
     *
     * Requires permission: `users.view` with global `all` scope. Returns pending/completed/rejected right-to-be-forgotten requests with requester and reviewer metadata.
     *
     * @group Users
     * @queryParam status string Optional request status filter. Example: pending
     * @response 200 {"forget_requests":{"data":[{"id":3,"status":"pending","request_note":"Hesabimin silinmesini talep ediyorum.","user":{"id":55,"email":"ayse@example.com"}}],"current_page":1}}
     * @response 403 {"message":"Unutulma taleplerini listelemek icin tum sistem kapsami gerekir."}
     */

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

    /**
     * Resolve a KVKK forget request.
     *
     * Requires permission: `users.update` with global `all` scope. Approving anonymizes personal account fields, deletes profile/staff profile records, revokes tokens and stores an anonymization summary. Rejecting stores reviewer metadata without anonymizing. The operation is audited as `users.kvkk_forget.resolved`.
     *
     * @group Users
     * @urlParam id integer required KVKK forget request ID. Example: 3
     * @bodyParam decision string required Decision. Allowed values: approve, reject. Example: approve
     * @bodyParam reviewer_note string Optional reviewer note. Max 2000 characters. Example: Talep uygun bulundu.
     * @response 200 {"message":"Unutulma talebi onaylandi ve hesap anonimlestirildi.","forget_request":{"id":3,"status":"completed","anonymized_at":"2026-06-30T12:00:00.000000Z"}}
     * @response 403 {"message":"Unutulma talebi islemek icin tum sistem kapsami gerekir."}
     * @response 422 {"message":"Bu talep zaten sonuclandirilmis."}
     */

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
