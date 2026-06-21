<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_form_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'is_default', 'is_active']);
        });

        Schema::create('feedback_form_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feedback_form_template_id')->constrained()->cascadeOnDelete();
            $table->string('question_key');
            $table->string('label');
            $table->string('type')->default('rating');
            $table->unsignedTinyInteger('min_value')->nullable();
            $table->unsignedTinyInteger('max_value')->nullable();
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['feedback_form_template_id', 'question_key'], 'feedback_template_question_key_unique');
            $table->index(['feedback_form_template_id', 'sort_order']);
        });

        Schema::table('programs', function (Blueprint $table) {
            $table->foreignId('feedback_form_template_id')
                ->nullable()
                ->after('application_quota')
                ->constrained('feedback_form_templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('feedback_form_template_id');
        });

        Schema::dropIfExists('feedback_form_questions');
        Schema::dropIfExists('feedback_form_templates');
    }
};
