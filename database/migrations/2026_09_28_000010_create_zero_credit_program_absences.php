<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_absences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('period_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('program_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('excused')->default(false);
            $table->string('excuse_reason', 1000)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['participant_id', 'program_id']);
            $table->index(['participant_id', 'excused']);
        });
    }

    public function down(): void
    {
        if (DB::table('program_absences')->exists()) {
            throw new RuntimeException('Kesinleşmiş devamsızlık kayıtları kaybolmadan bu değişiklik geri alınamaz.');
        }

        Schema::dropIfExists('program_absences');
    }
};
