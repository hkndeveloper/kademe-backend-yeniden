<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waitlist_invitations', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('participant_id')->constrained('participants')->onDelete('cascade');
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->foreignId('period_id')->constrained('periods')->onDelete('cascade');
            
            $table->enum('status', [
                'pending',
                'accepted',
                'declined',
                'expired'
            ])->default('pending');
            
            $table->timestamp('invited_at');
            $table->timestamp('responded_at')->nullable();
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_invitations');
    }
};
