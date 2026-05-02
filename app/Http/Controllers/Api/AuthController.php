<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

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
            'university' => $user->university,
            'department' => $user->department,
            'class_year' => $user->class_year,
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
    /**
     * Kullanıcı Kayıt (Register)
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
        ]);

        // Spatie rolü ata
        $user->assignRole('student');

        // Boş bir profil oluştur
        $user->profile()->create();

        // Token oluştur
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Kayıt başarılı.',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->authenticatedUserPayload($user)
        ], 201);
    }

    /**
     * Kullanıcı Giriş (Login)
     */
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['E-posta adresi veya şifre hatalı.'],
            ]);
        }

        if ($user->status !== 'active') {
            return response()->json(['message' => 'Hesabınız aktif değil veya pasif duruma alınmış.'], 403);
        }

        $this->ensureRoleSync($user);

        // Token oluştur (Tüm eski tokenleri silebiliriz veya çoklu cihaza izin verebiliriz)
        // $user->tokens()->delete(); // İsteğe bağlı: Tek cihazdan giriş için
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Giriş başarılı.',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->authenticatedUserPayload($user)
        ]);
    }

    /**
     * Oturumu Kapat (Logout)
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
     * Aktif Kullanıcı Bilgileri (Me)
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
}
