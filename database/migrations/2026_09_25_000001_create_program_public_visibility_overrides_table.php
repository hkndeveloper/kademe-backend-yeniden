<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_public_visibility_overrides', function (Blueprint $table) {
            $table->foreignId('program_id')->primary()->constrained('programs')->cascadeOnDelete();
            $table->boolean('is_public');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_public_visibility_overrides');
    }
};
