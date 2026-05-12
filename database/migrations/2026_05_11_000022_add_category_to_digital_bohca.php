<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bohca kategorisi: Diplomasi360 staj belgelerini ayirt etmek icin
     * general | internship_documents | assignment | certificate | kpd_report | other
     */
    public function up(): void
    {
        Schema::table('digital_bohca', function (Blueprint $table) {
            $table->string('category', 60)->default('general')->after('file_type');
            $table->index(['project_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::table('digital_bohca', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'category']);
            $table->dropColumn('category');
        });
    }
};
