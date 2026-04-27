<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dinamik başvuru formu şablonu - her proje/dönem için özelleştirilebilir
        Schema::create('application_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->foreignId('period_id')->nullable()->constrained('periods')->onDelete('set null');

            // Dinamik soru tanımları (JSON)
            // Örn: [{"key":"why","label":"Neden katılmak istiyorsunuz?","type":"textarea","required":true}]
            $table->jsonb('fields');

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_forms');
    }
};
