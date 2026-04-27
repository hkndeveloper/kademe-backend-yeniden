<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->foreignId('period_id')->constrained('periods')->onDelete('cascade');

            // Katılım durumu
            $table->enum('status', [
                'active',
                'passive',
                'graduated',
                'failed',
                'waitlist',
            ])->default('active');

            // Mezuniyet durumu (dönem sonunda koordinatör işaretler)
            $table->enum('graduation_status', [
                'completed',    // Tamamladı (kısa süreli program)
                'graduated',    // Mezun
                'not_completed',// Tamamlayamadı
            ])->nullable();
            $table->text('graduation_note')->nullable(); // Tamamlayamadı gerekçesi

            // Kredi
            $table->integer('credit')->default(100);

            // Bekleme listesi sırası
            $table->integer('waitlist_order')->nullable();

            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('graduated_at')->nullable();

            $table->timestamps();

            // Bir kullanıcı aynı dönemde aynı projeye bir kez katılabilir
            $table->unique(['user_id', 'project_id', 'period_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participants');
    }
};
