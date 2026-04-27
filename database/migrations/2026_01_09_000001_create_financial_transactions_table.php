<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_transactions', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('cascade');
            $table->foreignId('period_id')->nullable()->constrained('periods')->onDelete('cascade');
            
            $table->enum('type', ['expense', 'payment']);
            
            $table->enum('category', [
                'transport', 
                'food', 
                'print', 
                'education', 
                'other'
            ]);
            
            $table->string('payee_name'); // Faturayı kesen firma veya kişi
            $table->decimal('amount', 12, 2);
            
            $table->enum('status', [
                'pending',  // Onay bekliyor
                'approved', // Onaylandı
                'rejected', // Reddedildi
                'paid'      // Ödendi
            ])->default('pending');
            
            $table->string('invoice_path')->nullable(); // Fatura PDF/Görsel
            
            // İşlemi giren koordinatör
            $table->foreignId('submitted_by')->constrained('users')->onDelete('cascade');
            
            // İşlemi onaylayan üst admin
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_transactions');
    }
};
