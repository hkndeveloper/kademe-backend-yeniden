<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            // Herkese açık faaliyetler listesinde (/activities) göster/gizle
            $table->boolean('is_public')->default(true)->after('status');
            // Anasayfada öne çıkarılmaya aday (site-settings featured_activity_ids ile koordineli)
            $table->boolean('is_featured')->default(false)->after('is_public');
        });
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->dropColumn(['is_public', 'is_featured']);
        });
    }
};
