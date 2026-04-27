<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_logs', function (Blueprint $table) {
            $table->id();
            
            // participant_id kullanıyoruz çünkü kredi dönem/proje bazlı
            $table->foreignId('participant_id')->constrained('participants')->onDelete('cascade');
            
            // Kolay raporlama için
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->foreignId('period_id')->constrained('periods')->onDelete('cascade');

            $table->integer('amount'); // eksi veya artı olabilir

            $table->enum('type', [
                'deduction',      // Yoklama eksi puanı
                'restore',        // Form doldurunca geri gelen puan
                'manual_adjust',  // Adminin manuel ekleyip/çıkardığı
                'reward',         // Ekstra ödül puanı
            ])->default('deduction');

            $table->string('reason'); // "Program katılım yok", "Form dolduruldu" vs.

            $table->foreignId('program_id')->nullable()->constrained('programs')->onDelete('set null');
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_logs');
    }
};
