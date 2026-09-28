<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_module_enrollments', function (Blueprint $table) {
            $table->longText('consent_text_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('project_module_enrollments')->whereNotNull('consent_text_snapshot')->exists()) {
            throw new RuntimeException('Kaydedilmis modül onay metinleri kaybolmadan bu migration geri alinamaz.');
        }

        Schema::table('project_module_enrollments', function (Blueprint $table) {
            $table->dropColumn('consent_text_snapshot');
        });
    }
};
