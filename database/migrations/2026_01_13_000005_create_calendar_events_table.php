<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('cascade');
            $table->foreignId('program_id')->nullable()->constrained('programs')->onDelete('cascade');
            
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            
            $table->timestamp('start_at');
            $table->timestamp('end_at')->nullable();
            
            $table->string('google_event_id')->nullable(); // Google Calendar entegrasyonu için
            
            // Atanan personeller (json array of user_ids)
            $table->jsonb('assigned_users')->nullable();
            
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
