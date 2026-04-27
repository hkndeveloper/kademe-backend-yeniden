<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internships', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('participant_id')->constrained('participants')->onDelete('cascade');
            
            $table->string('company_name');
            $table->string('position');
            
            $table->date('start_date');
            $table->date('end_date')->nullable();
            
            $table->text('description')->nullable();
            
            $table->string('document_path')->nullable(); // Staj belgesi/onay yazısı

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internships');
    }
};
