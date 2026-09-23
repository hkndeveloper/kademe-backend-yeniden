<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coordination_unit_membership_permission_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')->constrained('coordination_unit_memberships')->cascadeOnDelete();
            $table->string('permission_name');
            $table->string('effect', 10);
            $table->string('scope_type', 50)->nullable();
            $table->json('scope_payload')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['membership_id', 'status']);
            $table->unique(
                ['membership_id', 'permission_name', 'effect'],
                'coord_membership_permission_override_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coordination_unit_membership_permission_overrides');
    }
};
