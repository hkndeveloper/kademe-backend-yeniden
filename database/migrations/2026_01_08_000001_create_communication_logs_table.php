<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_logs', function (Blueprint $table) {
            $table->id();
            
            $table->enum('type', ['sms', 'email']);
            
            $table->foreignId('sender_id')->nullable()->constrained('users')->onDelete('set null');
            
            $table->integer('recipients_count')->default(0);
            
            // Kime gönderildiğini belirten filtre parametreleri (Örn: {"project_id": 1, "role": "student"})
            $table->jsonb('recipient_filter')->nullable();
            
            $table->string('subject')->nullable(); // Email ise konu
            $table->text('content'); // Mesaj içeriği
            $table->string('attachment_path')->nullable(); // Varsa ek dosya
            
            $table->enum('status', ['queued', 'sent', 'failed'])->default('queued');
            
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('cascade');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_logs');
    }
};
