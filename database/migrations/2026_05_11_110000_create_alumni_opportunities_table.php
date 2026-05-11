<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alumni_opportunities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->string('kind', 32)->default('other');
            $table->text('summary')->nullable();
            $table->text('body')->nullable();
            $table->string('link_url', 2048)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('target_audience')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alumni_opportunities');
    }
};
