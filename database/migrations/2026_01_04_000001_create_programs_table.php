<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->foreignId('period_id')->nullable()->constrained('periods')->onDelete('set null');

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location')->nullable();

            // QR konum doğrulama
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->integer('radius_meters')->default(100); // GPS sapma payı

            // Konuk bilgisi (detaylı - isim, kurum, bio vb.)
            $table->jsonb('guest_info')->nullable();

            // Program zamanı
            $table->timestamp('start_at');
            $table->timestamp('end_at');

            // Kredi sistemi
            $table->integer('credit_deduction')->default(10); // Katılmayanlara düşülecek kredi

            // QR Kod
            $table->string('qr_token')->nullable();
            $table->timestamp('qr_expires_at')->nullable();
            $table->integer('qr_rotation_seconds')->default(30);

            $table->enum('status', [
                'scheduled',  // Planlandı
                'active',     // Aktif (yoklama açık)
                'completed',  // Tamamlandı
                'cancelled',  // İptal
            ])->default('scheduled');

            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programs');
    }
};
