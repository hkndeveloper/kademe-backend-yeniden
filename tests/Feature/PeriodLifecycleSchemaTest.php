<?php

namespace Tests\Feature;

use App\Models\Period;
use App\Models\PeriodArchive;
use App\Models\PeriodLifecycleEvent;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PeriodLifecycleSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_lifecycle_foundation_is_additive_and_relations_are_available(): void
    {
        $this->assertTrue(Schema::hasColumns('projects', ['current_period_id']));
        $this->assertTrue(Schema::hasColumns('periods', [
            'lifecycle_version',
            'activated_at',
            'activated_by',
            'closing_started_at',
            'closing_started_by',
            'completed_at',
            'completed_by',
            'reopened_at',
            'reopened_by',
            'cancelled_at',
            'cancelled_by',
        ]));
        $this->assertTrue(Schema::hasColumns('period_archives', [
            'schema_version',
            'previous_archive_id',
            'previous_hash',
            'snapshot_json',
            'manifest_json',
            'readiness_json',
            'override_reason',
            'correction_reason',
            'verification_status',
            'verified_at',
            'verified_by',
        ]));
        $this->assertTrue(Schema::hasTable('period_lifecycle_events'));

        $actor = User::factory()->create(['surname' => 'Yonetici']);
        $project = $this->project('lifecycle-schema');
        $period = $this->period($project, 'Aktif Donem', 'active');

        $project->currentPeriod()->associate($period);
        $project->save();

        $event = PeriodLifecycleEvent::query()->create([
            'period_id' => $period->id,
            'project_id' => $project->id,
            'event_type' => 'activated',
            'from_status' => 'planned',
            'to_status' => 'active',
            'actor_id' => $actor->id,
            'reason' => 'Test aktivasyonu',
            'metadata_json' => ['source' => 'test'],
        ]);

        $this->assertTrue($project->fresh()->currentPeriod->is($period));
        $this->assertTrue($period->lifecycleEvents()->first()->is($event));
        $this->assertSame('test', $event->metadata_json['source']);
    }

    public function test_new_lifecycle_status_values_are_accepted_by_the_database(): void
    {
        $project = $this->project('lifecycle-statuses');

        foreach (['planned', 'closing', 'cancelled'] as $index => $status) {
            $period = $this->period($project, "Donem {$index}", $status, $index);
            $this->assertSame($status, $period->fresh()->status);
        }
    }

    public function test_archive_version_is_unique_per_period(): void
    {
        $project = $this->project('archive-version');
        $period = $this->period($project, 'Tamamlanan Donem', 'completed');

        $this->archive($project, $period, 1);

        $this->expectException(QueryException::class);
        $this->archive($project, $period, 1);
    }

    public function test_critical_records_reject_a_project_period_mismatch(): void
    {
        $firstProject = $this->project('integrity-first');
        $secondProject = $this->project('integrity-second');
        $period = $this->period($firstProject, 'Birinci Donem', 'active');
        $user = User::factory()->create(['surname' => 'Aday']);

        $this->expectException(QueryException::class);

        DB::table('applications')->insert([
            'user_id' => $user->id,
            'project_id' => $secondProject->id,
            'period_id' => $period->id,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_database_rejects_a_second_active_or_closing_period_for_the_same_project(): void
    {
        $project = $this->project('single-current-status');
        $this->period($project, 'Aktif Donem', 'active');

        $this->expectException(QueryException::class);

        $this->period($project, 'Kapanan Donem', 'closing', 1);
    }

    public function test_database_allows_non_current_periods_and_current_periods_in_different_projects(): void
    {
        $firstProject = $this->project('single-current-first');
        $secondProject = $this->project('single-current-second');

        $this->period($firstProject, 'Planlanan Donem', 'planned');
        $this->period($firstProject, 'Tamamlanan Donem', 'completed', -1);
        $this->period($firstProject, 'Aktif Donem', 'active', 1);
        $this->period($secondProject, 'Diger Proje Aktif Donem', 'active', 1);

        $this->assertSame(3, Period::query()->where('project_id', $firstProject->id)->count());
        $this->assertSame(1, Period::query()->where('project_id', $secondProject->id)->count());
    }

    private function project(string $slug): Project
    {
        return Project::query()->create([
            'name' => str($slug)->headline()->toString(),
            'slug' => $slug,
            'type' => 'other',
            'status' => 'active',
        ]);
    }

    private function period(Project $project, string $name, string $status, int $yearOffset = 0): Period
    {
        return Period::query()->create([
            'project_id' => $project->id,
            'name' => $name,
            'start_date' => now()->addYears($yearOffset)->startOfYear()->toDateString(),
            'end_date' => now()->addYears($yearOffset)->endOfYear()->toDateString(),
            'credit_start_amount' => 100,
            'credit_threshold' => 75,
            'status' => $status,
        ]);
    }

    private function archive(Project $project, Period $period, int $version): PeriodArchive
    {
        return PeriodArchive::query()->create([
            'period_id' => $period->id,
            'project_id' => $project->id,
            'closed_at' => now(),
            'archive_version' => $version,
            'schema_version' => 1,
            'summary_json' => [],
            'warnings_json' => [],
            'counts_json' => [],
            'integrity_hash' => str_repeat('a', 64),
        ]);
    }
}
