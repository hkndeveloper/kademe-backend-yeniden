<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Period;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\IstanbulDateTime;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApplicationScheduleConflictTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->mock(NotificationService::class, function ($mock) {
            $mock->shouldReceive('sendTemplatedEmail')->andReturn(1)->byDefault();
        });
    }

    public static function reservingStatuses(): array
    {
        return [
            'pending' => ['pending'],
            'waitlisted' => ['waitlisted'],
            'interview planned' => ['interview_planned'],
            'interview passed' => ['interview_passed'],
            'accepted' => ['accepted'],
        ];
    }

    #[DataProvider('reservingStatuses')]
    public function test_submission_rejects_overlap_with_every_reserving_status(string $status): void
    {
        [$project, $period, $first, $second] = $this->scope();
        $candidate = $this->user();
        $this->application($candidate, $project, $period, $first, $status);
        Sanctum::actingAs($candidate);

        $this->postJson('/api/applications', [
            'project_id' => $project->id,
            'program_id' => $second->id,
            'consent_accepted' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('program_id')
            ->assertSee('10.10.2026 10:00');

        $this->assertDatabaseCount('applications', 1);
    }

    public function test_failed_rejected_cancelled_and_completed_programs_do_not_reserve_time(): void
    {
        foreach ([['interview_failed', 'scheduled'], ['rejected', 'scheduled'], ['accepted', 'cancelled'], ['accepted', 'completed']] as [$applicationStatus, $programStatus]) {
            [$project, $period, $first, $second] = $this->scope(uniqid('scope-', true));
            $candidate = $this->user();
            $first->update(['status' => $programStatus]);
            $this->application($candidate, $project, $period, $first, $applicationStatus);
            Sanctum::actingAs($candidate);

            $this->postJson('/api/applications', [
                'project_id' => $project->id,
                'program_id' => $second->id,
                'consent_accepted' => true,
            ])->assertCreated();
        }

        $this->assertDatabaseCount('applications', 8);
    }

    public function test_admin_acceptance_rechecks_current_program_times_without_changing_the_pending_decision(): void
    {
        [$project, $period, $first, $second] = $this->scope();
        $candidate = $this->user();
        $this->application($candidate, $project, $period, $first, 'accepted');
        $pending = $this->application($candidate, $project, $period, $second, 'pending');
        Sanctum::actingAs($this->user('super_admin'));

        $this->putJson("/api/panel/applications/{$pending->id}/status", ['status' => 'accepted'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('pending', $pending->fresh()->status);

        $first->update(['status' => 'cancelled']);
        $this->putJson("/api/panel/applications/{$pending->id}/status", ['status' => 'accepted'])
            ->assertOk()->assertJsonPath('application.status', 'accepted');
    }

    public function test_waitlist_acceptance_rechecks_current_program_times_and_keeps_invitation_open(): void
    {
        [$project, $period, $first, $second] = $this->scope();
        $candidate = $this->user();
        $this->application($candidate, $project, $period, $first, 'accepted');
        $waitlisted = $this->application($candidate, $project, $period, $second, 'waitlisted');
        $waitlisted->update([
            'waitlist_invited_at' => now(),
            'waitlist_invitation_expires_at' => now()->addDay(),
            'waitlist_invitation_delivery_status' => 'sent',
        ]);
        Sanctum::actingAs($candidate);

        $this->postJson("/api/applications/{$waitlisted->id}/waitlist-response", ['decision' => 'accept'])
            ->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->assertSame('waitlisted', $waitlisted->fresh()->status);
        $this->assertNotNull($waitlisted->fresh()->waitlist_invited_at);

        $first->update(['status' => 'cancelled']);
        $this->postJson("/api/applications/{$waitlisted->id}/waitlist-response", ['decision' => 'accept'])
            ->assertOk()->assertJsonPath('application.status', 'accepted');
    }

    public function test_scoped_program_report_lists_only_affected_applicants_without_disclosing_other_programs(): void
    {
        [$project, $period, $first, $second] = $this->scope();
        $second->update([
            'start_at' => IstanbulDateTime::toUtc('2026-10-10 13:00:00'),
            'end_at' => IstanbulDateTime::toUtc('2026-10-10 14:00:00'),
        ]);
        $candidate = $this->user();
        $firstApplication = $this->application($candidate, $project, $period, $first, 'accepted');
        $this->application($candidate, $project, $period, $second, 'pending');
        Sanctum::actingAs($this->user('super_admin'));

        $this->getJson("/api/panel/programs/{$first->id}/application-conflicts")
            ->assertOk()->assertJsonCount(0, 'applications');

        $this->putJson("/api/panel/programs/{$second->id}", [
            'start_at' => '2026-10-10 11:00:00',
            'end_at' => '2026-10-10 13:00:00',
        ])->assertOk();

        $this->getJson("/api/panel/programs/{$first->id}/application-conflicts")
            ->assertOk()->assertJsonCount(1, 'applications')
            ->assertJsonPath('applications.0.id', $firstApplication->id)
            ->assertJsonPath('applications.0.candidate', $candidate->name.' '.$candidate->surname)
            ->assertDontSee($second->title);

        $this->putJson("/api/panel/programs/{$second->id}", [
            'start_at' => '2026-10-10 13:00:00',
            'end_at' => '2026-10-10 14:00:00',
        ])->assertOk();
        $this->getJson("/api/panel/programs/{$first->id}/application-conflicts")
            ->assertOk()->assertJsonCount(0, 'applications');

        Sanctum::actingAs($candidate);
        $this->getJson("/api/panel/programs/{$first->id}/application-conflicts")->assertForbidden();
    }

    private function scope(string $suffix = ''): array
    {
        $project = Project::query()->create([
            'name' => 'Schedule '.$suffix,
            'slug' => 'schedule-'.str_replace('.', '-', $suffix ?: 'main'),
            'type' => 'other',
            'status' => 'active',
            'application_open' => true,
            'has_interview' => false,
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026',
            'status' => 'active',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
        ]);
        $project->update(['current_period_id' => $period->id]);
        $first = $this->program($project, $period, 'Birinci Program', '2026-10-10 10:00:00', '2026-10-10 12:00:00');
        $second = $this->program($project, $period, 'Ikinci Program', '2026-10-10 11:00:00', '2026-10-10 13:00:00');

        return [$project, $period, $first, $second];
    }

    private function program(Project $project, Period $period, string $title, string $start, string $end): Program
    {
        return Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => $title,
            'status' => 'scheduled',
            'start_at' => IstanbulDateTime::toUtc($start),
            'end_at' => IstanbulDateTime::toUtc($end),
        ]);
    }

    private function user(string $role = 'student'): User
    {
        $user = User::factory()->create(['surname' => 'Schedule', 'role' => $role, 'status' => 'active', 'kvkk_consent_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    private function application(User $user, Project $project, Period $period, Program $program, string $status): Application
    {
        return Application::query()->create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'program_id' => $program->id,
            'status' => $status,
        ]);
    }
}
