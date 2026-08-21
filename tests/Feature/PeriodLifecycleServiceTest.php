<?php

namespace Tests\Feature;

use App\Exceptions\PeriodLifecycleException;
use App\Models\Period;
use App\Models\PeriodArchive;
use App\Models\Project;
use App\Models\User;
use App\Models\ApplicationWindow;
use App\Services\PeriodLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class PeriodLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_activate_close_cancel_close_and_complete_are_audited(): void
    {
        $actor = User::factory()->create(['surname' => 'Koordinator']);
        $project = $this->project('lifecycle-flow');
        $service = app(PeriodLifecycleService::class);

        $period = $service->createPlanned([
            'project_id' => $project->id,
            'name' => '2026 Donemi',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'credit_start_amount' => 100,
            'credit_threshold' => 75,
        ], $actor);

        $this->assertSame('planned', $period->status);
        $this->assertSame(['created'], $period->lifecycleEvents()->pluck('event_type')->all());

        $period = $service->activate($period->id, $actor, 'Yeni donem baslatildi.');
        $this->assertSame('active', $period->status);
        $this->assertSame($period->id, $project->fresh()->current_period_id);

        $period = $service->startClosing($period->id, $actor, 'Kapanis kontrolleri basladi.');
        $this->assertSame('closing', $period->status);

        $period = $service->cancelClosing($period->id, $actor, 'Eksik isler bulundu.');
        $this->assertSame('active', $period->status);

        $period = $service->startClosing($period->id, $actor);
        $result = $service->complete(
            $period->id,
            $actor,
            'Donem kapatildi.',
            fn (Period $lockedPeriod) => $this->archive($project, $lockedPeriod),
        );

        $this->assertSame('completed', $result['period']->status);
        $this->assertNull($project->fresh()->current_period_id);
        $this->assertSame(
            ['created', 'activated', 'closing_started', 'closing_cancelled', 'closing_started', 'completed'],
            $period->lifecycleEvents()->pluck('event_type')->all(),
        );
        $completedEvent = $period->lifecycleEvents()->where('event_type', 'completed')->firstOrFail();
        $this->assertSame($result['archive']->id, $completedEvent->metadata_json['archive_id']);
    }

    public function test_activation_rejects_a_second_current_period_without_mutating_it(): void
    {
        $actor = User::factory()->create(['surname' => 'Koordinator']);
        $project = $this->project('lifecycle-conflict');
        $service = app(PeriodLifecycleService::class);
        $current = $this->period($project, 'Mevcut Donem', 'active', 0);
        $planned = $this->period($project, 'Yeni Donem', 'planned', 1);
        $project->currentPeriod()->associate($current);
        $project->save();

        try {
            $service->activate($planned->id, $actor);
            $this->fail('Ikinci aktif donem reddedilmeliydi.');
        } catch (PeriodLifecycleException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertSame('active', $current->fresh()->status);
        $this->assertSame('planned', $planned->fresh()->status);
        $this->assertSame($current->id, $project->fresh()->current_period_id);
    }

    public function test_legacy_active_period_without_pointer_is_adopted_when_closing_starts(): void
    {
        $actor = User::factory()->create(['surname' => 'Koordinator']);
        $project = $this->project('legacy-pointer-adoption');
        $period = $this->period($project, 'Eski Aktif Donem', 'active');

        $closed = app(PeriodLifecycleService::class)->startClosing($period->id, $actor);

        $this->assertSame('closing', $closed->status);
        $this->assertSame($period->id, $project->fresh()->current_period_id);
    }

    public function test_start_closing_atomically_closes_period_application_intake(): void
    {
        $actor = User::factory()->create(['surname' => 'Koordinator']);
        $project = $this->project('closing-intake');
        $project->forceFill(['application_open' => true])->save();
        $period = $this->period($project, 'Basvuru Acik Donem', 'active');
        $project->forceFill(['current_period_id' => $period->id])->save();
        $window = ApplicationWindow::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'is_open' => true,
            'opened_by' => $actor->id,
            'opened_at' => now(),
        ]);

        $closed = app(PeriodLifecycleService::class)->startClosing($period->id, $actor, 'Kapanis basladi.');

        $this->assertSame('closing', $closed->status);
        $this->assertFalse($project->fresh()->application_open);
        $this->assertFalse($window->fresh()->is_open);
        $this->assertSame($actor->id, $window->fresh()->closed_by);
        $this->assertNotNull($window->fresh()->closed_at);
        $this->assertSame(
            1,
            $closed->lifecycleEvents()->where('event_type', 'closing_started')->firstOrFail()->metadata_json['closed_application_windows'],
        );
    }

    public function test_completed_period_can_reopen_only_to_planned_or_conflict_free_active(): void
    {
        $actor = User::factory()->create(['surname' => 'Superadmin']);
        $project = $this->project('lifecycle-reopen');
        $completed = $this->period($project, 'Arsiv Donemi', 'completed');
        $service = app(PeriodLifecycleService::class);

        $reopened = $service->reopen($completed->id, 'planned', $actor, 'Arsiv duzeltmesi gerekiyor.');

        $this->assertSame('planned', $reopened->status);
        $this->assertSame('reopened', $reopened->lifecycleEvents()->latest('id')->value('event_type'));
        $this->assertSame('Arsiv duzeltmesi gerekiyor.', $reopened->lifecycleEvents()->latest('id')->value('reason'));
    }

    public function test_planned_period_can_be_cancelled_with_audit_reason(): void
    {
        $actor = User::factory()->create(['surname' => 'Koordinator']);
        $project = $this->project('lifecycle-cancel');
        $period = $this->period($project, 'Iptal Edilecek', 'planned');

        $cancelled = app(PeriodLifecycleService::class)->cancel($period->id, $actor, 'Proje takvimi degisti.');

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame('Proje takvimi degisti.', $cancelled->lifecycleEvents()->latest('id')->value('reason'));
    }

    public function test_lifecycle_events_are_append_only(): void
    {
        $actor = User::factory()->create(['surname' => 'Koordinator']);
        $project = $this->project('lifecycle-immutable-event');
        $period = app(PeriodLifecycleService::class)->createPlanned([
            'project_id' => $project->id,
            'name' => 'Planlanan Donem',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'credit_start_amount' => 100,
            'credit_threshold' => 75,
        ], $actor);

        $event = $period->lifecycleEvents()->firstOrFail();

        $this->expectException(LogicException::class);
        $event->update(['reason' => 'Degistirilemez']);
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

    private function archive(Project $project, Period $period): PeriodArchive
    {
        return PeriodArchive::query()->create([
            'period_id' => $period->id,
            'project_id' => $project->id,
            'closed_at' => now(),
            'archive_version' => 1,
            'schema_version' => 1,
            'summary_json' => [],
            'warnings_json' => [],
            'counts_json' => [],
            'integrity_hash' => str_repeat('b', 64),
        ]);
    }
}
