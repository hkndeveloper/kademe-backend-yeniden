<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Participant;
use App\Models\Period;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use App\Services\ApplicationEmailVerificationService;
use App\Services\NotificationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ApplicationAcceptanceConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql'
            || ! filter_var(env('APPLICATION_ACCEPTANCE_CONCURRENCY_TESTS', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Ayri PostgreSQL test veritabani ve APPLICATION_ACCEPTANCE_CONCURRENCY_TESTS=1 gerekir.');
        }

        if (! app()->environment('testing')
            || config('database.connections.pgsql.database') !== 'kademe_admissions_concurrency_test'
            || config('database.connections.pgsql.host') !== '127.0.0.1') {
            throw new RuntimeException('Bu test yalniz yerel kademe_admissions_concurrency_test veritabanini sifirlayabilir.');
        }

        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->seed(RolePermissionSeeder::class);
    }

    public static function competingPaths(): array
    {
        return [
            'two panel acceptances' => ['panel', 'panel', false],
            'panel and waitlist' => ['panel', 'waitlist', false],
            'two waitlist responses' => ['waitlist', 'waitlist', false],
            'shared period quota across programs' => ['panel', 'waitlist', true],
        ];
    }

    #[DataProvider('competingPaths')]
    public function test_last_seat_can_only_be_accepted_once(string $firstPath, string $secondPath, bool $sharedQuota): void
    {
        [$project, $period, $program] = $this->scope('last-seat', $sharedQuota ? null : 1);
        $otherProgram = $sharedQuota ? $program->replicate() : $program;
        if ($sharedQuota) {
            $otherProgram->save();
        }
        $first = $this->application($project, $period, $program);
        $second = $this->application($project, $period, $otherProgram);
        foreach ([[$first, $firstPath], [$second, $secondPath]] as [$application, $path]) {
            if ($path === 'panel') {
                $application->update([
                    'status' => 'pending',
                    'waitlist_invited_at' => null,
                    'waitlist_invitation_expires_at' => null,
                ]);
            }
        }

        $results = $this->runWorkers([[$first, $firstPath], [$second, $secondPath]]);

        // Two active invitations for one place represent inconsistent old data;
        // neither may overbook it. Normal competing paths still accept one.
        $expectedAccepted = $firstPath === 'waitlist' && $secondPath === 'waitlist' ? 0 : 1;
        $this->assertSame($expectedAccepted === 0 ? [422, 422] : [200, 422], $this->statuses($results), json_encode($results));
        $this->assertSame($expectedAccepted, Application::query()->where('status', 'accepted')->count());
        $this->assertDatabaseCount('participants', $expectedAccepted);
    }

    public function test_two_responses_to_the_same_invitation_do_not_reverse_or_duplicate_acceptance(): void
    {
        [$project, $period, $program] = $this->scope('same-invitation');
        $application = $this->application($project, $period, $program);

        $results = $this->runWorkers([[$application, 'panel'], [$application, 'waitlist']]);

        $this->assertSame([200, 422], $this->statuses($results), json_encode($results));
        $this->assertSame('accepted', $application->fresh()->status);
        $this->assertDatabaseCount('participants', 1);
    }

    public function test_invitation_and_normal_acceptance_cannot_claim_the_same_last_place(): void
    {
        [$project, $period, $program] = $this->scope('invite-versus-accept');
        $invitation = $this->application($project, $period, $program);
        $invitation->update([
            'waitlist_invited_at' => null,
            'waitlist_invitation_expires_at' => null,
        ]);
        $normal = $this->application($project, $period, $program);
        $normal->update([
            'status' => 'pending',
            'waitlist_invited_at' => null,
            'waitlist_invitation_expires_at' => null,
        ]);

        $results = $this->runWorkers([[$invitation, 'invite'], [$normal, 'panel']]);

        $this->assertSame([200, 422], $this->statuses($results), json_encode($results));
        $accepted = Application::query()->where('status', 'accepted')->count();
        $reserved = Application::query()->where('status', 'waitlisted')->whereNotNull('waitlist_invited_at')->count();
        $this->assertSame(1, $accepted + $reserved);
        $this->assertDatabaseCount('participants', $accepted);
    }

    public function test_two_invitation_requests_reserve_only_one_last_place(): void
    {
        [$project, $period, $program] = $this->scope('two-invitations');
        $first = $this->application($project, $period, $program);
        $second = $this->application($project, $period, $program);
        foreach ([$first, $second] as $application) {
            $application->update([
                'waitlist_invited_at' => null,
                'waitlist_invitation_expires_at' => null,
            ]);
        }

        $results = $this->runWorkers([[$first, 'invite'], [$second, 'invite']]);

        $this->assertSame([200, 422], $this->statuses($results), json_encode($results));
        $this->assertSame(1, Application::query()->where('status', 'waitlisted')->whereNotNull('waitlist_invited_at')->count());
        $this->assertDatabaseCount('participants', 0);
    }

    public function test_waitlist_move_cannot_overwrite_a_concurrent_acceptance(): void
    {
        [$project, $period, $program] = $this->scope('waitlist-move');
        $application = $this->application($project, $period, $program);

        $results = $this->runWorkers([[$application, 'panel'], [$application, 'move_to_waitlist']]);

        $this->assertSame(200, $results[0]['status'], json_encode($results));
        $this->assertContains($results[1]['status'], [200, 422]);
        $this->assertSame('accepted', $application->fresh()->status);
        $this->assertDatabaseCount('participants', 1);
    }

    public function test_one_applicant_cannot_be_concurrently_enrolled_in_two_projects(): void
    {
        [$firstProject, $firstPeriod, $firstProgram] = $this->scope('first-project');
        [$secondProject, $secondPeriod, $secondProgram] = $this->scope('second-project');
        $secondProgram->update([
            'start_at' => now()->addDays(2),
            'end_at' => now()->addDays(2)->addHour(),
        ]);
        $first = $this->application($firstProject, $firstPeriod, $firstProgram);
        $second = $this->application($secondProject, $secondPeriod, $secondProgram, $first->user);

        $results = $this->runWorkers([[$first, 'panel'], [$second, 'waitlist']]);

        $this->assertSame([200, 422], $this->statuses($results), json_encode($results));
        $this->assertSame(1, Participant::query()->where('user_id', $first->user_id)->count());
        $this->assertSame(1, Application::query()->where('status', 'accepted')->count());
    }

    public function test_period_closed_after_initial_read_is_checked_again_before_acceptance(): void
    {
        [$project, $period, $program] = $this->scope('period-closed');
        $first = $this->application($project, $period, $program);
        $second = $this->application($project, $period, $program);

        $results = $this->runWorkers(
            [[$first, 'panel'], [$second, 'waitlist']],
            fn () => $period->update(['status' => 'completed']),
        );

        $this->assertSame([423, 423], $this->statuses($results), json_encode($results));
        $this->assertDatabaseCount('participants', 0);
        $this->assertSame(0, Application::query()->where('status', 'accepted')->count());
    }

    public function test_same_user_concurrent_project_submissions_create_only_one_application(): void
    {
        [$project, $period] = $this->scope('duplicate-project');
        $project->update(['application_open' => true, 'current_period_id' => $period->id]);
        $user = $this->user();

        $results = $this->runWorkers([[$project, 'submit', $user->id], [$project, 'submit', $user->id]]);

        $this->assertSame([201, 422], $this->statuses($results), json_encode($results));
        $this->assertSame('project_id', array_key_first($results[array_search(422, array_column($results, 'status'))]['body']['errors'] ?? []));
        $this->assertDatabaseCount('applications', 1);
        $this->assertSame($user->id, Application::query()->sole()->user_id);
    }

    public function test_same_user_concurrent_program_submissions_create_only_one_application(): void
    {
        [$project, $period, $program] = $this->scope('duplicate-program');
        $project->update(['application_open' => true, 'current_period_id' => $period->id]);
        $user = $this->user();

        $results = $this->runWorkers([
            [$project, 'submit', $user->id, $program->id],
            [$project, 'submit', $user->id, $program->id],
        ]);

        $this->assertSame([201, 422], $this->statuses($results), json_encode($results));
        $this->assertDatabaseCount('applications', 1);
        $this->assertSame($program->id, Application::query()->sole()->program_id);
    }

    public function test_two_different_programs_remain_open_for_same_user(): void
    {
        [$project, $period, $program] = $this->scope('separate-programs');
        $project->update(['application_open' => true, 'current_period_id' => $period->id]);
        $other = $program->replicate();
        $other->fill(['start_at' => now()->addDays(2), 'end_at' => now()->addDays(2)->addHour()])->save();
        $user = $this->user();

        $results = $this->runWorkers([
            [$project, 'submit', $user->id, $program->id],
            [$project, 'submit', $user->id, $other->id],
        ]);

        $this->assertSame([201, 201], $this->statuses($results), json_encode($results));
        $this->assertDatabaseCount('applications', 2);
    }

    public function test_two_first_time_guest_submissions_create_one_user_and_one_application(): void
    {
        [$project, $period] = $this->scope('duplicate-guest');
        $project->update(['application_open' => true, 'current_period_id' => $period->id]);

        $code = null;
        $this->mock(NotificationService::class, function ($mock) use (&$code) {
            $mock->shouldReceive('sendEmail')->once()->andReturnUsing(function (...$arguments) use (&$code) {
                preg_match('/kodunuz: ([0-9]{8})/', (string) ($arguments[2] ?? ''), $matches);
                $code = $matches[1] ?? null;

                return 1;
            });
        });
        app(ApplicationEmailVerificationService::class)->sendCode($project, 'concurrent-guest@example.test');
        $this->assertNotNull($code);

        $results = $this->runWorkers([[$project, 'guest_submit', 0, $code], [$project, 'guest_submit', 0, $code]]);

        $this->assertSame([201, 422], $this->statuses($results), json_encode($results));
        $guest = User::query()->where('email', 'concurrent-guest@example.test')->sole();
        $this->assertSame('student', $guest->role);
        $this->assertDatabaseCount('applications', 1);
        $this->assertSame($guest->id, Application::query()->sole()->user_id);
    }

    public function test_same_verified_guest_can_submit_to_two_projects_without_duplicate_accounts(): void
    {
        [$firstProject, $firstPeriod] = $this->scope('guest-project-one');
        [$secondProject, $secondPeriod] = $this->scope('guest-project-two');
        $firstProject->update(['application_open' => true, 'current_period_id' => $firstPeriod->id]);
        $secondProject->update(['application_open' => true, 'current_period_id' => $secondPeriod->id]);

        $codes = [];
        $this->mock(NotificationService::class, function ($mock) use (&$codes) {
            $mock->shouldReceive('sendEmail')->twice()->andReturnUsing(function (...$arguments) use (&$codes) {
                preg_match('/kodunuz: ([0-9]{8})/', (string) ($arguments[2] ?? ''), $matches);
                $codes[(int) ($arguments[3] ?? 0)] = $matches[1] ?? null;

                return 1;
            });
        });
        app(ApplicationEmailVerificationService::class)->sendCode($firstProject, 'concurrent-guest@example.test');
        app(ApplicationEmailVerificationService::class)->sendCode($secondProject, 'concurrent-guest@example.test');

        $results = $this->runWorkers([
            [$firstProject, 'guest_submit', 0, $codes[$firstProject->id]],
            [$secondProject, 'guest_submit', 0, $codes[$secondProject->id]],
        ]);

        $this->assertSame([201, 201], $this->statuses($results), json_encode($results));
        $guest = User::query()->where('email', 'concurrent-guest@example.test')->sole();
        $this->assertSame(2, Application::query()->where('user_id', $guest->id)->count());
        $this->assertSame([$firstProject->id, $secondProject->id], Application::query()
            ->where('user_id', $guest->id)->orderBy('project_id')->pluck('project_id')->all());
    }

    public function test_window_closed_after_initial_read_blocks_both_submissions(): void
    {
        [$project, $period] = $this->scope('closing-intake');
        $project->update(['application_open' => true, 'current_period_id' => $period->id]);
        $user = $this->user();

        $results = $this->runWorkers(
            [[$project, 'submit', $user->id], [$project, 'submit', $user->id]],
            fn () => $project->update(['application_open' => false]),
        );

        $this->assertSame([422, 422], $this->statuses($results), json_encode($results));
        $this->assertDatabaseCount('applications', 0);
    }

    public function test_full_period_assigns_distinct_waitlist_orders_to_concurrent_submissions(): void
    {
        [$project, $period] = $this->scope('concurrent-waitlist');
        $project->update(['application_open' => true, 'current_period_id' => $period->id]);
        Participant::query()->create([
            'user_id' => $this->user()->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
            'credit' => 100,
        ]);
        $first = $this->user();
        $second = $this->user();

        $results = $this->runWorkers([[$project, 'submit', $first->id], [$project, 'submit', $second->id]]);

        $this->assertSame([201, 201], $this->statuses($results), json_encode($results));
        $this->assertSame(['waitlisted', 'waitlisted'], Application::query()->pluck('status')->sort()->values()->all());
        $this->assertSame([1, 2], Application::query()->orderBy('waitlist_order')->pluck('waitlist_order')->all());
        $this->assertDatabaseCount('applications', 2);
    }

    private function runWorkers(array $jobs, ?\Closure $beforeRelease = null): array
    {
        $admin = $this->user('super_admin');
        $directory = storage_path('framework/testing');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        $prefix = $directory.DIRECTORY_SEPARATOR.'application-concurrency-'.bin2hex(random_bytes(8));
        $barrier = $prefix.'.go';
        $paths = [];
        $processes = [];

        try {
            foreach ($jobs as $index => $job) {
                [$application, $path] = $job;
                $ready = $prefix."-{$index}.ready";
                $paths[] = $ready;
                $process = new Process([
                    PHP_BINARY, base_path('tests/Fixtures/application_acceptance_concurrency_worker.php'),
                    (string) $application->id,
                    (string) ($job[2] ?? ($path === 'waitlist' ? $application->user_id : $admin->id)),
                    $path, $ready, $barrier,
                    (string) ($job[3] ?? 0),
                ], base_path(), $this->workerEnvironment(), null, 40);
                $process->start();
                $processes[] = $process;
            }

            $deadline = microtime(true) + 20;
            while (collect($paths)->contains(fn ($path) => ! file_exists($path))) {
                foreach ($processes as $process) {
                    if (! $process->isRunning()) {
                        throw new RuntimeException('Worker erken sonlandi: '.$process->getErrorOutput().$process->getOutput());
                    }
                }
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Worker hazirlik suresi doldu.');
                }
                usleep(10_000);
            }
            $beforeRelease?->__invoke();
            touch($barrier);

            return array_map(function (Process $process): array {
                $process->wait();
                if (! $process->isSuccessful()) {
                    throw new RuntimeException($process->getErrorOutput().$process->getOutput());
                }

                return json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
            }, $processes);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach ([$barrier, ...$paths] as $path) {
                if (file_exists($path)) {
                    unlink($path);
                }
            }
        }
    }

    private function workerEnvironment(): array
    {
        $connection = config('database.connections.pgsql');

        return [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => 'null',
            'DB_HOST' => (string) $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => (string) $connection['database'],
            'DB_USERNAME' => (string) $connection['username'], 'DB_PASSWORD' => (string) $connection['password'],
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array', 'RESEND_API_KEY' => 'null', 'COORDINATION_AUTHORIZATION_MODE' => 'enforce',
            'BROADCAST_CONNECTION' => 'null', 'TELESCOPE_ENABLED' => 'false',
            'NIGHTWATCH_ENABLED' => 'false', 'PULSE_ENABLED' => 'false',
        ];
    }

    private function statuses(array $results): array
    {
        $statuses = array_column($results, 'status');
        sort($statuses);

        return $statuses;
    }

    private function user(string $role = 'student'): User
    {
        $user = User::factory()->create([
            'surname' => 'Concurrency', 'role' => $role, 'status' => 'active',
            'kvkk_consent_at' => now(), 'must_change_password' => false,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function scope(string $slug, ?int $programQuota = null): array
    {
        $project = Project::query()->create([
            'name' => $slug, 'slug' => $slug, 'type' => 'other', 'status' => 'active',
            'quota' => 1, 'has_interview' => false,
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id, 'name' => 'Test period', 'status' => 'active',
            'start_date' => now()->subMonth(), 'end_date' => now()->addMonth(),
        ]);
        $program = Program::query()->create([
            'project_id' => $project->id, 'period_id' => $period->id, 'title' => 'Test program',
            'status' => 'scheduled', 'start_at' => now()->addDay(), 'end_at' => now()->addDay()->addHour(),
            'application_quota' => $programQuota,
        ]);

        return [$project, $period, $program];
    }

    private function application(Project $project, Period $period, Program $program, ?User $user = null): Application
    {
        return Application::query()->create([
            'user_id' => ($user ?? $this->user())->id,
            'project_id' => $project->id, 'period_id' => $period->id, 'program_id' => $program->id,
            'status' => 'waitlisted', 'waitlist_invited_at' => now()->subMinute(),
            'waitlist_invitation_expires_at' => now()->addDay(),
        ]);
    }
}
