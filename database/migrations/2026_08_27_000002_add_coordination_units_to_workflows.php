<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->foreignId('target_unit_id')
                ->nullable()
                ->after('target_unit')
                ->constrained('coordination_units')
                ->nullOnDelete();
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->foreignId('assigned_unit_id')
                ->nullable()
                ->after('assigned_to')
                ->constrained('coordination_units')
                ->nullOnDelete();
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->foreignId('unit_id')
                ->nullable()
                ->after('user_id')
                ->constrained('coordination_units')
                ->nullOnDelete();
        });

        Schema::table('financial_transactions', function (Blueprint $table) {
            $table->foreignId('processing_unit_id')
                ->nullable()
                ->after('spending_unit')
                ->constrained('coordination_units')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('processing_unit_id');
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unit_id');
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_unit_id');
        });

        Schema::table('requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('target_unit_id');
        });
    }
};
