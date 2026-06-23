<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('participant_mentor')) {
            return;
        }

        Schema::table('participant_mentor', function (Blueprint $table) {
            if (! Schema::hasColumn('participant_mentor', 'period_id')) {
                $table->foreignId('period_id')->nullable()->constrained('periods')->nullOnDelete();
            }

            if (! Schema::hasColumn('participant_mentor', 'assigned_by')) {
                $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('participant_mentor', 'note')) {
                $table->text('note')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('participant_mentor')) {
            return;
        }

        Schema::table('participant_mentor', function (Blueprint $table) {
            if (Schema::hasColumn('participant_mentor', 'assigned_by')) {
                $table->dropConstrainedForeignId('assigned_by');
            }

            if (Schema::hasColumn('participant_mentor', 'note')) {
                $table->dropColumn('note');
            }
        });
    }
};