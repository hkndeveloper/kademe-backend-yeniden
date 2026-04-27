<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_badges', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('badge_id')->constrained('badges')->onDelete('cascade');
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('cascade');
            
            $table->timestamp('awarded_at');
            $table->foreignId('awarded_by')->nullable()->constrained('users')->onDelete('set null'); // Manuel verildiyse
            
            $table->timestamps();

            // Aynı rozet aynı projede birden fazla kez verilemesin
            $table->unique(['user_id', 'badge_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_badges');
    }
};
