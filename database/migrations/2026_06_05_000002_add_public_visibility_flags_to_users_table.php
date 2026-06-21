<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'public_profile_visible')) {
                $table->boolean('public_profile_visible')->default(false)->after('profile_photo_path');
            }

            if (! Schema::hasColumn('users', 'public_photo_visible')) {
                $table->boolean('public_photo_visible')->default(false)->after('public_profile_visible');
            }

            if (! Schema::hasColumn('users', 'public_alumni_visible')) {
                $table->boolean('public_alumni_visible')->default(false)->after('public_photo_visible');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['public_alumni_visible', 'public_photo_visible', 'public_profile_visible'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
