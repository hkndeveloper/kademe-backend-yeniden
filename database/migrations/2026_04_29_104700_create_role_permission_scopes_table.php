<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permission_scopes', function (Blueprint $table) {
            $table->id();
            $table->string('role_name', 100);
            $table->string('permission_name', 150);
            $table->string('scope_type', 50);
            $table->json('scope_payload')->nullable();
            $table->timestamps();

            $table->unique(['role_name', 'permission_name']);
            $table->index('role_name');
            $table->index('permission_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permission_scopes');
    }
};
