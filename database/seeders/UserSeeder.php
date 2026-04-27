<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Üst Admin
        $admin = User::firstOrCreate([
            'email' => 'admin@kademe.org',
        ], [
            'name' => 'Üst',
            'surname' => 'Admin',
            'password' => Hash::make('12345678'),
            'role' => 'super_admin',
            'tc_no' => '11111111111',
            'phone' => '05555555555',
            'email_verified_at' => now(),
            'status' => 'active',
        ]);
        $admin->assignRole('super_admin');

        // 2. Örnek Koordinatör
        $coordinator = User::firstOrCreate([
            'email' => 'koordinator@kademe.org',
        ], [
            'name' => 'Proje',
            'surname' => 'Koordinatörü',
            'password' => Hash::make('12345678'),
            'role' => 'coordinator',
            'tc_no' => '22222222222',
            'phone' => '05555555556',
            'email_verified_at' => now(),
            'status' => 'active',
        ]);
        $coordinator->assignRole('coordinator');

        // 3. Örnek Öğrenci
        $student = User::firstOrCreate([
            'email' => 'ogrenci@kademe.org',
        ], [
            'name' => 'Örnek',
            'surname' => 'Öğrenci',
            'password' => Hash::make('12345678'),
            'role' => 'student',
            'tc_no' => '33333333333',
            'phone' => '05555555557',
            'email_verified_at' => now(),
            'university' => 'Ankara Üniversitesi',
            'department' => 'Bilgisayar Mühendisliği',
            'class_year' => '3',
            'status' => 'active',
        ]);
        $student->assignRole('student');
        
        // Profil oluştur
        $student->profile()->firstOrCreate([
            'motivation_message' => 'Hoş geldin! KADEME ile yeni döneme hazırsın.',
        ]);
    }
}
