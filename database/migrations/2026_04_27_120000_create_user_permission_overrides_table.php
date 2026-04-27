<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_permission_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('permission_name');
            $table->string('effect', 10)->default('allow');
            $table->string('scope_type', 50)->nullable();
            $table->json('scope_payload')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'permission_name']);
            $table->unique(['user_id', 'permission_name', 'effect'], 'user_permission_overrides_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permission_overrides');
    }
};
