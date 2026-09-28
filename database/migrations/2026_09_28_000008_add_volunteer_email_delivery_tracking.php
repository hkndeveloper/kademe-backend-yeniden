<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('volunteer_applications', function (Blueprint $table) {
            // Null is an old application whose email delivery was not recorded.
            $table->string('receipt_email_status', 16)->nullable();
            $table->string('decision_email_status', 16)->nullable();
            $table->uuid('decision_email_key')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('volunteer_applications')->whereNotNull('receipt_email_status')->exists()
            || DB::table('volunteer_applications')->whereNotNull('decision_email_status')->exists()
            || DB::table('volunteer_applications')->whereNotNull('decision_email_key')->exists()) {
            throw new RuntimeException('Gönüllülük e-posta gönderim kayıtları kaybolmadan bu değişiklik geri alınamaz.');
        }

        Schema::table('volunteer_applications', function (Blueprint $table) {
            $table->dropColumn(['receipt_email_status', 'decision_email_status', 'decision_email_key']);
        });
    }
};
