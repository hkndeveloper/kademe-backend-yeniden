<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_notifications', function (Blueprint $table) {
            $table->id();

            // Alici kullanici
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');

            // Bildirim tipi: application, support, assignment, kpd, financial, program, system
            $table->string('type', 60)->default('system');

            // Bildirim baslik ve icerigi
            $table->string('title');
            $table->text('body')->nullable();

            // Varsa ilgili kayit URL'si veya route bilgisi
            $table->string('action_url')->nullable();

            // Okundu mu?
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();

            // Bildirimi tetikleyen islem / kayit
            $table->string('notifiable_type')->nullable();
            $table->unsignedBigInteger('notifiable_id')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'is_read']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_notifications');
    }
};
