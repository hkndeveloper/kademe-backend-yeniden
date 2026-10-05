<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_invoice_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_transaction_id')->constrained()->cascadeOnDelete();
            $table->string('invoice_path');
            $table->foreignId('replaced_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('replaced_at');
            $table->timestamps();

            $table->index(['financial_transaction_id', 'replaced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_invoice_revisions');
    }
};
