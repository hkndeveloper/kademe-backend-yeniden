<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->foreignId('target_membership_id')
                ->nullable()
                ->after('target_user_id')
                ->constrained('coordination_unit_memberships')
                ->nullOnDelete();
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->foreignId('membership_id')
                ->nullable()
                ->after('unit_id')
                ->constrained('coordination_unit_memberships')
                ->nullOnDelete();
            $table->string('position_snapshot', 30)->nullable()->after('membership_id');
            $table->string('reviewer_scope', 40)->nullable()->after('position_snapshot');
        });

        Schema::create('workflow_status_histories', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('coordination_units')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id'], 'workflow_status_subject_index');
            $table->index(['unit_id', 'created_at'], 'workflow_status_unit_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_status_histories');

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('membership_id');
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn(['position_snapshot', 'reviewer_scope']);
        });

        Schema::table('requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('target_membership_id');
        });
    }
};
