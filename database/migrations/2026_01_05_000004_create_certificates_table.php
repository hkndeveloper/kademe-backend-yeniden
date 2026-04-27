<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->foreignId('period_id')->nullable()->constrained('periods')->onDelete('cascade');

            $table->enum('type', [
                'participation', // Katılım belgesi
                'graduation',    // Mezuniyet sertifikası
                'achievement',   // Başarı/Ödül belgesi
            ])->default('participation');

            // Doğrulama için benzersiz kod
            $table->string('verification_code')->unique();
            
            // Sertifika dosya yolu
            $table->string('certificate_path')->nullable();

            $table->timestamp('issued_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
