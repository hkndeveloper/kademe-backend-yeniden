<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');

            $table->string('name'); // Örn: "2024 Bahar Dönemi"
            $table->date('start_date');
            $table->date('end_date');

            // Kredi sistemi
            $table->integer('credit_start_amount')->default(100); // Dönem başı kredi
            $table->integer('credit_threshold')->default(75);     // Uyarı eşiği

            $table->enum('status', ['active', 'passive', 'completed'])->default('active');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('periods');
    }
};
