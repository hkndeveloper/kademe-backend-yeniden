<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationWindow;
use App\Models\Participant;
use App\Models\Period;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use App\Services\PeriodClosureReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PeriodClosureReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_returns_blockers_warnings_resolved_checks_and_watermark(): void
    {
        $project = Project::query()->create([
            'name' => 'Readiness Projesi',
            'slug' => 'readiness-project',
            'type' => 'other',
            'status' => 'active',
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => 'Readiness Donemi',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'closing',
            'credit_start_amount' => 100,
            'credit_threshold' => 75,
        ]);
        $student = User::factory()->create(['role' => 'student', 'surname' => 'Readiness']);
        Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
            'graduation_status' => null,
            'credit' => 60,
        ]);
        ApplicationWindow::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'is_open' => true,
        ]);
        Application::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'pending',
        ]);
        Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Acik Program',
            'start_at' => now(),
            'end_at' => now()->addHour(),
            'status' => 'scheduled',
        ]);

        $readiness = app(PeriodClosureReadinessService::class)->evaluate($period);

        $this->assertFalse($readiness['ready']);
        $this->assertNotEmpty($readiness['blockers']);
        $this->assertNotEmpty($readiness['warnings']);
        $this->assertNotEmpty($readiness['resolved_checks']);
        $this->assertNotEmpty($readiness['calculated_at']);
        $this->assertSame(64, strlen($readiness['watermark']));
        $this->assertSame(
            $readiness['watermark'],
            app(PeriodClosureReadinessService::class)->evaluate($period)['watermark'],
        );
        $this->assertSame(1, collect($readiness['blockers'])->firstWhere('code', 'open_programs')['count']);
        $this->assertSame(1, collect($readiness['warnings'])->firstWhere('code', 'low_credit')['count']);
    }
}
