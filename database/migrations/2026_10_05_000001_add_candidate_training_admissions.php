<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_candidates', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('name');
            $table->string('surname');
            $table->string('phone', 30)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->string('application_scope', 20)->default('project');
        });
        // Classify KADEME+ without deleting or rewriting historical applications/accounts.
        DB::table('projects')->where(function ($query) {
            $query->whereIn('slug', ['kademe', 'kademe-plus'])->orWhere('name', 'KADEME+');
        })->update(['application_scope' => 'training']);
        Schema::create('project_trainings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('period_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('application_open')->default(false);
            $table->timestamp('application_start_at')->nullable();
            $table->timestamp('application_end_at')->nullable();
            $table->unsignedInteger('quota')->nullable();
            $table->timestamps();
        });
        Schema::table('application_forms', function (Blueprint $table) {
            $table->foreignId('training_id')->nullable()->constrained('project_trainings')->nullOnDelete();
        });
        Schema::table('applications', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreignId('candidate_id')->nullable()->constrained('application_candidates')->restrictOnDelete();
            $table->foreignId('training_id')->nullable()->constrained('project_trainings')->restrictOnDelete();
            $table->boolean('has_interview_snapshot')->nullable();
            $table->json('form_fields_snapshot')->nullable();
            $table->string('submission_key', 64)->nullable()->unique();
            $table->string('tracking_token_hash', 64)->nullable()->unique();
            $table->timestamp('tracking_expires_at')->nullable();
        });
        Schema::create('training_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_id')->constrained('project_trainings')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['training_id', 'user_id']);
        });
        Schema::table('project_modules', function (Blueprint $table) {
            $table->foreignId('training_id')->nullable()->constrained('project_trainings')->restrictOnDelete();
        });
        Schema::table('programs', function (Blueprint $table) {
            $table->foreignId('project_module_id')->nullable()->constrained('project_modules')->restrictOnDelete();
        });
        Schema::create('application_message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('event', 40);
            $table->string('email_subject')->nullable();
            $table->text('email_body')->nullable();
            $table->text('sms_body')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'event']);
        });
    }

    public function down(): void
    {
        if (DB::table('applications')->whereNotNull('candidate_id')->exists() || DB::table('project_trainings')->exists()) {
            throw new RuntimeException('Admissions records exist. Restore a database backup instead of a destructive rollback.');
        }
        Schema::dropIfExists('application_message_templates');
        Schema::table('programs', fn (Blueprint $table) => $table->dropConstrainedForeignId('project_module_id'));
        Schema::table('project_modules', fn (Blueprint $table) => $table->dropConstrainedForeignId('training_id'));
        Schema::dropIfExists('training_enrollments');
        Schema::table('applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('candidate_id');
            $table->dropConstrainedForeignId('training_id');
            $table->dropColumn(['has_interview_snapshot', 'form_fields_snapshot', 'submission_key', 'tracking_token_hash', 'tracking_expires_at']);
        });
        Schema::table('application_forms', fn (Blueprint $table) => $table->dropConstrainedForeignId('training_id'));
        Schema::dropIfExists('project_trainings');
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('application_scope'));
        Schema::dropIfExists('application_candidates');
    }
};
