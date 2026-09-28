<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->text('consent_text_snapshot')->nullable();
            $table->timestamp('consent_accepted_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('applications')->whereNotNull('consent_accepted_at')->exists()) {
            throw new RuntimeException('Kaydedilmis basvuru onaylari kaybolmadan bu migration geri alinamaz.');
        }

        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['consent_text_snapshot', 'consent_accepted_at']);
        });
    }
};
