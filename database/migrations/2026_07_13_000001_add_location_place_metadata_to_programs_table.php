<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->string('location_place_name')->nullable();
            $table->string('location_place_address', 500)->nullable();
            $table->string('location_place_id')->nullable();
            $table->string('location_place_provider', 50)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->dropColumn([
                'location_place_name',
                'location_place_address',
                'location_place_id',
                'location_place_provider',
            ]);
        });
    }
};