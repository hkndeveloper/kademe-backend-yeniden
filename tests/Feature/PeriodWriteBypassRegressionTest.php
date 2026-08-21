<?php

namespace Tests\Feature;

use App\Jobs\CreditDeductionJob;
use App\Jobs\RotateQrTokenJob;
use App\Models\Application;
use App\Models\Participant;
use App\Models\Period;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\CreditService;
use App\Services\GoogleCalendarService;
use App\Services\QrCodeService;
use App\Services\WaitlistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PeriodWriteBypassRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_period_cannot_be_mutated_through_direct_service_calls(): void
    {
        $project = $this->project('completed-service-bypass');
        $period = $this->period($project, 'completed');
        $student = User::factory()->create(['surname' => 'Service', 'role' => 'student']);
        $participant = $this->participant($student, $project, $period, 80);
        $program = $this->program($project, $period, [
            'qr_token' => 'completed-period-token',
            'qr_expires_at' => now()->addMinute(),
        ]);
        $application = Application::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'waitlisted',
        ]);

        $this->assertLocked(fn () => app(CreditService::class)->reward(
            $participant,
            10,
            'Controller disi kredi denemesi.',
        ));
        $this->assertLocked(fn () => app(QrCodeService::class)->generateForProgram($program));
        $this->assertLocked(fn () => app(GoogleCalendarService::class)->syncProgram($program));
        $this->assertLocked(fn () => app(WaitlistService::class)->inviteSpecific($application));
        $this->assertLocked(fn () => app(AttendanceService::class)->markQrAttendance(
            $student,
            'completed-period-token',
            null,
            null,
        ));

        $this->assertSame(80, (int) $participant->fresh()->credit);
        $this->assertSame('completed-period-token', $program->fresh()->qr_token);
        $this->assertNull($application->fresh()->waitlist_invited_at);
        $this->assertDatabaseCount('credit_logs', 0);
        $this->assertDatabaseCount('calendar_events', 0);
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_background_jobs_do_not_mutate_completed_period_programs(): void
    {
        $project = $this->project('completed-job-bypass');
        $period = $this->period($project, 'completed');
        $student = User::factory()->create(['surname' => 'Job', 'role' => 'student']);
        $participant = $this->participant($student, $project, $period, 80);
        $program = $this->program($project, $period, [
            'qr_token' => null,
            'qr_expires_at' => null,
        ]);

        $this->assertLocked(fn () => (new CreditDeductionJob($program))->handle(app(CreditService::class)));
        (new RotateQrTokenJob())->handle(app(QrCodeService::class));

        $this->assertSame(80, (int) $participant->fresh()->credit);
        $this->assertNull($program->fresh()->qr_token);
        $this->assertDatabaseCount('credit_logs', 0);
    }

    public function test_credit_reset_command_skips_archived_period_but_processes_current_active_period(): void
    {
        $student = User::factory()->create(['surname' => 'Command', 'role' => 'student']);

        $archivedProject = $this->project('completed-command-bypass');
        $completedPeriod = $this->period($archivedProject, 'completed');
        $completedParticipant = $this->participant($student, $archivedProject, $completedPeriod, 45);

        $this->artisan('kademe:reset-period-credits', [
            '--period' => $completedPeriod->id,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame(45, (int) $completedParticipant->fresh()->credit);
        $this->assertDatabaseCount('credit_logs', 0);

        $activeProject = $this->project('active-command-control');
        $activePeriod = $this->period($activeProject, 'active');
        $activeProject->forceFill(['current_period_id' => $activePeriod->id])->save();
        $activeParticipant = $this->participant($student, $activeProject, $activePeriod, 55);

        $this->artisan('kademe:reset-period-credits', [
            '--period' => $activePeriod->id,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame(100, (int) $activeParticipant->fresh()->credit);
        $this->assertDatabaseHas('credit_logs', [
            'participant_id' => $activeParticipant->id,
            'period_id' => $activePeriod->id,
            'type' => 'period_reset',
            'amount' => 45,
        ]);
    }

    private function assertLocked(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Tamamlanmis donemde controller disi yazma islemi kilitlenmeliydi.');
        } catch (HttpException $exception) {
            $this->assertSame(423, $exception->getStatusCode());
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
            'name' => strtoupper($status).' Donemi',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'credit_start_amount' => 100,
            'credit_threshold' => 75,
            'status' => $status,
        ]);
    }

    private function participant(
        User $user,
        Project $project,
        Period $period,
        int $credit,
    ): Participant {
        return Participant::query()->create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
            'credit' => $credit,
            'enrolled_at' => now(),
        ]);
    }

    private function program(Project $project, Period $period, array $overrides = []): Program
    {
        return Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Bypass Programi',
            'description' => 'Controller disi yazma regresyonu.',
            'start_at' => now()->subMinute(),
            'end_at' => now()->addHour(),
            'credit_deduction' => 10,
            'target_audience' => ['student'],
            'status' => 'active',
            ...$overrides,
        ]);
    }
}
