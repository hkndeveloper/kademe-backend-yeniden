<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * @group Auth
 */
class AuthController extends Controller
{
    private function authenticatedUserPayload(User $user): array
    {
        $user->loadMissing('profile', 'roles', 'staffProfile');
        $authorization = app(\App\Services\PermissionResolver::class)->resolve($user);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'surname' => $user->surname,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'status' => $user->status,
            'address' => $user->address,
            'birth_date' => $user->birth_date?->format('Y-m-d'),
            'university' => $user->university,
            'department' => $user->department,
            'class_year' => $user->class_year,
            'hometown' => $user->hometown,
            'tc_verified' => (bool) $user->tc_verified,
            'yok_verified' => (bool) $user->yok_verified,
            'profile' => $user->profile,
            'roles' => $user->roles,
            'joined_at' => $user->created_at?->format('Y-m-d H:i:s'),
            'effective_permissions' => $authorization['effective_permissions'] ?? [],
            'role_permissions' => $authorization['role_permissions'] ?? [],
            'permission_scopes' => $authorization['scopes'] ?? [],
            'permission_overrides' => $authorization['direct_overrides'] ?? [],
            'authorization_context' => $authorization['contexts'] ?? [],
        ];
    }

    /**
     * role kolonu ile Spatie rol kaydini senkron tut.
     */
    private function ensureRoleSync(User $user): void
    {
        $allowedRoles = ['super_admin', 'coordinator', 'staff', 'student', 'alumni', 'visitor'];
        if (! in_array((string) $user->role, $allowedRoles, true)) {
            return;
        }

        if (! $user->hasRole((string) $user->role)) {
            $user->syncRoles([(string) $user->role]);
        }
    }

    private function logAuthActivity(Request $request, string $event, string $description, ?User $user = null, array $properties = []): void
    {
        try {
            $logger = activity()
                ->useLog('auth')
                ->event($event)
                ->withProperties(array_merge([
                    'http_method' => $request->method(),
                    'path' => $request->path(),
                    'ip_address' => $request->ip(),
                    'user_agent' => (string) $request->userAgent(),
                    'email' => $request->filled('email') ? Str::lower(trim((string) $request->input('email'))) : null,
                ], $properties));

            if ($user !== null) {
                $logger->causedBy($user)->performedOn($user);
            }

            $logger->log($description);
        } catch (\Throwable) {
            // Auth log hatasi ana akisi kesmemeli.
        }
    }

    /**
     * Register a new student user.
     *
     * @group Auth
     * @unauthenticated
     *
     * Creates an active student account, assigns the `student` role, creates an empty profile, and returns a Sanctum bearer token.
     *
     * @bodyParam name string required First name. Example: Hakan
     * @bodyParam surname string required Last name. Example: Kekec
     * @bodyParam email string required Unique email address. Example: hakan@example.com
     * @bodyParam password string required Minimum 8 characters, must be confirmed. Example: secret123
     * @bodyParam password_confirmation string required Password confirmation. Example: secret123
     * @bodyParam tc_no string required Turkish identity number, 11 characters. Example: 12345678901
     * @bodyParam phone string required Phone number. Example: 05551234567
     * @response 201 {"message":"Kayit basarili.","access_token":"1|plainTextToken","token_type":"Bearer","user":{"id":1,"name":"Hakan","surname":"Kekec","email":"hakan@example.com","role":"student","status":"active","effective_permissions":[],"permission_scopes":{}}}
     * @response 422 {"message":"The email has already been taken.","errors":{"email":["The email has already been taken."],"password":["The password field confirmation does not match."]}}
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'surname' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'tc_no' => 'required|string|size:11', // KADEME için önemli
            'phone' => 'required|string|max:20',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'surname' => $validated['surname'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'tc_no' => $validated['tc_no'],
            'phone' => $validated['phone'],
            'role' => 'student', // Varsayılan kayıt rolü
            'status' => 'active',
            'must_change_password' => false,
        ]);

        // Spatie rolü ata
        $user->assignRole('student');

        // Boş bir profil oluştur
        $user->profile()->create();

        // Token oluştur
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->logAuthActivity($request, 'registered', 'auth.register.success', $user, [
            'status_code' => 201,
            'role' => $user->role,
        ]);

        return response()->json([
            'message' => 'Kayıt başarılı.',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->authenticatedUserPayload($user)
        ], 201);
    }

    /**
     * Login and receive an access token.
     *
     * @group Auth
     * @unauthenticated
     *
     * Returns the bearer token and the resolved authorization payload. The returned `effective_permissions`, `permission_scopes`, and `authorization_context` fields help clients decide which panel/mobile features to show; backend authorization is still enforced on every protected endpoint.
     *
     * @bodyParam email string required User email. Example: hakan@example.com
     * @bodyParam password string required User password. Example: secret123
     * @response 200 {"message":"Giris basarili.","access_token":"1|plainTextToken","token_type":"Bearer","user":{"id":1,"name":"Hakan","surname":"Kekec","email":"hakan@example.com","role":"student","status":"active","effective_permissions":["participant.dashboard.view"],"permission_scopes":{"participant.dashboard.view":{"scope_type":"self","scope_payload":[]}},"authorization_context":{"manageable_project_ids":[]}}}
     * @response 403 {"message":"Hesabiniz aktif degil veya pasif duruma alinmis."}
     * @response 403 {"message":"Sifrenizi henuz belirlemediniz. E-postaniza gonderilen baglanti ile sifre olusturun; gelmediyse \"Sifremi unuttum\" ile yeni baglanti isteyin.","must_change_password":true,"error":"password_setup_required"}
     * @response 422 {"message":"The given data was invalid.","errors":{"email":["E-posta adresi veya sifre hatali."]}}
     */
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $email = Str::lower(trim($validated['email']));
        $user = User::where('email', $email)->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            $this->logAuthActivity($request, 'login_failed', 'auth.login.failed', $user, [
                'status_code' => 422,
                'reason' => 'invalid_credentials',
            ]);

            throw ValidationException::withMessages([
                'email' => ['E-posta adresi veya şifre hatalı.'],
            ]);
        }

        if ($user->status !== 'active') {
            $this->logAuthActivity($request, 'login_blocked', 'auth.login.blocked', $user, [
                'status_code' => 403,
                'reason' => 'inactive_user',
                'status' => $user->status,
            ]);

            return response()->json(['message' => 'Hesabınız aktif değil veya pasif duruma alınmış.'], 403);
        }

        if ($user->must_change_password) {
            $this->logAuthActivity($request, 'login_blocked', 'auth.login.blocked', $user, [
                'status_code' => 403,
                'reason' => 'password_setup_required',
            ]);

            return response()->json([
                'message' => 'Sifrenizi henuz belirlemediniz. E-postaniza gonderilen baglanti ile sifre olusturun; gelmediyse "Sifremi unuttum" ile yeni baglanti isteyin.',
                'must_change_password' => true,
                'error' => 'password_setup_required',
            ], 403);
        }

        $this->ensureRoleSync($user);

        // Token oluştur (Tüm eski tokenleri silebiliriz veya çoklu cihaza izin verebiliriz)
        // $user->tokens()->delete(); // İsteğe bağlı: Tek cihazdan giriş için
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->logAuthActivity($request, 'login', 'auth.login.success', $user, [
            'status_code' => 200,
            'role' => $user->role,
        ]);

        return response()->json([
            'message' => 'Giriş başarılı.',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->authenticatedUserPayload($user)
        ]);
    }

    /**
     * Logout the current token.
     *
     * @group Auth
     * @authenticated
     *
     * Deletes only the current Sanctum token.
     *
     * @response 200 {"message":"Cikis yapildi."}
     * @response 401 {"message":"Unauthenticated."}
     */
    public function logout(Request $request)
    {
        // Mevcut token'ı sil
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Çıkış yapıldı.'
        ]);
    }

    /**
     * Get the authenticated user.
     *
     * @group Auth
     * @authenticated
     *
     * Returns the current user with profile, roles, and staff profile relations where available.
     *
     * @response 200 {"user":{"id":1,"name":"Hakan","surname":"Kekec","email":"hakan@example.com","role":"student","status":"active","profile":{},"roles":[{"id":4,"name":"student"}]}}
     * @response 401 {"message":"Unauthenticated."}
     */
    public function me(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $this->ensureRoleSync($user);

        return response()->json([
            'user' => new \App\Http\Resources\UserResource($user->load('profile', 'roles', 'staffProfile'))
        ]);
    }

    /**
     * Send a password reset link.
     *
     * @group Auth
     * @unauthenticated
     *
     * Sends a Laravel password reset email when the address is known. The endpoint responds generically for the public flow.
     *
     * @bodyParam email string required User email. Example: hakan@example.com
     * @response 200 {"message":"E-posta adresinize sifre belirleme baglantisi gonderdik. Gelmiyorsa spam klasorunu kontrol edin."}
     * @response 422 {"message":"The email field must be a valid email address.","errors":{"email":["The email field must be a valid email address."]}}
     */
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $email = Str::lower(trim((string) $request->input('email')));
        Password::sendResetLink(['email' => $email]);

        $this->logAuthActivity($request, 'password_reset_requested', 'auth.password_reset.requested', null, [
            'status_code' => 200,
        ]);

        return response()->json([
            'message' => 'E-posta adresinize sifre belirleme baglantisi gonderdik. Gelmiyorsa spam klasorunu kontrol edin.',
        ]);
    }

    /**
     * Reset password with email token.
     *
     * @group Auth
     * @unauthenticated
     *
     * Resets the password, clears `must_change_password`, and invalidates existing tokens.
     *
     * @bodyParam token string required Password reset token.
     * @bodyParam email string required User email. Example: hakan@example.com
     * @bodyParam password string required Minimum 8 characters, must be confirmed. Example: newsecret123
     * @bodyParam password_confirmation string required Password confirmation. Example: newsecret123
     * @response 200 {"message":"Sifreniz guncellendi. Giris yapabilirsiniz."}
     * @response 422 {"message":"Baglanti gecersiz veya suresi dolmus. Yeni baglanti icin sifremi unuttum kullanin."}
     * @response 422 {"message":"The password field confirmation does not match.","errors":{"password":["The password field confirmation does not match."]}}
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $payload = $request->only('email', 'password', 'password_confirmation', 'token');
        $payload['email'] = Str::lower(trim((string) ($payload['email'] ?? '')));

        $status = Password::reset(
            $payload,
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'must_change_password' => false,
                ])->save();
                $user->tokens()->delete();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            $user = User::where('email', $payload['email'])->first();
            $this->logAuthActivity($request, 'password_reset', 'auth.password_reset.success', $user, [
                'status_code' => 200,
            ]);

            return response()->json([
                'message' => 'Sifreniz guncellendi. Giris yapabilirsiniz.',
            ]);
        }

        $messages = [
            Password::INVALID_TOKEN => 'Baglanti gecersiz veya suresi dolmus. Yeni baglanti icin sifremi unuttum kullanin.',
            Password::INVALID_USER => 'Bu e-posta ile kayit bulunamadi.',
            Password::RESET_THROTTLED => 'Cok fazla deneme. Lutfen bir sure sonra tekrar deneyin.',
        ];

        $this->logAuthActivity($request, 'password_reset_failed', 'auth.password_reset.failed', null, [
            'status_code' => 422,
            'reason' => $status,
        ]);

        return response()->json([
            'message' => $messages[$status] ?? 'Sifre guncellenemedi.',
        ], 422);
    }
}
