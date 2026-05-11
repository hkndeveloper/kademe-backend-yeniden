<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_module_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_module_id')->constrained('project_modules')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('participant_id')->nullable()->constrained('participants')->nullOnDelete();
            $table->string('status', 32)->default('pending');
            $table->timestamp('consented_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['project_module_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_module_enrollments');
    }
};
