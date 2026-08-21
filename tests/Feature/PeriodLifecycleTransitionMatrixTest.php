<?php

namespace Tests\Feature;

use App\Exceptions\PeriodLifecycleException;
use App\Models\Period;
use App\Models\PeriodArchive;
use App\Models\Project;
use App\Models\User;
use App\Services\PeriodLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PeriodLifecycleTransitionMatrixTest extends TestCase
{
    use RefreshDatabase;

    public static function allowedTransitions(): array
    {
        return [
            'planned -> activate' => ['activate', 'planned', 'active'],
            'legacy passive -> activate' => ['activate', 'passive', 'active'],
            'planned -> update details' => ['update_details', 'planned', 'planned'],
            'legacy passive -> update details' => ['update_details', 'passive', 'passive'],
            'active -> update details' => ['update_details', 'active', 'active'],
            'active -> start closing' => ['start_closing', 'active', 'closing'],
            'closing -> cancel closing' => ['cancel_closing', 'closing', 'active'],
            'active -> complete' => ['complete', 'active', 'completed'],
            'closing -> complete' => ['complete', 'closing', 'completed'],
            'completed -> reopen planned' => ['reopen_planned', 'completed', 'planned'],
            'completed -> reopen active' => ['reopen_active', 'completed', 'active'],
            'planned -> cancel' => ['cancel', 'planned', 'cancelled'],
            'legacy passive -> cancel' => ['cancel', 'passive', 'cancelled'],
        ];
    }

    #[DataProvider('allowedTransitions')]
    public function test_allowed_transition_matrix(
        string $action,
        string $sourceStatus,
        string $expectedStatus,
    ): void {
        $actor = User::factory()->create(['surname' => 'Lifecycle']);
        $project = $this->project('allowed-'.$action.'-'.$sourceStatus);
        $period = $this->period($project, $sourceStatus);
        $this->synchronizeCurrentPointer($project, $period);

        $result = $this->invokeAction($action, $period, $actor, $project);
        $updatedPeriod = $result instanceof Period ? $result : $result['period'];

        $this->assertSame($expectedStatus, $updatedPeriod->status);
        $this->assertSame($expectedStatus, $period->fresh()->status);
        $this->assertGreaterThan(0, (int) $period->fresh()->lifecycle_version);

        if ($action === 'update_details') {
            $this->assertSame('Guncellenen Donem', $period->fresh()->name);
            $this->assertSame('updated', $period->lifecycleEvents()->latest('id')->value('event_type'));
        }

        if ($action === 'complete') {
            $this->assertDatabaseCount('period_archives', 1);
        }

        $expectedCurrentPeriodId = in_array($expectedStatus, ['active', 'closing'], true)
            ? $period->id
            : null;
        $this->assertSame($expectedCurrentPeriodId, $project->fresh()->current_period_id);
    }

    public static function invalidTransitions(): array
    {
        $validSources = [
            'activate' => ['planned', 'passive'],
            'update_details' => ['planned', 'passive', 'active'],
            'start_closing' => ['active'],
            'cancel_closing' => ['closing'],
            'complete' => ['active', 'closing'],
            'reopen_planned' => ['completed'],
            'cancel' => ['planned', 'passive'],
        ];
        $statuses = ['planned', 'passive', 'active', 'closing', 'completed', 'cancelled'];
        $cases = [];

        foreach ($validSources as $action => $allowedStatuses) {
            foreach (array_diff($statuses, $allowedStatuses) as $status) {
                $cases["{$status} cannot {$action}"] = [$action, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('invalidTransitions')]
    public function test_invalid_transition_matrix_does_not_mutate_state(
        string $action,
        string $sourceStatus,
    ): void {
        $actor = User::factory()->create(['surname' => 'Lifecycle']);
        $project = $this->project('invalid-'.$action.'-'.$sourceStatus);
        $period = $this->period($project, $sourceStatus);
        $this->synchronizeCurrentPointer($project, $period);
        $expectedCurrentPeriodId = $project->fresh()->current_period_id;

        try {
            $this->invokeAction($action, $period, $actor, $project);
            $this->fail("{$sourceStatus} durumunda {$action} reddedilmeliydi.");
        } catch (PeriodLifecycleException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertSame($sourceStatus, $period->fresh()->status);
        $this->assertSame(0, (int) $period->fresh()->lifecycle_version);
        $this->assertSame($expectedCurrentPeriodId, $project->fresh()->current_period_id);
        $this->assertDatabaseCount('period_lifecycle_events', 0);
        $this->assertDatabaseCount('period_archives', 0);
    }

    public function test_completion_rolls_back_status_pointer_events_and_archive_when_archive_factory_fails(): void
    {
        $actor = User::factory()->create(['surname' => 'Lifecycle']);
        $project = $this->project('completion-transaction-rollback');
        $period = $this->period($project, 'active');
        $this->synchronizeCurrentPointer($project, $period);

        try {
            app(PeriodLifecycleService::class)->complete(
                $period->id,
                $actor,
                'Arsiv olusturulurken hata olustu.',
                function (Period $lockedPeriod) use ($project): never {
                    $this->archive($project, $lockedPeriod);

                    throw new RuntimeException('Simule arsiv hatasi.');
                },
            );
            $this->fail('Arsiv hatasi kapanis islemini durdurmaliydi.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simule arsiv hatasi.', $exception->getMessage());
        }

        $this->assertSame('active', $period->fresh()->status);
        $this->assertSame(0, (int) $period->fresh()->lifecycle_version);
        $this->assertNull($period->fresh()->closing_started_at);
        $this->assertNull($period->fresh()->completed_at);
        $this->assertSame($period->id, $project->fresh()->current_period_id);
        $this->assertDatabaseCount('period_lifecycle_events', 0);
        $this->assertDatabaseCount('period_archives', 0);
    }

    private function invokeAction(
        string $action,
        Period $period,
        User $actor,
        Project $project,
    ): Period|array {
        $service = app(PeriodLifecycleService::class);

        return match ($action) {
            'activate' => $service->activate($period->id, $actor, 'Test aktivasyonu.'),
            'update_details' => $service->updateDetails($period->id, ['name' => 'Guncellenen Donem'], $actor),
            'start_closing' => $service->startClosing($period->id, $actor, 'Test kapanis hazirligi.'),
            'cancel_closing' => $service->cancelClosing($period->id, $actor, 'Test kapanis iptali.'),
            'complete' => $service->complete(
                $period->id,
                $actor,
                'Test tamamlamasi.',
                fn (Period $lockedPeriod) => $this->archive($project, $lockedPeriod),
            ),
            'reopen_planned' => $service->reopen($period->id, 'planned', $actor, 'Test arsiv duzeltmesi.'),
            'reopen_active' => $service->reopen($period->id, 'active', $actor, 'Test arsiv duzeltmesi.'),
            'cancel' => $service->cancel($period->id, $actor, 'Test donem iptali.'),
            default => throw new RuntimeException("Bilinmeyen yasam dongusu aksiyonu: {$action}"),
        };
    }

    private function synchronizeCurrentPointer(Project $project, Period $period): void
    {
        if (in_array($period->status, ['active', 'closing'], true)) {
            $project->forceFill(['current_period_id' => $period->id])->save();
        }
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

    private function period(Project $project, string $status): Period
    {
        return Period::query()->create([
            'project_id' => $project->id,
            'name' => 'Matris Donemi',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'credit_start_amount' => 100,
            'credit_threshold' => 75,
            'status' => $status,
            'lifecycle_version' => 0,
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
            'integrity_hash' => str_repeat('c', 64),
        ]);
    }
}
