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
            $table->timestamp('interview_passed_at')->nullable()->after('interview_at');
        });

        DB::table('applications')->where('status', 'interview_passed')
            ->update(['interview_passed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('interview_passed_at');
        });
    }
};
