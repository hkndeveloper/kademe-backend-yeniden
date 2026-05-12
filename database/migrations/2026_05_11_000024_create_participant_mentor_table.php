<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pergel projesi: Mentor-Katilimci eslestirme tablosu.
     * Bir mentore birden fazla katilimci; bir katilimciye birden fazla mentor atanabilir.
     */
    public function up(): void
    {
        // Tablo daha once (manuel veya yarim migration) olusturulduysa tekrar CREATE etme.
        // Aksi halde production'da "relation already exists" ile migrate kesilir.
        if (Schema::hasTable('participant_mentor')) {
            return;
        }

        Schema::create('participant_mentor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained('participants')->onDelete('cascade');
            $table->foreignId('mentor_id')->constrained('mentors')->onDelete('cascade');
            $table->foreignId('period_id')->nullable()->constrained('periods')->onDelete('set null');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->onDelete('set null');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['participant_id', 'mentor_id', 'period_id'], 'participant_mentor_period_unique');
            $table->index(['mentor_id', 'period_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participant_mentor');
    }
};
