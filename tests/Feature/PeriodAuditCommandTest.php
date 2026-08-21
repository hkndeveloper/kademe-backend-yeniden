<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Period;
use App\Models\Project;
use App\Models\User;
use App\Services\PeriodAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PeriodAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_period_audit_reports_a_healthy_consistent_dataset(): void
    {
        $project = $this->project('audit-healthy');
        $activePeriod = $this->period($project, 'Aktif Donem', 'active');
        $project->currentPeriod()->associate($activePeriod);
        $project->save();

        $report = app(PeriodAuditService::class)->run();

        $this->assertTrue($report['meta']['report_only']);
        $this->assertTrue($report['summary']['healthy']);
        $this->assertSame(0, $report['summary']['anomaly_count']);
        $this->assertContains('applications', array_column($report['period_tables'], 'table'));

        $this->artisan('periods:audit', ['--report-only' => true, '--strict' => true])
            ->expectsOutputToContain('kritik anomali bulunamadi')
            ->assertSuccessful();
    }

    public function test_period_audit_finds_multiple_active_and_missing_archive(): void
    {
        $firstProject = $this->project('audit-first');
        $this->period($firstProject, 'Birinci Aktif', 'active');
        $this->allowLegacyDuplicateCurrentPeriods();
        $this->period($firstProject, 'Ikinci Aktif', 'active');
        $this->period($firstProject, 'Arsivsiz Tamamlanan', 'completed');

        $report = app(PeriodAuditService::class)->run();

        $this->assertFalse($report['summary']['healthy']);
        $this->assertCount(1, $report['anomalies']['multiple_active_projects']);
        $this->assertCount(1, $report['anomalies']['completed_without_archive']);

        $this->artisan('periods:audit', ['--strict' => true])
            ->expectsOutputToContain('kritik anomali bulundu')
            ->assertFailed();
    }

    public function test_nullable_global_period_rows_are_warnings_not_anomalies(): void
    {
        $project = $this->project('audit-global-content');
        $activePeriod = $this->period($project, 'Aktif Donem', 'active');
        $project->currentPeriod()->associate($activePeriod);
        $project->save();
        $creator = User::factory()->create(['surname' => 'Yonetici']);

        Announcement::query()->create([
            'title' => 'Genel duyuru',
            'content' => 'Tum donemlerde gorunur.',
            'period_id' => null,
            'created_by' => $creator->id,
        ]);

        $report = app(PeriodAuditService::class)->run();

        $this->assertTrue($report['summary']['healthy']);
        $this->assertContains('announcements', array_column($report['warnings']['nullable_period_rows'], 'table'));
    }

    public function test_missing_current_pointer_is_transitional_warning_until_cutover(): void
    {
        $project = $this->project('audit-pointer-transition');
        $period = $this->period($project, 'Aktif Donem', 'active');

        $report = app(PeriodAuditService::class)->run();

        $this->assertTrue($report['summary']['healthy']);
        $this->assertSame($period->id, $report['warnings']['current_period_pointer'][0]['period_id']);

        config()->set('period_lifecycle.enforce_current_period_pointer', true);
        $strictReport = app(PeriodAuditService::class)->run();

        $this->assertFalse($strictReport['summary']['healthy']);
        $this->assertSame('active_period_without_pointer', $strictReport['anomalies']['current_period_pointer'][0]['type']);
    }

    public function test_active_and_closing_periods_are_both_counted_as_current_candidates(): void
    {
        $project = $this->project('audit-current-candidates');
        $this->period($project, 'Aktif Donem', 'active');
        $this->allowLegacyDuplicateCurrentPeriods();
        $this->period($project, 'Kapanan Donem', 'closing');

        $report = app(PeriodAuditService::class)->run();

        $this->assertFalse($report['summary']['healthy']);
        $this->assertSame(2, $report['anomalies']['multiple_active_projects'][0]['active_period_count']);
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

    private function period(Project $project, string $name, string $status): Period
    {
        return Period::query()->create([
            'project_id' => $project->id,
            'name' => $name,
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'credit_start_amount' => 100,
            'credit_threshold' => 75,
            'status' => $status,
        ]);
    }
}
