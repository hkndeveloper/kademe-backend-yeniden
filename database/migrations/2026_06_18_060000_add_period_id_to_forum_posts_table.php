<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forum_posts', function (Blueprint $table) {
            if (! Schema::hasColumn('forum_posts', 'period_id')) {
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
        Schema::table('forum_posts', function (Blueprint $table) {
            if (Schema::hasColumn('forum_posts', 'period_id')) {
                $table->dropConstrainedForeignId('period_id');
            }
        });
    }
};
