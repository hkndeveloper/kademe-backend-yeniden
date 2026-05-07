<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_awards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->foreignId('participant_id')->constrained('participants')->onDelete('cascade');
            $table->foreignId('reward_tier_id')->nullable()->constrained('reward_tiers')->onDelete('set null');
            $table->foreignId('awarded_by')->nullable()->constrained('users')->onDelete('set null');
            $table->string('reward_name');
            $table->string('status')->default('given');
            $table->timestamp('awarded_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'participant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_awards');
    }
};
