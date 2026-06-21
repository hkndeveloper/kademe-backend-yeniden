<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedback_form_questions', function (Blueprint $table) {
            if (! Schema::hasColumn('feedback_form_questions', 'options')) {
                $table->json('options')->nullable()->after('type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('feedback_form_questions', function (Blueprint $table) {
            if (Schema::hasColumn('feedback_form_questions', 'options')) {
                $table->dropColumn('options');
            }
        });
    }
};
