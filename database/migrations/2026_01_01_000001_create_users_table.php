<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // Temel bilgiler
            $table->string('name');
            $table->string('surname');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            // İletişim
            $table->string('phone', 20)->nullable();
            $table->text('address')->nullable();

            // Kimlik (şifreli saklanacak)
            $table->text('tc_no')->nullable(); // encrypted
            $table->date('birth_date')->nullable();

            // Eğitim bilgileri
            $table->string('university')->nullable();
            $table->string('department')->nullable();
            $table->string('class_year', 10)->nullable(); // 1,2,3,4 veya mezun
            $table->string('hometown')->nullable();

            // Profil
            $table->string('profile_photo_path')->nullable();

            // Sistem rolü (spatie permission ana rol kolonu)
            $table->enum('role', [
                'super_admin',
                'coordinator',
                'staff',
                'student',
                'alumni',
                'visitor',
            ])->default('visitor');

            // Kullanıcı durumu
            $table->enum('status', [
                'active',
                'passive',
                'blacklisted',
                'alumni',
            ])->default('active');

            // Blacklist
            $table->integer('blacklist_count')->default(0);
            $table->timestamp('blacklisted_until')->nullable();

            // 2FA
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            // KVKK
            $table->timestamp('kvkk_consent_at')->nullable();
            $table->timestamp('kvkk_forget_requested_at')->nullable();
            $table->boolean('kvkk_forgotten')->default(false);

            // Doğrulamalar
            $table->boolean('yok_verified')->default(false);
            $table->boolean('tc_verified')->default(false);

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
