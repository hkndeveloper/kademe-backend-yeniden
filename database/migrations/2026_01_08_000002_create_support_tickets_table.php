<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            
            // Kayıtlı olmayan kullanıcı (ziyaretçi) iletişim formu doldurduysa user_id null olabilir
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            
            // Kayıtlı olmayanlar için manuel ad/eposta
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            
            $table->string('subject');
            $table->text('message');
            
            $table->string('category')->nullable();
            
            // Belli bir projeyle ilgiliyse o projenin koordinatörüne düşsün
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('set null');
            
            // Atanan yetkili (admin/koordinatör)
            $table->foreignId('assigned_to')->nullable()->constrained('users')->onDelete('set null');
            
            $table->enum('status', [
                'open',
                'in_progress',
                'resolved',
                'closed'
            ])->default('open');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
