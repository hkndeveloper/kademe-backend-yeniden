<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            if (! Schema::hasColumn('calendar_events', 'event_type')) {
                $table->string('event_type', 32)->default('program')->index();
            }
            if (! Schema::hasColumn('calendar_events', 'status')) {
                $table->string('status', 32)->default('scheduled')->index();
            }
            if (! Schema::hasColumn('calendar_events', 'metadata')) {
                $table->json('metadata')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            if (Schema::hasColumn('calendar_events', 'metadata')) {
                $table->dropColumn('metadata');
            }
            if (Schema::hasColumn('calendar_events', 'status')) {
                $table->dropColumn('status');
            }
            if (Schema::hasColumn('calendar_events', 'event_type')) {
                $table->dropColumn('event_type');
            }
        });
    }
};
