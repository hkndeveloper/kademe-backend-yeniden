<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mentors', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null'); // İstersek sistemdeki user'la eşleyebiliriz
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade'); // Pergel Fellowship vb
            
            $table->string('name');
            $table->text('bio')->nullable();
            $table->string('expertise')->nullable();
            $table->string('photo_path')->nullable();

            $table->timestamps();
        });

        Schema::create('participant_mentor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained('participants')->onDelete('cascade');
            $table->foreignId('mentor_id')->constrained('mentors')->onDelete('cascade');
            $table->foreignId('period_id')->constrained('periods')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['participant_id', 'mentor_id', 'period_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participant_mentor');
        Schema::dropIfExists('mentors');
    }
};
