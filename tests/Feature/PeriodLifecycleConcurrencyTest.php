<?php

namespace Tests\Feature;

use App\Models\Period;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PeriodLifecycleConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->postgresConcurrencyEnabled()) {
            return;
        }

        $database = (string) config('database.connections.pgsql.database');
        if (! str_ends_with(strtolower($database), '_test')) {
            throw new RuntimeException('Concurrency testi yalniz adi `_test` ile biten ayri bir PostgreSQL veritabaninda calistirilabilir.');
        }

        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_two_concurrent_activate_requests_produce_one_current_period_and_one_rejection(): void
    {
        $this->requirePostgresConcurrency();

        $actor = User::factory()->create(['surname' => 'Concurrency']);
        $project = $this->project('concurrent-activate');
        $period = $this->period($project, 'planned');

        $results = $this->runConcurrentWorkers('activate', $period, $actor);

        $this->assertSame([200, 422], $this->sortedStatuses($results));
        $this->assertSame('active', $period->fresh()->status);
        $this->assertSame($period->id, $project->fresh()->current_period_id);
        $this->assertSame(1, $period->lifecycleEvents()->where('event_type', 'activated')->count());
        $this->assertDatabaseCount('period_archives', 0);
    }

    public function test_two_concurrent_complete_requests_produce_one_archive_and_one_rejection(): void
    {
        $this->requirePostgresConcurrency();

        $actor = User::factory()->create(['surname' => 'Concurrency']);
        $project = $this->project('concurrent-complete');
        $period = $this->period($project, 'closing');
        $project->forceFill(['current_period_id' => $period->id])->save();

        $results = $this->runConcurrentWorkers('complete', $period, $actor);

        $this->assertSame([200, 422], $this->sortedStatuses($results));
        $this->assertSame('completed', $period->fresh()->status);
        $this->assertNull($project->fresh()->current_period_id);
        $this->assertSame(1, $period->lifecycleEvents()->where('event_type', 'completed')->count());
        $this->assertDatabaseCount('period_archives', 1);
        $this->assertDatabaseHas('period_archives', [
            'period_id' => $period->id,
            'archive_version' => 1,
        ]);
    }

    private function runConcurrentWorkers(string $action, Period $period, User $actor): array
    {
        $directory = storage_path('framework/testing');
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Test senkronizasyon dizini olusturulamadi: {$directory}");
        }

        $token = bin2hex(random_bytes(8));
        $barrierPath = $directory.DIRECTORY_SEPARATOR."period-concurrency-{$token}.go";
        $readyPaths = [
            $directory.DIRECTORY_SEPARATOR."period-concurrency-{$token}-1.ready",
            $directory.DIRECTORY_SEPARATOR."period-concurrency-{$token}-2.ready",
        ];
        $processes = [];

        try {
            foreach ($readyPaths as $readyPath) {
                $process = new Process([
                    PHP_BINARY,
                    base_path('tests/Fixtures/period_lifecycle_concurrency_worker.php'),
                    $action,
                    (string) $period->id,
                    (string) $actor->id,
                    $readyPath,
                    $barrierPath,
                ], base_path(), $this->workerEnvironment(), null, 30);
                $process->start();
                $processes[] = $process;
            }

            $deadline = microtime(true) + 15;
            while (collect($readyPaths)->contains(fn (string $path) => ! file_exists($path))) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Concurrency worker surecleri hazirlik zaman asimina ugradi.');
                }

                usleep(10_000);
            }

            touch($barrierPath);

            return array_map(function (Process $process): array {
                $process->wait();
                if (! $process->isSuccessful()) {
                    throw new RuntimeException(trim($process->getErrorOutput()) ?: 'Concurrency worker basarisiz oldu.');
                }

                return json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
            }, $processes);
        } finally {
            foreach ([$barrierPath, ...$readyPaths] as $path) {
                if (file_exists($path)) {
                    unlink($path);
                }
            }

            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
        }
    }

    private function workerEnvironment(): array
    {
        $connection = config('database.connections.pgsql');

        return [
            'APP_ENV' => 'testing',
            'CACHE_STORE' => 'array',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) $connection['host'],
            'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => (string) $connection['database'],
            'DB_USERNAME' => (string) $connection['username'],
            'DB_PASSWORD' => (string) $connection['password'],
            'DB_URL' => '',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
        ];
    }

    private function sortedStatuses(array $results): array
    {
        $statuses = array_map(fn (array $result) => (int) $result['status'], $results);
        sort($statuses);

        return $statuses;
    }

    private function requirePostgresConcurrency(): void
    {
        if (! $this->postgresConcurrencyEnabled()) {
            $this->markTestSkipped(
                'Gercek satir kilidi yarisi icin PostgreSQL ve PERIOD_LIFECYCLE_CONCURRENCY_TESTS=1 gerekir.',
            );
        }
    }

    private function postgresConcurrencyEnabled(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql'
            && filter_var(env('PERIOD_LIFECYCLE_CONCURRENCY_TESTS', false), FILTER_VALIDATE_BOOL);
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
            'name' => 'Concurrency Donemi',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'credit_start_amount' => 100,
            'credit_threshold' => 75,
            'status' => $status,
            'lifecycle_version' => 0,
        ]);
    }
}
