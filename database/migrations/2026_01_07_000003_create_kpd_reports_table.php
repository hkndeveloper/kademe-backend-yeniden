<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpd_reports', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade'); // Danışan
            $table->foreignId('counselor_id')->constrained('users')->onDelete('cascade'); // Yükleyen danışman
            
            $table->string('title');
            
            // Dosya yolu şifreli saklanacak veya güvenli bir dizinde olacak
            $table->string('file_path');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpd_reports');
    }
};
