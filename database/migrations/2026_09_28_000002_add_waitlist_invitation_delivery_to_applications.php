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
            // Null means a legacy invitation whose delivery was never tracked.
            $table->string('waitlist_invitation_delivery_status', 16)->nullable();
            $table->unsignedInteger('waitlist_invitation_response_seconds')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('applications')->where('status', 'waitlisted')
            ->whereNotNull('waitlist_invited_at')
            ->whereIn('waitlist_invitation_delivery_status', ['pending', 'failed', 'unknown'])
            ->exists()) {
            throw new RuntimeException('Teslim edilmemis yedek davetleri cozulmeden bu migration geri alinamaz.');
        }

        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn([
                'waitlist_invitation_delivery_status',
                'waitlist_invitation_response_seconds',
            ]);
        });
    }
};
