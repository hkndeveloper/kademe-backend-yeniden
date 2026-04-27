<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedbacks', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('program_id')->constrained('programs')->onDelete('cascade');
            
            // Anonimlik için (Kullanıcı verisi şifreli veya hashli olabilir, 
            // ya da sadece katıldığını bilip kimin ne verdiğini saklamayız)
            $table->string('anonymous_token')->unique(); 
            
            $table->jsonb('responses'); // Form cevapları
            
            $table->timestamp('submitted_at');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedbacks');
    }
};
