<?php

namespace Tests\Feature;

use App\Models\Period;
use App\Models\PeriodArchive;
use App\Models\Project;
use App\Services\PeriodLifecycleBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PeriodLifecycleBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_plans_single_current_pointer_without_mutating_data(): void
    {
        $project = $this->project('dry-run');
        $period = $this->period($project, 'Aktif Donem', 'active');

        $report = app(PeriodLifecycleBackfillService::class)->execute();

        $this->assertSame('dry-run', $report['meta']['mode']);
        $this->assertFalse($report['meta']['applied']);
        $this->assertSame(1, $report['summary']['pointer_change_count']);
        $this->assertSame(0, $report['summary']['blocker_count']);
        $this->assertNull($project->fresh()->current_period_id);
        $this->assertDatabaseMissing('period_lifecycle_events', [
            'period_id' => $period->id,
            'event_type' => 'current_pointer_backfilled',
        ]);
    }

    public function test_apply_fills_pointer_and_second_run_is_idempotent(): void
    {
        $project = $this->project('pointer-apply');
        $period = $this->period($project, 'Aktif Donem', 'active');
        $service = app(PeriodLifecycleBackfillService::class);

        $first = $service->execute(true);
        $second = $service->execute(true);

        $this->assertTrue($first['meta']['applied']);
        $this->assertSame(1, $first['summary']['applied_change_count']);
        $this->assertTrue($first['verification']['idempotent']);
        $this->assertSame($period->id, $project->fresh()->current_period_id);
        $this->assertSame(0, $second['summary']['applied_change_count']);
        $this->assertSame(0, $second['summary']['proposed_change_count']);
        $this->assertTrue($second['verification']['idempotent']);
        $this->assertDatabaseCount('period_lifecycle_events', 1);
    }

    public function test_multiple_active_or_closing_candidates_block_the_entire_apply(): void
    {
        $project = $this->project('ambiguous-pointer');
        $this->period($project, 'Aktif Donem', 'active');
        $this->allowLegacyDuplicateCurrentPeriods();
        $this->period($project, 'Kapanan Donem', 'closing', 1);

        $report = app(PeriodLifecycleBackfillService::class)->execute(true);

        $this->assertFalse($report['meta']['applied']);
        $this->assertGreaterThan(0, $report['summary']['blocker_count']);
        $this->assertContains('multiple_current_period_candidates', array_column($report['blockers'], 'type'));
        $this->assertNull($project->fresh()->current_period_id);
    }

    public function test_historical_passive_period_requires_explicit_manual_mapping(): void
    {
        $project = $this->project('historical-passive');
        $period = $this->period($project, 'Gecmis Donem', 'passive', -2);

        $report = app(PeriodLifecycleBackfillService::class)->execute(true);

        $this->assertFalse($report['meta']['applied']);
        $this->assertContains('historical_passive_requires_mapping', array_column($report['blockers'], 'type'));
        $this->assertSame('passive', $period->fresh()->status);
    }

    public function test_approved_historical_completion_creates_marked_archive_and_is_idempotent(): void
    {
        $project = $this->project('legacy-completion');
        $period = $this->period($project, 'Gecmis Donem', 'passive', -2);
        $mapping = [
            'passive_periods' => [
                (string) $period->id => [
                    'target_status' => 'completed',
                    'reason' => 'Koordinator onayli gecmis donem eslemesi.',
                ],
            ],
            'legacy_archive_period_ids' => [$period->id],
            'legacy_archive_reason' => 'Koordinator tarafindan arsiv backfill onayi verildi.',
        ];
        $service = app(PeriodLifecycleBackfillService::class);

        $first = $service->execute(true, $mapping);
        $second = $service->execute(true, $mapping);

        $archive = PeriodArchive::query()->where('period_id', $period->id)->firstOrFail();
        $this->assertTrue($first['verification']['idempotent']);
        $this->assertSame('completed', $period->fresh()->status);
        $this->assertSame('legacy_backfill', $archive->override_reason);
        $this->assertTrue($archive->summary_json['legacy_backfill']);
        $this->assertSame(0, $second['summary']['proposed_change_count']);
        $this->assertSame(0, $second['summary']['blocker_count']);
        $this->assertDatabaseCount('period_archives', 1);
    }

    public function test_completed_without_archive_is_never_fabricated_without_explicit_approval(): void
    {
        $project = $this->project('archive-approval');
        $period = $this->period($project, 'Tamamlanan Donem', 'completed', -1);

        $report = app(PeriodLifecycleBackfillService::class)->execute(true);

        $this->assertFalse($report['meta']['applied']);
        $this->assertContains('completed_period_requires_archive_approval', array_column($report['blockers'], 'type'));
        $this->assertDatabaseMissing('period_archives', ['period_id' => $period->id]);
    }

    public function test_legacy_read_is_logged_and_strict_cutover_disables_fallback(): void
    {
        $project = $this->project('fallback-telemetry');
        $period = $this->period($project, 'Aktif Donem', 'active');
        Log::spy();

        $this->assertTrue($project->currentPeriodOrLegacy()->is($period));
        Log::shouldHaveReceived('warning')
            ->once()
            ->with('period_lifecycle.legacy_current_period_fallback_used', ['project_id' => $project->id]);

        config()->set('period_lifecycle.enforce_current_period_pointer', true);
        $this->assertNull($project->currentPeriodOrLegacy());

        $project->forceFill(['current_period_id' => $period->id])->save();
        $this->assertTrue($project->fresh()->currentPeriodOrLegacy()->is($period));
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

    private function allowLegacyDuplicateCurrentPeriods(): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX periods_one_current_status_per_project');

            return;
        }

        $this->markTestSkipped('Legacy coklu guncel donem simulasyonu yalnizca PostgreSQL ve SQLite test veritabanlarinda desteklenir.');
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
}
