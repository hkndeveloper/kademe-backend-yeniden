<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('digital_bohca', function (Blueprint $table) {
            if (! Schema::hasColumn('digital_bohca', 'period_id')) {
                $table->foreignId('period_id')
                    ->nullable()
                    ->after('project_id')
                    ->constrained('periods')
                    ->nullOnDelete();
                $table->index(['project_id', 'period_id']);
            }
        });

        Schema::table('volunteer_opportunities', function (Blueprint $table) {
            if (! Schema::hasColumn('volunteer_opportunities', 'period_id')) {
                $table->foreignId('period_id')
                    ->nullable()
                    ->after('project_id')
                    ->constrained('periods')
                    ->nullOnDelete();
                $table->index(['project_id', 'period_id']);
            }
        });

        Schema::table('kpd_appointments', function (Blueprint $table) {
            if (! Schema::hasColumn('kpd_appointments', 'period_id')) {
                $table->foreignId('period_id')
                    ->nullable()
                    ->after('counselee_id')
                    ->constrained('periods')
                    ->nullOnDelete();
                $table->index('period_id');
            }
        });

        Schema::table('kpd_reports', function (Blueprint $table) {
            if (! Schema::hasColumn('kpd_reports', 'period_id')) {
                $table->foreignId('period_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('periods')
                    ->nullOnDelete();
                $table->index('period_id');
            }
        });

        Schema::table('project_modules', function (Blueprint $table) {
            if (! Schema::hasColumn('project_modules', 'period_id')) {
                $table->foreignId('period_id')
                    ->nullable()
                    ->after('project_id')
                    ->constrained('periods')
                    ->nullOnDelete();
                $table->index(['project_id', 'period_id']);
            }
        });

        Schema::table('eurodesk_projects', function (Blueprint $table) {
            if (! Schema::hasColumn('eurodesk_projects', 'period_id')) {
                $table->foreignId('period_id')
                    ->nullable()
                    ->after('project_id')
                    ->constrained('periods')
                    ->nullOnDelete();
                $table->index(['project_id', 'period_id']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('project_modules', function (Blueprint $table) {
            if (Schema::hasColumn('project_modules', 'period_id')) {
                $table->dropConstrainedForeignId('period_id');
            }
        });

        Schema::table('eurodesk_projects', function (Blueprint $table) {
            if (Schema::hasColumn('eurodesk_projects', 'period_id')) {
                $table->dropConstrainedForeignId('period_id');
            }
        });

        Schema::table('kpd_reports', function (Blueprint $table) {
            if (Schema::hasColumn('kpd_reports', 'period_id')) {
                $table->dropConstrainedForeignId('period_id');
            }
        });

        Schema::table('kpd_appointments', function (Blueprint $table) {
            if (Schema::hasColumn('kpd_appointments', 'period_id')) {
                $table->dropConstrainedForeignId('period_id');
            }
        });

        Schema::table('volunteer_opportunities', function (Blueprint $table) {
            if (Schema::hasColumn('volunteer_opportunities', 'period_id')) {
                $table->dropConstrainedForeignId('period_id');
            }
        });

        Schema::table('digital_bohca', function (Blueprint $table) {
            if (Schema::hasColumn('digital_bohca', 'period_id')) {
                $table->dropConstrainedForeignId('period_id');
            }
        });
    }
};
