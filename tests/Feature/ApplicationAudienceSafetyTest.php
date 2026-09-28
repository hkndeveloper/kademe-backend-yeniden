<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Participant;
use App\Models\Period;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use App\Services\NotificationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApplicationAudienceSafetyTest extends TestCase
{
    use RefreshDatabase;

    private ?string $verificationCode = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->mock(NotificationService::class, function ($mock) {
            $mock->shouldReceive('sendEmail')->andReturnUsing(function (...$arguments) {
                preg_match('/kodunuz: ([0-9]{8})/', (string) ($arguments[2] ?? ''), $matches);
                $this->verificationCode = $matches[1] ?? null;

                return 1;
            })->byDefault();
            $mock->shouldReceive('sendTemplatedEmail')->andReturn(1)->byDefault();
        });
    }

    public function test_authenticated_student_and_alumni_cannot_submit_to_the_other_audience(): void
    {
        [$project, , $program] = $this->scope();
        $program->update(['target_audience' => ['student']]);
        Sanctum::actingAs($this->user('alumni'));
        $this->submit($project, $program)->assertUnprocessable()->assertJsonValidationErrors('program_id');

        $program->update(['target_audience' => ['alumni']]);
        Sanctum::actingAs($this->user('student'));
        $this->submit($project, $program)->assertUnprocessable()->assertJsonValidationErrors('program_id');

        Sanctum::actingAs($this->user('alumni'));
        $this->submit($project, $program)->assertCreated();
        $this->assertDatabaseCount('applications', 1);
    }

    public function test_hidden_internal_program_remains_available_to_authenticated_eligible_applicant_only(): void
    {
        [$project, , $program] = $this->scope();
        $program->update(['is_public' => false, 'target_audience' => ['student']]);
        $this->requestCode($project, 'guest-audience@example.test');
        $this->postJson('/api/applications/public', $this->guestPayload($project, $program))
            ->assertUnprocessable()->assertJsonValidationErrors('program_id');
        $this->assertDatabaseCount('applications', 0);

        Sanctum::actingAs($this->user('student'));
        $this->submit($project, $program)->assertCreated();
        $this->assertDatabaseCount('applications', 1);
    }

    public function test_public_program_rejects_existing_alumni_guest_on_student_only_program(): void
    {
        [$project, , $program] = $this->scope();
        $program->update(['is_public' => true, 'target_audience' => ['student']]);
        $alumni = $this->user('alumni');
        $this->requestCode($project, $alumni->email);
        $payload = $this->guestPayload($project, $program);
        $payload['applicant']['email'] = $alumni->email;

        $this->postJson('/api/applications/public', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('program_id');
        $this->assertDatabaseCount('applications', 0);
        $this->assertSame('alumni', $alumni->fresh()->role);
    }

    public function test_public_program_still_accepts_an_eligible_verified_guest(): void
    {
        [$project, , $program] = $this->scope();
        $program->update(['is_public' => true]);
        $this->requestCode($project, 'guest-audience@example.test');

        $this->postJson('/api/applications/public', $this->guestPayload($project, $program))
            ->assertCreated()->assertJsonPath('application.program_id', $program->id);
        $this->assertDatabaseCount('applications', 1);
    }

    public function test_private_project_cannot_issue_public_code_but_authenticated_application_stays_open(): void
    {
        [$project] = $this->scope();
        $project->update(['is_public' => false]);
        $this->postJson('/api/applications/public/verification', [
            'project_id' => $project->id,
            'email' => 'private-project@example.test',
        ])->assertUnprocessable()->assertJsonValidationErrors('project_id');

        Sanctum::actingAs($this->user('student'));
        $this->postJson('/api/applications', [
            'project_id' => $project->id,
            'consent_accepted' => true,
        ])->assertCreated();
    }

    public function test_new_project_submission_only_blocks_an_overlapping_active_project_period(): void
    {
        [$project, , $program] = $this->scope();
        $otherProject = Project::query()->create([
            'name' => 'Other project', 'slug' => 'other-period-scope', 'type' => 'other', 'status' => 'active',
        ]);
        $oldPeriod = Period::query()->create([
            'project_id' => $otherProject->id, 'name' => 'Past', 'status' => 'completed',
            'start_date' => now()->subMonths(4), 'end_date' => now()->subMonths(3),
        ]);
        $priorStudent = $this->user('student');
        Participant::query()->create([
            'user_id' => $priorStudent->id, 'project_id' => $otherProject->id,
            'period_id' => $oldPeriod->id, 'status' => 'active', 'credit' => 100,
        ]);
        Sanctum::actingAs($priorStudent);
        $this->submit($project, $program)->assertCreated();

        $overlapPeriod = Period::query()->create([
            'project_id' => $otherProject->id, 'name' => 'Overlapping', 'status' => 'active',
            'start_date' => now()->subWeek(), 'end_date' => now()->addWeek(),
        ]);
        $overlapStudent = $this->user('student');
        Participant::query()->create([
            'user_id' => $overlapStudent->id, 'project_id' => $otherProject->id,
            'period_id' => $overlapPeriod->id, 'status' => 'active', 'credit' => 100,
        ]);
        Sanctum::actingAs($overlapStudent);
        $this->submit($project, $program)->assertUnprocessable()->assertJsonValidationErrors('project_id');
        $this->assertDatabaseCount('applications', 1);
    }

    public function test_admin_acceptance_rechecks_audience_and_program_scope_without_changing_pending_decision(): void
    {
        [$project, $period, $program] = $this->scope();
        $student = $this->user('student');
        $application = $this->application($student, $project, $period, $program);
        Sanctum::actingAs($this->user('super_admin'));

        $program->update(['target_audience' => ['alumni']]);
        $this->putJson("/api/panel/applications/{$application->id}/status", ['status' => 'accepted'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('pending', $application->fresh()->status);
        $this->assertDatabaseCount('participants', 0);

        $program->update(['target_audience' => ['student'], 'status' => 'cancelled']);
        $this->putJson("/api/panel/applications/{$application->id}/status", ['status' => 'accepted'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        $otherPeriod = Period::query()->create([
            'project_id' => $project->id, 'name' => 'Other period', 'status' => 'planned',
            'start_date' => now()->addMonth(), 'end_date' => now()->addMonths(2),
        ]);
        $program->update(['status' => 'scheduled', 'period_id' => $otherPeriod->id]);
        $this->putJson("/api/panel/applications/{$application->id}/status", ['status' => 'accepted'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        $program->update(['period_id' => $period->id]);
        $this->putJson("/api/panel/applications/{$application->id}/status", ['status' => 'accepted'])
            ->assertOk()->assertJsonPath('application.status', 'accepted');
    }

    public function test_waitlist_acceptance_rechecks_audience_and_keeps_the_invitation(): void
    {
        [$project, $period, $program] = $this->scope();
        $alumni = $this->user('alumni');
        $application = $this->application($alumni, $project, $period, $program, 'waitlisted');
        $application->update([
            'waitlist_invited_at' => now(),
            'waitlist_invitation_expires_at' => now()->addDay(),
            'waitlist_invitation_delivery_status' => 'sent',
        ]);
        Sanctum::actingAs($alumni);

        $this->postJson("/api/applications/{$application->id}/waitlist-response", ['decision' => 'accept'])
            ->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->assertSame('waitlisted', $application->fresh()->status);
        $this->assertNotNull($application->fresh()->waitlist_invited_at);

        $program->update(['target_audience' => ['alumni']]);
        $this->postJson("/api/applications/{$application->id}/waitlist-response", ['decision' => 'accept'])
            ->assertOk()->assertJsonPath('application.status', 'accepted');
    }

    public function test_target_change_reports_existing_acceptance_without_revoking_it(): void
    {
        [$project, $period, $program] = $this->scope();
        $alumni = $this->user('alumni');
        $program->update(['target_audience' => ['alumni']]);
        $application = $this->application($alumni, $project, $period, $program);
        Sanctum::actingAs($this->user('super_admin'));
        $this->putJson("/api/panel/applications/{$application->id}/status", ['status' => 'accepted'])
            ->assertOk();
        $this->assertDatabaseHas('participants', ['user_id' => $alumni->id, 'project_id' => $project->id]);

        $program->update(['target_audience' => ['student']]);
        $this->getJson("/api/panel/programs/{$program->id}/application-conflicts")
            ->assertOk()
            ->assertJsonCount(1, 'audience_mismatches')
            ->assertJsonPath('audience_mismatches.0.id', $application->id)
            ->assertJsonPath('audience_mismatches.0.status', 'accepted');
        $this->assertSame('accepted', $application->fresh()->status);
        $this->assertSame(1, Participant::query()->where('user_id', $alumni->id)->count());

        Sanctum::actingAs($alumni);
        $this->getJson("/api/panel/programs/{$program->id}/application-conflicts")->assertForbidden();
    }

    private function scope(): array
    {
        $project = Project::query()->create([
            'name' => 'Audience', 'slug' => 'audience', 'type' => 'other',
            'status' => 'active', 'application_open' => true, 'is_public' => true,
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id, 'name' => 'Current', 'status' => 'active',
            'start_date' => now()->subMonth(), 'end_date' => now()->addMonth(),
        ]);
        $project->update(['current_period_id' => $period->id]);
        $program = Program::query()->create([
            'project_id' => $project->id, 'period_id' => $period->id,
            'title' => 'Audience program', 'status' => 'scheduled',
            'start_at' => now()->addWeek(), 'end_at' => now()->addWeek()->addHour(),
            'is_public' => false, 'target_audience' => ['student'],
        ]);

        return [$project, $period, $program];
    }

    private function user(string $role): User
    {
        $user = User::factory()->create([
            'surname' => 'Audience', 'role' => $role, 'status' => 'active',
            'kvkk_consent_at' => now(), 'must_change_password' => false,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function application(User $user, Project $project, Period $period, Program $program, string $status = 'pending'): Application
    {
        return Application::query()->create([
            'user_id' => $user->id, 'project_id' => $project->id,
            'period_id' => $period->id, 'program_id' => $program->id,
            'status' => $status,
        ]);
    }

    private function submit(Project $project, Program $program)
    {
        return $this->postJson('/api/applications', [
            'project_id' => $project->id, 'program_id' => $program->id,
            'consent_accepted' => true,
        ]);
    }

    private function requestCode(Project $project, string $email): void
    {
        $this->verificationCode = null;
        $this->postJson('/api/applications/public/verification', [
            'project_id' => $project->id,
            'email' => $email,
        ])->assertOk();
        $this->assertMatchesRegularExpression('/^[0-9]{8}$/', (string) $this->verificationCode);
    }

    private function guestPayload(Project $project, Program $program): array
    {
        return [
            'project_id' => $project->id,
            'program_id' => $program->id,
            'consent_accepted' => true,
            'verification_code' => $this->verificationCode,
            'applicant' => [
                'name' => 'Guest', 'surname' => 'Audience',
                'email' => 'guest-audience@example.test',
            ],
        ];
    }
}
