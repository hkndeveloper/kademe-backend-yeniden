<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
        });

        Schema::table('certificates', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->change();
            $table->string('title')->nullable()->after('type');
            $table->string('issuer')->nullable()->after('title');
            $table->foreignId('uploaded_by_user_id')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->string('source', 40)->default('admin')->after('uploaded_by_user_id');
            $table->boolean('included_in_cv')->default(true)->after('source');

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropForeign(['uploaded_by_user_id']);
            $table->dropColumn(['title', 'issuer', 'uploaded_by_user_id', 'source', 'included_in_cv']);
            $table->foreignId('project_id')->nullable(false)->change();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }
};