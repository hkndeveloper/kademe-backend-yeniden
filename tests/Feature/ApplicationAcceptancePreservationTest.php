<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationWindow;
use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitMembership;
use App\Models\CoordinationUnitPermissionRule;
use App\Models\Participant;
use App\Models\Period;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\WaitlistService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApplicationAcceptancePreservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->mock(NotificationService::class, function ($mock) {
            $mock->shouldReceive('sendEmail')->andReturn(1);
            $mock->shouldReceive('sendTemplatedEmail')->andReturn(1);
        });
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->startOfSecond());
    }

    public static function acceptancePaths(): array
    {
        return ['panel' => [false], 'waitlist' => [true]];
    }

    public static function newParticipantCases(): array
    {
        return [
            'panel with custom starting credit' => [false, 135],
            'waitlist with custom starting credit' => [true, 135],
            'panel with zero starting credit' => [false, 0],
            'waitlist with zero starting credit' => [true, 0],
        ];
    }

    public static function roleCases(): array
    {
        $cases = [];
        foreach (['student', 'alumni', 'staff', 'coordinator', 'super_admin'] as $role) {
            $cases["panel preserves {$role}"] = [false, $role];
            $cases["waitlist preserves {$role}"] = [true, $role];
        }

        return $cases;
    }

    public function test_committed_acceptance_is_reported_even_when_both_emails_fail(): void
    {
        $application = $this->application(false);
        $this->mock(NotificationService::class, function ($mock) {
            $mock->shouldReceive('sendTemplatedEmail')->andThrow(new \RuntimeException('Mail transport unavailable'));
        });
        Password::shouldReceive('sendResetLink')->once()->andThrow(new \RuntimeException('Password mail unavailable'));
        Sanctum::actingAs($this->user('super_admin'));

        $this->putJson("/api/panel/applications/{$application->id}/status", ['status' => 'accepted'])
            ->assertOk()
            ->assertJsonPath('application.status', 'accepted')
            ->assertJsonPath('follow_up.status_email_sent', false)
            ->assertJsonPath('follow_up.password_link_sent', false);

        $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'accepted']);
        $this->assertDatabaseCount('participants', 1);
        $this->putJson("/api/panel/applications/{$application->id}/status", ['status' => 'accepted'])
            ->assertUnprocessable();
        $this->assertDatabaseCount('participants', 1);
    }

    public function test_committed_interview_plan_is_reported_when_status_email_fails(): void
    {
        $application = $this->application(false);
        $application->project->update(['has_interview' => true]);
        $this->mock(NotificationService::class, function ($mock) {
            $mock->shouldReceive('sendTemplatedEmail')->andReturn(0);
        });
        Sanctum::actingAs($this->user('super_admin'));

        $this->putJson("/api/panel/applications/{$application->id}/interview", [
            'interview_at' => now()->addDay()->toIso8601String(),
        ])
            ->assertOk()
            ->assertJsonPath('application.status', 'interview_planned')
            ->assertJsonPath('follow_up.status_email_sent', false);

        $this->assertSame('interview_planned', $application->fresh()->status);
    }

    public function test_waitlist_acceptance_remains_committed_when_confirmation_email_fails(): void
    {
        $application = $this->application(true);
        $this->mock(NotificationService::class, function ($mock) {
            $mock->shouldReceive('sendTemplatedEmail')->andThrow(new \RuntimeException('Mail transport unavailable'));
        });
        Sanctum::actingAs($application->user);

        $this->postJson("/api/applications/{$application->id}/waitlist-response", ['decision' => 'accept'])
            ->assertOk()
            ->assertJsonPath('application.status', 'accepted')
            ->assertJsonPath('follow_up.applicant_email_sent', false);

        $this->assertDatabaseCount('participants', 1);
        $this->postJson("/api/applications/{$application->id}/waitlist-response", ['decision' => 'accept'])
            ->assertUnprocessable();
        $this->assertDatabaseCount('participants', 1);
    }

    public function test_waitlist_rejection_remains_committed_when_next_check_and_email_fail(): void
    {
        $application = $this->application(true);
        $this->mock(WaitlistService::class, function ($mock) {
            $mock->shouldReceive('inviteNextIfSeatAvailable')->once()->andThrow(new \RuntimeException('Invitation check unavailable'));
        });
        $this->mock(NotificationService::class, function ($mock) {
            $mock->shouldReceive('sendTemplatedEmail')->andReturn(0);
        });
        Sanctum::actingAs($application->user);

        $this->postJson("/api/applications/{$application->id}/waitlist-response", ['decision' => 'reject'])
            ->assertOk()
            ->assertJsonPath('application.status', 'rejected')
            ->assertJsonPath('follow_up.applicant_email_sent', false)
            ->assertJsonPath('follow_up.next_waitlist_checked', false);

        $this->assertSame('rejected', $application->fresh()->status);
        $this->assertDatabaseCount('participants', 0);
    }

    public function test_waitlist_response_notifies_the_current_unit_coordinator(): void
    {
        config(['coordination_authorization.mode' => 'enforce']);
        $application = $this->application(true);
        $coordinator = $this->user('coordinator');
        $unit = CoordinationUnit::query()->create([
            'code' => 'project_'.$application->project_id,
            'name' => 'Başvuru projesi',
            'kind' => 'project',
            'project_id' => $application->project_id,
            'status' => 'active',
        ]);
        CoordinationUnitMembership::query()->create([
            'unit_id' => $unit->id,
            'user_id' => $coordinator->id,
            'position' => 'coordinator',
            'status' => 'active',
        ]);
        CoordinationUnitPermissionRule::query()->create([
            'unit_id' => $unit->id,
            'position' => 'coordinator',
            'permission_name' => 'applications.view',
            'effect' => 'allow',
            'scope_source' => 'linked_project',
            'status' => 'active',
        ]);
        $sentTo = [];
        $this->mock(NotificationService::class)
            ->shouldReceive('sendTemplatedEmail')
            ->twice()
            ->andReturnUsing(function ($recipients) use (&$sentTo) {
                $sentTo[] = $recipients;

                return 1;
            });
        Sanctum::actingAs($application->user);

        $this->postJson("/api/applications/{$application->id}/waitlist-response", ['decision' => 'reject'])
            ->assertOk()
            ->assertJsonPath('follow_up.coordinators_email_sent', true);
        $this->assertSame([$coordinator->email], $sentTo[1]);
    }

    public function test_notification_retry_sends_only_the_current_status_without_repeating_acceptance(): void
    {
        $application = $this->application(false);
        $this->mock(NotificationService::class, function ($mock) {
            $mock->shouldReceive('sendTemplatedEmail')->once()->andReturn(1);
        });
        $application->update(['status' => 'accepted']);
        $participant = $this->participant($application, $application->user);
        $before = $participant->fresh()->getAttributes();
        Sanctum::actingAs($this->user('super_admin'));

        $this->postJson("/api/panel/applications/{$application->id}/notification-retry", ['type' => 'status'])
            ->assertOk()
            ->assertJsonPath('notification_type', 'status')
            ->assertJsonPath('sent', true);

        $this->assertSame($before, $participant->fresh()->getAttributes());
        $this->assertDatabaseCount('participants', 1);
        $this->assertSame('accepted', $application->fresh()->status);
    }

    public function test_legacy_visitor_with_an_administrative_assignment_is_not_reclassified(): void
    {
        $application = $this->application(false, 'visitor');
        $user = $application->user;
        $user->assignRole('coordinator');
        $user->update(['status' => 'passive']);

        $this->accept($application, false);

        $this->assertSame('visitor', $user->fresh()->role);
        $this->assertSame('passive', $user->fresh()->status);
        $this->assertTrue($user->fresh()->hasRole('coordinator'));
        $this->assertDatabaseCount('participants', 1);
    }

    public function test_password_notification_retry_requires_acceptance_and_project_access(): void
    {
        $application = $this->application(false);
        Sanctum::actingAs($this->user('super_admin'));

        $this->postJson("/api/panel/applications/{$application->id}/notification-retry", ['type' => 'password'])
            ->assertUnprocessable();
        $this->postJson("/api/panel/applications/{$application->id}/notification-retry", ['type' => 'status'])
            ->assertUnprocessable();

        $application->update(['status' => 'accepted']);
        Password::shouldReceive('sendResetLink')->once()->andReturn(Password::RESET_LINK_SENT);
        $this->postJson("/api/panel/applications/{$application->id}/notification-retry", ['type' => 'password'])
            ->assertOk()->assertJsonPath('sent', true);
        $this->assertDatabaseCount('participants', 0);

        Sanctum::actingAs($this->user('student'));
        $this->postJson("/api/panel/applications/{$application->id}/notification-retry", ['type' => 'status'])
            ->assertForbidden();
    }

    #[DataProvider('newParticipantCases')]
    public function test_new_participant_receives_the_selected_period_starting_credit(bool $waitlist, int $credit): void
    {
        $application = $this->application($waitlist, 'student', $credit);

        $this->accept($application, $waitlist);

        $participant = Participant::query()->sole();
        $this->assertSame($application->user_id, $participant->user_id);
        $this->assertSame($application->project_id, $participant->project_id);
        $this->assertSame($application->period_id, $participant->period_id);
        $this->assertSame('active', $participant->status);
        $this->assertSame($credit, (int) $participant->credit);
        $this->assertTrue($participant->enrolled_at->equalTo(now()));
        $this->assertNotNull($application->user->fresh()->profile);
    }

    #[DataProvider('acceptancePaths')]
    public function test_another_program_acceptance_keeps_existing_participant_and_credit_history(bool $waitlist): void
    {
        $application = $this->application($waitlist);
        $participant = Participant::query()->create([
            'user_id' => $application->user_id,
            'project_id' => $application->project_id,
            'period_id' => $application->period_id,
            'status' => 'active',
            'credit' => 70,
            'enrolled_at' => now()->subWeeks(3),
        ]);
        $participant->creditLogs()->create([
            'user_id' => $application->user_id,
            'project_id' => $application->project_id,
            'period_id' => $application->period_id,
            'amount' => -30,
            'type' => 'manual_adjust',
            'reason' => 'Existing adjustment',
        ]);
        $before = $participant->fresh()->getAttributes();
        $creditHistory = $participant->creditLogs()->get()->toArray();

        $this->accept($application, $waitlist);

        $this->assertDatabaseCount('participants', 1);
        $this->assertSame($before, $participant->fresh()->getAttributes());
        $this->assertSame($creditHistory, $participant->creditLogs()->get()->toArray());
    }

    #[DataProvider('acceptancePaths')]
    public function test_acceptance_does_not_rewrite_a_previous_graduation_record(bool $waitlist): void
    {
        $application = $this->application($waitlist, 'alumni');
        $participant = Participant::query()->create([
            'user_id' => $application->user_id,
            'project_id' => $application->project_id,
            'period_id' => $application->period_id,
            'status' => 'graduated',
            'credit' => 85,
            'enrolled_at' => now()->subMonths(4),
            'graduated_at' => now()->subWeek(),
            'graduation_status' => 'graduated',
            'graduation_note' => 'Existing graduation decision',
        ]);
        $before = $participant->fresh()->getAttributes();

        $this->accept($application, $waitlist);

        $this->assertSame($before, $participant->fresh()->getAttributes());
    }

    #[DataProvider('roleCases')]
    public function test_acceptance_preserves_account_role_and_assigned_roles(bool $waitlist, string $role): void
    {
        $application = $this->application($waitlist, $role);
        $user = $application->user;
        Role::findOrCreate('application-reviewer', 'web');
        $user->assignRole('application-reviewer');
        $rolesBefore = $user->getRoleNames()->sort()->values()->all();
        $user->profile()->create(['motivation_message' => 'Existing profile']);
        $profileBefore = $user->profile()->firstOrFail()->getAttributes();

        $this->accept($application, $waitlist);

        $this->assertSame($role, $user->fresh()->role);
        $this->assertSame($rolesBefore, $user->fresh()->getRoleNames()->sort()->values()->all());
        $this->assertSame($profileBefore, $user->profile()->firstOrFail()->getAttributes());
    }

    public function test_panel_acceptance_rejects_an_account_restricted_after_application(): void
    {
        $application = $this->application(false);
        $application->user->update(['status' => 'blacklisted', 'blacklisted_until' => now()->addMonth()]);
        $before = $application->user->fresh()->only(['status', 'blacklisted_until']);

        $this->acceptResponse($application, false)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('application');

        $this->assertEquals($before, $application->user->fresh()->only(['status', 'blacklisted_until']));
        $this->assertSame('pending', $application->fresh()->status);
        $this->assertDatabaseCount('participants', 0);
    }

    #[DataProvider('acceptancePaths')]
    public function test_acceptance_in_a_completed_period_does_not_create_participation(bool $waitlist): void
    {
        $application = $this->application($waitlist);
        $application->period->update(['status' => 'completed']);

        $this->acceptResponse($application, $waitlist)->assertStatus(423);

        $this->assertDatabaseCount('participants', 0);
        $this->assertSame($waitlist ? 'waitlisted' : 'pending', $application->fresh()->status);
    }

    #[DataProvider('acceptancePaths')]
    public function test_new_period_participation_does_not_change_previous_period_history(bool $waitlist): void
    {
        $application = $this->application($waitlist, 'alumni', 120);
        $previousPeriod = Period::query()->create([
            'project_id' => $application->project_id,
            'name' => 'Previous period',
            'start_date' => now()->subYear(),
            'end_date' => now()->subMonths(6),
            'status' => 'completed',
        ]);
        $previous = Participant::query()->create([
            'user_id' => $application->user_id,
            'project_id' => $application->project_id,
            'period_id' => $previousPeriod->id,
            'status' => 'graduated',
            'graduation_status' => 'graduated',
            'credit' => 90,
            'enrolled_at' => now()->subYear(),
            'graduated_at' => now()->subMonths(6),
        ]);
        $before = $previous->fresh()->getAttributes();

        $this->accept($application, $waitlist);

        $this->assertDatabaseCount('participants', 2);
        $this->assertSame($before, $previous->fresh()->getAttributes());
        $this->assertDatabaseHas('participants', [
            'user_id' => $application->user_id,
            'period_id' => $application->period_id,
            'status' => 'active',
            'credit' => 120,
        ]);
    }

    #[DataProvider('acceptancePaths')]
    public function test_period_quota_counts_participants_even_without_an_accepted_application(bool $waitlist): void
    {
        $application = $this->application($waitlist);
        $application->project->update(['quota' => 20]);
        $window = ApplicationWindow::query()->create([
            'project_id' => $application->project_id,
            'period_id' => $application->period_id,
            'quota' => 1,
            'has_interview' => false,
        ]);
        $application->update(['application_window_id' => $window->id]);
        $this->participant($application, $this->user('student'));

        $this->assertFalse(app(WaitlistService::class)->hasAvailableSeat($application->fresh()));

        $this->acceptResponse($application, $waitlist)->assertUnprocessable()
            ->assertJsonValidationErrors($waitlist ? 'decision' : 'status');

        $this->assertSame($waitlist ? 'waitlisted' : 'pending', $application->fresh()->status);
        $this->assertDatabaseCount('participants', 1);

        // Changing the existing setting should immediately allow the next acceptance.
        $window->update(['quota' => 2]);
        $this->assertTrue(app(\App\Services\ApplicationCapacityService::class)
            ->hasAvailableSeat($application->fresh(), forApplicant: true));
        $this->accept($application, $waitlist);
        $this->assertDatabaseCount('participants', 2);
    }

    #[DataProvider('acceptancePaths')]
    public function test_an_existing_active_participant_can_join_another_program_at_full_period_quota(bool $waitlist): void
    {
        $application = $this->application($waitlist);
        $application->project->update(['quota' => 1]);
        $participant = $this->participant($application, $application->user);
        $before = $participant->fresh()->getAttributes();

        $this->accept($application, $waitlist);

        $this->assertDatabaseCount('participants', 1);
        $this->assertSame($before, $participant->fresh()->getAttributes());
    }

    #[DataProvider('acceptancePaths')]
    public function test_explicit_program_quota_is_independent_but_cannot_be_exceeded(bool $waitlist): void
    {
        $application = $this->application($waitlist);
        $application->project->update(['quota' => 1]);
        $application->program->update(['application_quota' => 1]);
        $other = $this->user('student');
        $this->participant($application, $other);
        $occupied = Application::query()->create([
            'user_id' => $other->id,
            'project_id' => $application->project_id,
            'period_id' => $application->period_id,
            'program_id' => $application->program_id,
            'status' => 'accepted',
        ]);

        $this->acceptResponse($application, $waitlist)->assertUnprocessable();
        $this->assertDatabaseCount('participants', 1);

        // A participant from another program does not consume this explicit quota.
        $occupied->update(['program_id' => null]);
        $this->accept($application, $waitlist);
        $this->assertDatabaseCount('participants', 2);
    }

    #[DataProvider('acceptancePaths')]
    public function test_explicit_zero_program_quota_keeps_the_existing_unlimited_behavior(bool $waitlist): void
    {
        $application = $this->application($waitlist);
        $application->project->update(['quota' => 1]);
        $application->program->update(['application_quota' => 0]);
        $this->participant($application, $this->user('student'));

        $this->accept($application, $waitlist);

        $this->assertDatabaseCount('participants', 2);
    }

    #[DataProvider('acceptancePaths')]
    public function test_existing_single_active_project_rule_is_preserved(bool $waitlist): void
    {
        $application = $this->application($waitlist);
        $otherProject = Project::query()->create([
            'name' => 'Other project', 'slug' => 'other-project', 'type' => 'other', 'status' => 'active',
        ]);
        $otherPeriod = Period::query()->create([
            'project_id' => $otherProject->id, 'name' => 'Other period', 'status' => 'active',
            'start_date' => now()->subMonth(), 'end_date' => now()->addMonth(),
        ]);
        Participant::query()->create([
            'user_id' => $application->user_id,
            'project_id' => $otherProject->id,
            'period_id' => $otherPeriod->id,
            'status' => 'active',
            'credit' => 65,
        ]);

        $this->acceptResponse($application, $waitlist)->assertUnprocessable()
            ->assertJsonValidationErrors($waitlist ? 'decision' : 'status');

        $this->assertSame($waitlist ? 'waitlisted' : 'pending', $application->fresh()->status);
        $this->assertDatabaseCount('participants', 1);
    }

    #[DataProvider('acceptancePaths')]
    public function test_non_overlapping_prior_project_does_not_block_acceptance(bool $waitlist): void
    {
        $application = $this->application($waitlist);
        $otherProject = Project::query()->create([
            'name' => 'Past project', 'slug' => 'past-project', 'type' => 'other', 'status' => 'active',
        ]);
        $oldPeriod = Period::query()->create([
            'project_id' => $otherProject->id, 'name' => 'Past period', 'status' => 'completed',
            'start_date' => now()->subMonths(4), 'end_date' => now()->subMonths(3),
        ]);
        Participant::query()->create([
            'user_id' => $application->user_id, 'project_id' => $otherProject->id,
            'period_id' => $oldPeriod->id, 'status' => 'active', 'credit' => 65,
        ]);

        $this->acceptResponse($application, $waitlist)->assertOk()
            ->assertJsonPath('application.status', 'accepted');
        $this->assertDatabaseCount('participants', 2);
    }

    public function test_expired_invitation_cannot_be_accepted_and_expiry_cleanup_is_kept(): void
    {
        $application = $this->application(true);
        $application->update(['waitlist_invitation_expires_at' => now()->subSecond()]);

        $this->acceptResponse($application, true)->assertUnprocessable()->assertJsonValidationErrors('application');

        $this->assertSame('waitlisted', $application->fresh()->status);
        $this->assertSame('expired', $application->fresh()->waitlist_invitation_delivery_status);
        $this->assertNotNull($application->fresh()->waitlist_invited_at);
        $this->assertDatabaseCount('participants', 0);
    }

    #[DataProvider('acceptancePaths')]
    public function test_repeated_acceptance_is_rejected_without_duplicate_participation(bool $waitlist): void
    {
        $application = $this->application($waitlist);
        $this->accept($application, $waitlist);
        $before = Participant::query()->sole()->getAttributes();

        $this->acceptResponse($application, $waitlist)->assertUnprocessable();

        $this->assertDatabaseCount('participants', 1);
        $this->assertSame($before, Participant::query()->sole()->getAttributes());
    }

    public function test_panel_interview_flow_still_requires_a_positive_interview_before_acceptance(): void
    {
        $application = $this->application(false);
        $application->project->update(['has_interview' => true]);
        Sanctum::actingAs($this->user('super_admin'));

        $this->putJson("/api/panel/applications/{$application->id}/status", ['status' => 'accepted'])
            ->assertUnprocessable();
        $this->putJson("/api/panel/applications/{$application->id}/interview", [
            'interview_at' => now()->addDay()->toIso8601String(),
        ])->assertOk()->assertJsonPath('application.status', 'interview_planned');
        $this->putJson("/api/panel/applications/{$application->id}/status", ['status' => 'interview_passed'])
            ->assertOk()->assertJsonPath('application.status', 'interview_passed');
        $this->accept($application, false);

        $this->assertDatabaseCount('participants', 1);
    }

    public function test_rejecting_an_invitation_keeps_the_existing_next_candidate_behavior(): void
    {
        $application = $this->application(true);
        $next = $application->replicate();
        $next->fill([
            'user_id' => $this->user('student')->id,
            'waitlist_order' => 2,
            'waitlist_invited_at' => null,
            'waitlist_invitation_expires_at' => null,
        ])->save();
        Sanctum::actingAs($application->user);

        $this->postJson("/api/applications/{$application->id}/waitlist-response", ['decision' => 'reject'])
            ->assertOk()->assertJsonPath('application.status', 'rejected');

        $this->assertNull($application->fresh()->waitlist_invited_at);
        $this->assertNotNull($next->fresh()->waitlist_invited_at);
        $this->assertDatabaseCount('participants', 0);
    }

    private function participant(Application $application, User $user): Participant
    {
        return Participant::query()->create([
            'user_id' => $user->id,
            'project_id' => $application->project_id,
            'period_id' => $application->period_id,
            'status' => 'active',
            'credit' => 75,
            'enrolled_at' => now()->subWeek(),
        ]);
    }

    private function accept(Application $application, bool $waitlist): void
    {
        $this->acceptResponse($application, $waitlist)->assertOk()->assertJsonPath('application.status', 'accepted');
        $this->assertSame('accepted', $application->fresh()->status);
    }

    private function acceptResponse(Application $application, bool $waitlist)
    {
        if ($waitlist) {
            Sanctum::actingAs($application->user);

            return $this->postJson("/api/applications/{$application->id}/waitlist-response", ['decision' => 'accept']);
        }

        Sanctum::actingAs($this->user('super_admin'));

        return $this->putJson("/api/panel/applications/{$application->id}/status", ['status' => 'accepted']);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create([
            'surname' => 'Acceptance Test',
            'role' => $role,
            'status' => 'active',
            'kvkk_consent_at' => now(),
            'must_change_password' => false,
        ]);
        Role::findOrCreate($role, 'web');
        $user->assignRole($role);

        return $user;
    }

    private function application(bool $waitlist, string $role = 'student', int $credit = 100): Application
    {
        $project = Project::query()->create([
            'name' => 'Acceptance preservation',
            'slug' => 'acceptance-preservation',
            'type' => 'other',
            'status' => 'active',
            'has_interview' => false,
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => 'Current period',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'status' => 'active',
            'credit_start_amount' => $credit,
        ]);
        $program = Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Additional program',
            'start_at' => now()->addWeek(),
            'end_at' => now()->addWeek()->addHour(),
            'status' => 'scheduled',
            'target_audience' => $role === 'alumni' ? ['alumni'] : ['student'],
        ]);

        return Application::query()->create([
            'user_id' => $this->user($role)->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'program_id' => $program->id,
            'status' => $waitlist ? 'waitlisted' : 'pending',
            'waitlist_order' => $waitlist ? 1 : null,
            'waitlist_invited_at' => $waitlist ? now()->subMinute() : null,
            'waitlist_invitation_expires_at' => $waitlist ? now()->addDay() : null,
        ]);
    }
}
