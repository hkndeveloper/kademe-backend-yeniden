<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            if (! Schema::hasColumn('programs', 'application_quota')) {
                $table->integer('application_quota')->nullable()->after('credit_deduction');
            }
        });

        Schema::table('application_forms', function (Blueprint $table) {
            if (! Schema::hasColumn('application_forms', 'program_id')) {
                $table->foreignId('program_id')->nullable()->after('period_id')->constrained('programs')->onDelete('set null');
            }
            if (! Schema::hasColumn('application_forms', 'auto_reject_rules')) {
                $table->jsonb('auto_reject_rules')->nullable()->after('consent_text');
            }
        });

        Schema::table('applications', function (Blueprint $table) {
            if (! Schema::hasColumn('applications', 'program_id')) {
                $table->foreignId('program_id')->nullable()->after('period_id')->constrained('programs')->onDelete('set null');
            }
            if (! Schema::hasColumn('applications', 'waitlist_order')) {
                $table->integer('waitlist_order')->nullable()->after('status');
            }
            if (! Schema::hasColumn('applications', 'waitlist_invited_at')) {
                $table->timestamp('waitlist_invited_at')->nullable()->after('waitlist_order');
            }
            if (! Schema::hasColumn('applications', 'waitlist_invitation_expires_at')) {
                $table->timestamp('waitlist_invitation_expires_at')->nullable()->after('waitlist_invited_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            foreach (['waitlist_invitation_expires_at', 'waitlist_invited_at', 'waitlist_order', 'program_id'] as $column) {
                if (Schema::hasColumn('applications', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('application_forms', function (Blueprint $table) {
            foreach (['auto_reject_rules', 'program_id'] as $column) {
                if (Schema::hasColumn('application_forms', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('programs', function (Blueprint $table) {
            if (Schema::hasColumn('programs', 'application_quota')) {
                $table->dropColumn('application_quota');
            }
        });
    }
};
