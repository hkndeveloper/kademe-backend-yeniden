<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_profiles', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            
            $table->enum('title', [
                'researcher', 
                'specialist', 
                'coordinator', 
                'manager', 
                'other'
            ]);
            
            $table->string('unit'); // Bağlı olduğu birim
            
            $table->date('start_date')->nullable();
            $table->string('contract_type')->nullable();
            
            // Kişi belgeleri (cv, diploma vs.) json formatında yolları
            $table->jsonb('personal_documents')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_profiles');
    }
};
