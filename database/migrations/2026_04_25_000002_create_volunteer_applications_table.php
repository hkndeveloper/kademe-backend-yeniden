<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('volunteer_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('volunteer_opportunity_id')->constrained('volunteer_opportunities')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('motivation_text');
            $table->text('notes')->nullable();
            $table->enum('status', ['pending', 'accepted', 'waitlisted', 'rejected'])->default('pending');
            $table->text('evaluation_note')->nullable();
            $table->timestamps();

            $table->unique(['volunteer_opportunity_id', 'user_id'], 'volunteer_applications_unique_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('volunteer_applications');
    }
};
