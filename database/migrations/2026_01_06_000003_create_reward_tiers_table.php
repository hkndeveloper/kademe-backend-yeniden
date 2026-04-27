<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_tiers', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('cascade');
            
            $table->string('name'); // Örn: Kademe+ Hediye Kademesi 1
            $table->text('description')->nullable();
            
            // Kazanım Şartları
            $table->integer('min_badges')->default(0);
            $table->integer('min_credits')->default(0);
            
            // Verilecek Hediye/Ödül
            $table->string('reward_description');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_tiers');
    }
};
