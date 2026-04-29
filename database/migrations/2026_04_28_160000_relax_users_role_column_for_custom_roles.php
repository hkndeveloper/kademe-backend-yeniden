<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        try {
            DB::statement("ALTER TABLE users ALTER COLUMN role TYPE VARCHAR(120) USING role::text");
        } catch (\Throwable) {
            // Farkli veritabani surumlerinde tip donusumu degisebilir.
        }
    }

    public function down(): void
    {
        // Enum'a geri donus veri kaybina neden olabilecegi icin bilincli olarak bos birakildi.
    }
};

