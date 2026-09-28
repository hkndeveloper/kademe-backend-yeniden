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
            $table->text('auto_rejection_reason')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('applications')->whereRaw('LENGTH(auto_rejection_reason) > 255')->exists()) {
            throw new RuntimeException('Uzun otomatik ret gerekçeleri kısaltılmadan bu alan geri alınamaz.');
        }

        Schema::table('applications', function (Blueprint $table) {
            $table->string('auto_rejection_reason')->nullable()->change();
        });
    }
};
