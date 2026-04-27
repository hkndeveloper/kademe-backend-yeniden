<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
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
            'user' => $user->load('profile', 'roles')
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

        // Token oluştur (Tüm eski tokenleri silebiliriz veya çoklu cihaza izin verebiliriz)
        // $user->tokens()->delete(); // İsteğe bağlı: Tek cihazdan giriş için
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Giriş başarılı.',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user->load('profile', 'roles')
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
        return response()->json([
            'user' => new \App\Http\Resources\UserResource($request->user()->load('profile', 'roles', 'staffProfile'))
        ]);
    }
}
