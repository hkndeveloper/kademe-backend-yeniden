<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('icon_path')->nullable();
            
            // Eğer rozet belirli bir projeye özel ise
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('cascade');

            // Rozet Seviyesi
            $table->enum('tier', [
                'bronze',
                'silver',
                'gold',
                'platinum',
            ])->default('bronze');

            // Otomatik kazanım için gereken asgari kredi/puan
            $table->integer('required_points')->nullable();

            // KADEME+ profil özelleştirmeleri
            $table->string('frame_style')->nullable(); // Profil fotoğrafı çerçeve stili class/url
            $table->string('title_label')->nullable(); // Profil ünvanı, örn: "Ayın Pergellisi"

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('badges');
    }
};
