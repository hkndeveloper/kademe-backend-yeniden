<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requests', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('requester_id')->constrained('users')->onDelete('cascade');
            
            $table->enum('type', [
                'vehicle',          // Araç
                'food',             // Yemek
                'accommodation',    // Konaklama
                'ticket',           // Bilet
                'official_doc',     // Resmi Evrak
                'media_design',     // Medya/Tasarım
                'other'
            ]);
            
            $table->string('target_unit')->nullable(); // Talep edilen birim (örn: Medya)
            
            // Eğer belirli bir kişiden isteniyorsa
            $table->foreignId('target_user_id')->nullable()->constrained('users')->onDelete('set null');
            
            $table->text('description');
            
            $table->enum('status', [
                'pending',      // Bekliyor
                'in_progress',  // İşleniyor
                'completed',    // Tamamlandı
                'rejected'      // Reddedildi
            ])->default('pending');
            
            // İşlem tamamlandığında yüklenen sonuç belgesi (örn: tasarım dosyası, uçak bileti pdf)
            $table->string('response_file_path')->nullable();
            
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('cascade');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requests');
    }
};
