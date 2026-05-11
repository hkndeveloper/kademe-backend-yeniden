<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('application_open')->default(true);
            $table->boolean('requires_consent')->default(true);
            $table->text('consent_checkbox_label')->nullable();
            $table->text('warning_text')->nullable();
            $table->boolean('requires_coordinator_approval')->default(false);
            $table->json('outcomes')->nullable();
            $table->json('instructors')->nullable();
            $table->json('faq_items')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_modules');
    }
};
