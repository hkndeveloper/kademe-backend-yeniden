<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('slug')->unique();

            // Proje tipi
            $table->enum('type', [
                'diplomasi360',
                'kademe_plus',
                'eurodesk',
                'pergel_fellowship',
                'kpd',
                'zirve_kademe',
                'other',
            ]);

            $table->text('description')->nullable();
            $table->string('short_description', 500)->nullable();

            // Görseller
            $table->string('cover_image_path')->nullable();
            $table->jsonb('gallery_paths')->nullable(); // birden fazla görsel

            // Durum
            $table->enum('status', ['active', 'passive', 'archived'])->default('active');

            // Başvuru ayarları
            $table->boolean('application_open')->default(false);
            $table->timestamp('application_start_at')->nullable();
            $table->timestamp('application_end_at')->nullable();
            $table->date('next_application_date')->nullable();
            $table->boolean('has_interview')->default(false);

            // Kontenjan
            $table->integer('quota')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
