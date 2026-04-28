<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropUnique('system_settings_key_unique');
            $table->unique(['group', 'key'], 'system_settings_group_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropUnique('system_settings_group_key_unique');
            $table->unique('key', 'system_settings_key_unique');
        });
    }
};
