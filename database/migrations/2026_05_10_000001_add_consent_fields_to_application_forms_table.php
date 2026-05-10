<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_forms', function (Blueprint $table) {
            $table->boolean('require_consent')->default(false)->after('fields');
            $table->text('consent_text')->nullable()->after('require_consent');
        });
    }

    public function down(): void
    {
        Schema::table('application_forms', function (Blueprint $table) {
            $table->dropColumn(['require_consent', 'consent_text']);
        });
    }
};
