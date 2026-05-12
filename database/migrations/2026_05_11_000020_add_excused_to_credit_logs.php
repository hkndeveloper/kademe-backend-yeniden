<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_logs', function (Blueprint $table) {
            // Mazeret kodu - admin bir devamsizligi mazaretli sayarsa true yapar
            $table->boolean('excused')->default(false)->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('credit_logs', function (Blueprint $table) {
            $table->dropColumn('excused');
        });
    }
};
