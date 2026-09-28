<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('volunteer_opportunities', function (Blueprint $table) {
            $table->text('consent_text')->nullable();
        });

        Schema::table('volunteer_applications', function (Blueprint $table) {
            $table->text('consent_text_snapshot')->nullable();
            $table->timestamp('consent_accepted_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('volunteer_applications')->whereNotNull('consent_accepted_at')->exists()
            || DB::table('volunteer_opportunities')->whereNotNull('consent_text')->where('consent_text', '<>', '')->exists()) {
            throw new RuntimeException('Kaydedilmis gonulluluk basvurusu onaylari veya ozel kosullari kaybolmadan bu migration geri alinamaz.');
        }

        Schema::table('volunteer_applications', function (Blueprint $table) {
            $table->dropColumn(['consent_text_snapshot', 'consent_accepted_at']);
        });

        Schema::table('volunteer_opportunities', function (Blueprint $table) {
            $table->dropColumn('consent_text');
        });
    }
};
