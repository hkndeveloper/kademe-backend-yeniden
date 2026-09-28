<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_logs', function (Blueprint $table) {
            // Existing deductions are unclassified and require review, not automatic sanctions.
            $table->boolean('absence_confirmed')->default(false);
        });
    }

    public function down(): void
    {
        if (DB::table('credit_logs')->where('absence_confirmed', true)->exists()) {
            throw new RuntimeException('Kesinleşmiş devamsızlık kayıtları kaybolmadan bu değişiklik geri alınamaz.');
        }

        Schema::table('credit_logs', function (Blueprint $table) {
            $table->dropColumn('absence_confirmed');
        });
    }
};
