<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('period_id')->constrained('periods')->cascadeOnDelete();
            $table->boolean('is_open')->default(false);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->date('next_application_date')->nullable();
            $table->boolean('has_interview')->default(false);
            $table->unsignedInteger('quota')->nullable();
            $table->text('change_note')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('opened_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('status_changed_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'period_id']);
            $table->index(['project_id', 'is_open']);
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->foreignId('application_window_id')
                ->nullable()
                ->after('period_id')
                ->constrained('application_windows')
                ->nullOnDelete();
        });

        $now = now();
        $pairs = DB::table('applications')
            ->whereNotNull('period_id')
            ->select(['project_id', 'period_id'])
            ->distinct()
            ->get()
            ->concat(
                DB::table('periods')
                    ->where('status', 'active')
                    ->select(['project_id', 'id as period_id'])
                    ->get()
            )
            ->unique(fn ($row) => $row->project_id.'-'.$row->period_id)
            ->values();

        foreach ($pairs as $pair) {
            $project = DB::table('projects')->where('id', $pair->project_id)->first();
            $period = DB::table('periods')->where('id', $pair->period_id)->first();
            if (! $project || ! $period) {
                continue;
            }

            $windowId = DB::table('application_windows')->insertGetId([
                'project_id' => $pair->project_id,
                'period_id' => $pair->period_id,
                'is_open' => $period->status === 'active' && (bool) $project->application_open,
                'starts_at' => $period->status === 'active' ? $project->application_start_at : null,
                'ends_at' => $period->status === 'active' ? $project->application_end_at : null,
                'next_application_date' => $project->next_application_date,
                'has_interview' => (bool) $project->has_interview,
                'quota' => $project->quota,
                'change_note' => 'Mevcut proje basvuru ayarlarindan otomatik aktarildi.',
                'opened_by' => null,
                'opened_at' => null,
                'closed_by' => null,
                'closed_at' => null,
                'updated_by' => null,
                'status_changed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('applications')
                ->where('project_id', $pair->project_id)
                ->where('period_id', $pair->period_id)
                ->update(['application_window_id' => $windowId]);
        }
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('application_window_id');
        });

        Schema::dropIfExists('application_windows');
    }
};
