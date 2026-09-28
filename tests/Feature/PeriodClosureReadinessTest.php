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

    public function test_interview_outcomes_require_a_final_application_decision_before_closure(): void
    {
        $project = Project::query()->create([
            'name' => 'Interview closure', 'slug' => 'interview-closure',
            'type' => 'other', 'status' => 'active',
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id, 'name' => 'Interview period',
            'start_date' => now()->subMonth(), 'end_date' => now()->addMonth(),
            'status' => 'closing',
        ]);
        $student = User::factory()->create(['surname' => 'Interview']);
        $application = Application::query()->create([
            'user_id' => $student->id, 'project_id' => $project->id,
            'period_id' => $period->id, 'status' => 'interview_failed',
        ]);

        foreach (['interview_failed', 'interview_passed'] as $status) {
            $application->update(['status' => $status]);
            $readiness = app(PeriodClosureReadinessService::class)->evaluate($period);
            $this->assertFalse($readiness['ready']);
            $this->assertSame(1, collect($readiness['blockers'])->firstWhere('code', 'unresolved_applications')['count']);
        }

        $application->update(['status' => 'rejected']);
        $readiness = app(PeriodClosureReadinessService::class)->evaluate($period);
        $this->assertTrue($readiness['ready']);
        $this->assertSame(0, collect($readiness['checks'])->firstWhere('code', 'unresolved_applications')['count']);
    }
}
