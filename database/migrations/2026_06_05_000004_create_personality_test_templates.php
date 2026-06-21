<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personality_test_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('personality_test_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personality_test_template_id')->constrained()->cascadeOnDelete();
            $table->string('question_key');
            $table->string('category');
            $table->string('text');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['personality_test_template_id', 'question_key'], 'personality_template_question_key_unique');
            $table->index(['personality_test_template_id', 'sort_order']);
        });

        Schema::create('personality_test_result_ranges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personality_test_template_id')->constrained()->cascadeOnDelete();
            $table->string('category');
            $table->string('summary');
            $table->timestamps();

            $table->unique(['personality_test_template_id', 'category'], 'personality_template_category_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personality_test_result_ranges');
        Schema::dropIfExists('personality_test_questions');
        Schema::dropIfExists('personality_test_templates');
    }
};
