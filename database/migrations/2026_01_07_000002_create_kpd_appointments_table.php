<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpd_appointments', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('counselor_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('counselee_id')->constrained('users')->onDelete('cascade');
            
            $table->foreignId('room_id')->constrained('kpd_rooms')->onDelete('cascade');
            
            $table->timestamp('start_at');
            $table->timestamp('end_at');

            $table->enum('status', [
                'scheduled', 
                'completed', 
                'cancelled',
                'no_show' // Danışan gelmedi
            ])->default('scheduled');

            // Şifreli saklanacak notlar (KVKK)
            $table->text('notes')->nullable();

            $table->timestamps();
            
            // Aynı odada aynı saatte birden fazla randevu olamaz
            // Veritabanı seviyesinde karmaşık olduğundan Service katmanında kontrol edilecek.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpd_appointments');
    }
};
