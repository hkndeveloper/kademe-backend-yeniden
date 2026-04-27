<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('programs')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');

            // Yoklama yöntemi
            $table->enum('method', ['qr', 'manual'])->default('qr');

            // QR ise cihaz konumu
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            
            // Konum vs geçerli mi?
            $table->boolean('is_valid')->default(true);

            // Manuel ise not ve giren kişi
            $table->text('manual_note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->onDelete('set null');

            $table->timestamps();

            // Bir kişi bir programa bir kez yoklama verebilir
            $table->unique(['program_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
