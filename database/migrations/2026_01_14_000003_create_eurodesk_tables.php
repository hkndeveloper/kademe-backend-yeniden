<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eurodesk_projects', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade'); // Eurodesk genel projesine bağlı
            
            $table->string('title');
            
            // Ortak kuruluşlar array olarak (veya ayrı tablodan)
            $table->jsonb('partner_organizations')->nullable();
            
            $table->decimal('grant_amount', 12, 2)->nullable(); // Hibe tutarı
            
            $table->enum('grant_status', [
                'applied',
                'approved',
                'rejected',
                'completed'
            ])->default('applied');
            
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();

            $table->timestamps();
        });

        Schema::create('eurodesk_partnerships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('eurodesk_project_id')->constrained('eurodesk_projects')->onDelete('cascade');
            
            $table->string('organization_name');
            $table->string('country');
            $table->text('contact_info')->nullable();
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eurodesk_partnerships');
        Schema::dropIfExists('eurodesk_projects');
    }
};
