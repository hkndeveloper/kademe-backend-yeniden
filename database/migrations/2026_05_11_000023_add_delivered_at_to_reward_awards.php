<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reward_awards', function (Blueprint $table) {
            // Hediye fiziksel olarak teslim edildi mi?
            $table->timestamp('delivered_at')->nullable()->after('awarded_at');
            $table->foreignId('delivered_by')->nullable()->constrained('users')->onDelete('set null')->after('delivered_at');

            // Status: given (verildi) | delivered (teslim edildi) | cancelled (iptal)
            // 'given' zaten default - delivered_at doldurulunca 'delivered' yapilir
        });
    }

    public function down(): void
    {
        Schema::table('reward_awards', function (Blueprint $table) {
            $table->dropForeign(['delivered_by']);
            $table->dropColumn(['delivered_at', 'delivered_by']);
        });
    }
};
