<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digital_bohca', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade'); // Null ise tüm projeye ait olabilir
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('cascade');
            
            $table->string('title');
            $table->text('description')->nullable();
            
            $table->string('file_path');
            $table->string('file_type')->nullable(); // pdf, docx, video vb.

            $table->boolean('visible_to_student')->default(true);
            
            $table->foreignId('uploaded_by')->constrained('users')->onDelete('cascade');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_bohca');
    }
};
