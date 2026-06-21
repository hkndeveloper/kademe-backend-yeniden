<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('period_archives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('periods')->restrictOnDelete();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at');
            $table->unsignedInteger('archive_version')->default(1);
            $table->json('summary_json');
            $table->json('warnings_json')->nullable();
            $table->json('counts_json')->nullable();
            $table->string('integrity_hash', 64);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'period_id']);
            $table->index(['period_id', 'archive_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_archives');
    }
};
