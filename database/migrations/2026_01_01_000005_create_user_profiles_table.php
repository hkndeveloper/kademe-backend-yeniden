<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');

            // Kişilik analiz testi (JSON)
            $table->jsonb('personality_test_data')->nullable();

            // Dijital CV verileri (kariyer.net benzeri form)
            $table->jsonb('digital_cv_data')->nullable();

            // Aylık motivasyon mesajı (admin/koordinatör tarafından yazılır)
            $table->text('motivation_message')->nullable();

            // Sosyal medya linkleri
            $table->string('linkedin_url')->nullable();
            $table->string('github_url')->nullable();
            $table->string('instagram_url')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
