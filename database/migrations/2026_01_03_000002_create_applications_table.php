<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->foreignId('period_id')->nullable()->constrained('periods')->onDelete('set null');
            $table->foreignId('application_form_id')->nullable()->constrained('application_forms')->onDelete('set null');

            // Dinamik form yanıtları
            $table->jsonb('form_data')->nullable();

            // Başvuru durumu
            $table->enum('status', [
                'pending',           // Beklemede
                'accepted',          // Kabul edildi
                'rejected',          // Reddedildi
                'waitlisted',        // Yedek listede
                'interview_planned', // Mülakat planlandı
                'interview_passed',  // Mülakat başarılı
                'interview_failed',  // Mülakat başarısız
            ])->default('pending');

            $table->text('rejection_reason')->nullable();
            $table->timestamp('interview_at')->nullable();

            // Otomatik red (kriter uyumsuzluk)
            $table->boolean('auto_rejected')->default(false);
            $table->string('auto_rejection_reason')->nullable();

            // Değerlendirme notu (koordinatör)
            $table->text('evaluation_note')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
