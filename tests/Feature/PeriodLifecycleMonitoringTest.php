<?php

namespace Tests\Feature;

use App\Exceptions\PeriodLifecycleException;
use App\Models\Period;
use App\Models\PeriodLifecycleEvent;
use App\Models\Project;
use App\Models\User;
use App\Services\PeriodLifecycleMonitor;
use App\Services\PeriodLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PeriodLifecycleMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_emits_structured_success_event_for_committed_transition(): void
    {
        $event = (new PeriodLifecycleEvent)->forceFill([
            'id' => 44,
            'event_type' => 'activated',
            'period_id' => 12,
            'project_id' => 7,
            'from_status' => 'planned',
            'to_status' => 'active',
            'actor_id' => 5,
        ]);
        Log::spy();

        app(PeriodLifecycleMonitor::class)->transitionSucceeded($event, 3);

        Log::shouldHaveReceived('info')->withArgs(
            fn (string $event, array $context) => $event === 'period_lifecycle.transition_succeeded'
                && $context['event_type'] === 'activated'
                && $context['period_id'] === 12
                && $context['project_id'] === 7
                && $context['actor_id'] === 5
                && $context['lifecycle_version'] === 3
        )->once();
    }

    public function test_rejected_lifecycle_transition_emits_structured_warning(): void
    {
        $actor = User::factory()->create(['surname' => 'Monitor']);
        $project = $this->project('monitor-rejection');
        $current = $this->period($project, 'Mevcut Donem', 'active');
        $planned = $this->period($project, 'Yeni Donem', 'planned');
        $project->forceFill(['current_period_id' => $current->id])->save();
        Log::spy();

        try {
            app(PeriodLifecycleService::class)->activate($planned->id, $actor);
            $this->fail('Ikinci aktif donem reddedilmeliydi.');
        } catch (PeriodLifecycleException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $event, array $context) => $event === 'period_lifecycle.transition_rejected'
                && $context['operation'] === 'activate'
                && $context['period_id'] === $planned->id
                && $context['actor_id'] === $actor->id
                && $context['status_code'] === 409
        )->once();
    }

    public function test_archive_verification_is_registered_in_the_scheduler(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('periods:verify-archives')
            ->assertSuccessful();
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

    private function period(Project $project, string $name, string $status): Period
    {
        return Period::query()->create([
            'project_id' => $project->id,
            'name' => $name,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => $status,
            'credit_start_amount' => 100,
            'credit_threshold' => 75,
        ]);
    }
}
