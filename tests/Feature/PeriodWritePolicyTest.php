<?php

namespace Tests\Feature;

use App\Enums\PeriodWriteAction;
use App\Models\Period;
use App\Models\Project;
use App\Services\PeriodWritePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PeriodWritePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_write_matrix_distinguishes_configuration_creation_and_resolution(): void
    {
        $policy = app(PeriodWritePolicy::class);
        $planned = $this->period($this->project('write-matrix-planned'), 'planned');
        $active = $this->period($this->project('write-matrix-active'), 'active');
        $closing = $this->period($this->project('write-matrix-closing'), 'closing');

        $this->assertSame('configure_period', $policy->assertAllowed(null, $planned, PeriodWriteAction::CONFIGURE_PERIOD)['mode']);
        $this->assertLocked(fn () => $policy->assertAllowed(null, $planned, PeriodWriteAction::CREATE_OPERATION));
        $this->assertSame('create_operation', $policy->assertAllowed(null, $active, PeriodWriteAction::CREATE_OPERATION)['mode']);
        $this->assertSame('resolve_operation', $policy->assertAllowed(null, $active, PeriodWriteAction::RESOLVE_OPERATION)['mode']);
        $this->assertLocked(fn () => $policy->assertAllowed(null, $closing, PeriodWriteAction::CREATE_OPERATION));
        $this->assertSame('resolve_operation', $policy->assertAllowed(null, $closing, PeriodWriteAction::RESOLVE_OPERATION)['mode']);
    }

    public function test_completed_and_cancelled_periods_are_read_only_without_archive_correction(): void
    {
        $policy = app(PeriodWritePolicy::class);
        $project = $this->project('archive-lock');

        foreach (['completed', 'cancelled'] as $status) {
            $period = $this->period($project, $status);
            $this->assertLocked(fn () => $policy->assertAllowed(null, $period, PeriodWriteAction::RESOLVE_OPERATION));
        }
    }

    public function test_rejected_period_write_emits_structured_warning(): void
    {
        $policy = app(PeriodWritePolicy::class);
        $project = $this->project('write-monitor');
        $period = $this->period($project, 'closing');
        Log::spy();

        $this->assertLocked(fn () => $policy->assertAllowed(null, $period, PeriodWriteAction::CREATE_OPERATION));

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $event, array $context) => $event === 'period_lifecycle.write_rejected'
                && $context['period_id'] === $period->id
                && $context['period_status'] === 'closing'
                && $context['write_action'] === 'create_operation'
                && $context['status_code'] === 423
        )->once();
    }

    private function assertLocked(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Donem yazma islemi kilitlenmeliydi.');
        } catch (HttpException $exception) {
            $this->assertSame(423, $exception->getStatusCode());
        }
    }

    private function period(Project $project, string $status): Period
    {
        return Period::query()->create([
            'project_id' => $project->id,
            'name' => strtoupper($status).' Donemi',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => $status,
            'credit_start_amount' => 100,
            'credit_threshold' => 75,
        ]);
    }

    private function project(string $slug): Project
    {
        return Project::query()->create([
            'name' => strtoupper($slug),
            'slug' => $slug,
            'type' => 'other',
            'status' => 'active',
        ]);
    }
}
