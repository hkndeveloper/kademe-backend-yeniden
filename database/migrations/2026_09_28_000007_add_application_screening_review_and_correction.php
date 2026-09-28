<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->text('screening_review_reason')->nullable();
            $table->timestamp('auto_rejection_corrected_at')->nullable();
            $table->unsignedBigInteger('auto_rejection_corrected_by')->nullable();
            $table->string('auto_rejection_corrected_by_name')->nullable();
            $table->text('auto_rejection_correction_reason')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('applications')
            ->whereNotNull('screening_review_reason')
            ->orWhereNotNull('auto_rejection_corrected_at')
            ->orWhereNotNull('auto_rejection_corrected_by')
            ->orWhereNotNull('auto_rejection_correction_reason')
            ->exists()) {
            throw new RuntimeException('İnceleme ve otomatik ret düzeltme kayıtları silinmeden bu değişiklik geri alınamaz.');
        }

        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn([
                'screening_review_reason',
                'auto_rejection_corrected_at',
                'auto_rejection_corrected_by',
                'auto_rejection_corrected_by_name',
                'auto_rejection_correction_reason',
            ]);
        });
    }
};
