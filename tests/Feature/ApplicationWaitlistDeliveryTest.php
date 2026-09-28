<?php

namespace Tests\Feature;

use App\Models\Application;
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
use App\Support\IstanbulDateTime;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApplicationWaitlistDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->startOfSecond());
    }

    public function test_failed_invitation_does_not_start_the_clock_and_retry_sends_only_its_email(): void
    {
        [$application, $admin] = $this->application();
        $this->mock(NotificationService::class)->shouldReceive('sendTemplatedEmail')->once()->andReturn(0);
        Sanctum::actingAs($admin);

        $this->postJson("/api/panel/applications/{$application->id}/waitlist-invite")
            ->assertOk()
            ->assertJsonPath('invitation_email_sent', false)
            ->assertJsonPath('application.waitlist_invitation_delivery_status', 'failed');

        $failed = $application->fresh();
        $this->assertNotNull($failed->waitlist_invited_at);
        $this->assertNull($failed->waitlist_invitation_expires_at);
        $this->assertSame(3 * 86400, $failed->waitlist_invitation_response_seconds);

        Sanctum::actingAs($application->user);
        $this->getJson('/api/applications')
            ->assertOk()->assertJsonPath('applications.0.waitlist_invitation_active', false);
        $this->postJson("/api/applications/{$application->id}/waitlist-response", ['decision' => 'accept'])
            ->assertUnprocessable();
        $this->assertDatabaseCount('participants', 0);

        $this->travel(1)->days();
        $this->assertSame(0, app(WaitlistService::class)->expireOverdueInvitations($application));
        $this->assertNull($application->fresh()->waitlist_invitation_expires_at);

        $this->mock(NotificationService::class)->shouldReceive('sendTemplatedEmail')->once()->andReturn(1);
        Sanctum::actingAs($admin);
        $this->postJson("/api/panel/applications/{$application->id}/waitlist-invite-retry")
            ->assertOk()->assertJsonPath('invitation_email_sent', true)
            ->assertJsonPath('application.waitlist_invitation_delivery_status', 'sent');

        $sent = $application->fresh();
        $this->assertTrue($sent->waitlist_invitation_expires_at->between(now()->addDays(3)->subSeconds(2), now()->addDays(3)->addSeconds(2)));
        $this->postJson("/api/panel/applications/{$application->id}/waitlist-invite-retry")
            ->assertUnprocessable();

        Sanctum::actingAs($application->user);
        $this->getJson('/api/applications')
            ->assertOk()->assertJsonPath('applications.0.waitlist_invitation_active', true);
    }

    public function test_specific_invitation_is_not_sent_after_capacity_fills(): void
    {
        [$application, $admin] = $this->application();
        foreach (range(1, 2) as $index) {
            Participant::query()->create([
                'user_id' => $this->user('student')->id,
                'project_id' => $application->project_id,
                'period_id' => $application->period_id,
                'status' => 'active', 'credit' => 100,
            ]);
        }
        $this->mock(NotificationService::class)->shouldReceive('sendTemplatedEmail')->never();
        Sanctum::actingAs($admin);

        $this->postJson("/api/panel/applications/{$application->id}/waitlist-invite")
            ->assertUnprocessable();
        $this->assertNull($application->fresh()->waitlist_invited_at);
    }

    public function test_existing_period_participant_can_receive_waitlist_invitation_without_using_another_place(): void
    {
        [$application, $admin] = $this->application();
        foreach ([$application->user_id, $this->user('student')->id] as $userId) {
            Participant::query()->create([
                'user_id' => $userId,
                'project_id' => $application->project_id,
                'period_id' => $application->period_id,
                'status' => 'active', 'credit' => 100,
            ]);
        }
        $this->mock(NotificationService::class)->shouldReceive('sendTemplatedEmail')->once()->andReturn(1);
        Sanctum::actingAs($admin);

        $this->postJson("/api/panel/applications/{$application->id}/waitlist-invite")
            ->assertOk()->assertJsonPath('invitation_email_sent', true);
        $this->assertDatabaseCount('participants', 2);
    }

    public function test_active_invitation_reserves_the_last_place_against_normal_acceptance(): void
    {
        [$invited, $admin] = $this->application();
        Participant::query()->create([
            'user_id' => $this->user('student')->id,
            'project_id' => $invited->project_id,
            'period_id' => $invited->period_id,
            'status' => 'active', 'credit' => 100,
        ]);
        $pending = Application::query()->create([
            'user_id' => $this->user('student')->id,
            'project_id' => $invited->project_id,
            'period_id' => $invited->period_id,
            'status' => 'pending',
        ]);
        $this->mock(NotificationService::class)->shouldReceive('sendTemplatedEmail')->andReturn(1);
        Sanctum::actingAs($admin);
        $this->postJson("/api/panel/applications/{$invited->id}/waitlist-invite")
            ->assertOk()->assertJsonPath('invitation_email_sent', true);

        $this->putJson("/api/panel/applications/{$pending->id}/status", ['status' => 'accepted'])
            ->assertUnprocessable();
        $this->assertSame('pending', $pending->fresh()->status);

        Sanctum::actingAs($invited->user);
        $this->postJson("/api/applications/{$invited->id}/waitlist-response", ['decision' => 'accept'])
            ->assertOk()->assertJsonPath('application.status', 'accepted');
        $this->assertDatabaseCount('participants', 2);
    }

    public function test_shared_period_quota_reserves_a_place_across_different_programs(): void
    {
        [$invited, $admin] = $this->application();
        $invited->project->update(['quota' => 1]);
        $firstProgram = Program::query()->create([
            'project_id' => $invited->project_id, 'period_id' => $invited->period_id,
            'title' => 'First program', 'status' => 'scheduled', 'created_by' => $admin->id,
            'start_at' => now()->addDay(), 'end_at' => now()->addDay()->addHour(),
        ]);
        $secondProgram = Program::query()->create([
            'project_id' => $invited->project_id, 'period_id' => $invited->period_id,
            'title' => 'Second program', 'status' => 'scheduled', 'created_by' => $admin->id,
            'start_at' => now()->addDays(2), 'end_at' => now()->addDays(2)->addHour(),
        ]);
        $invited->update([
            'program_id' => $firstProgram->id,
            'waitlist_invited_at' => now(),
            'waitlist_invitation_expires_at' => now()->addDay(),
            'waitlist_invitation_delivery_status' => 'sent',
        ]);
        $pending = Application::query()->create([
            'user_id' => $this->user('student')->id,
            'project_id' => $invited->project_id,
            'period_id' => $invited->period_id,
            'program_id' => $secondProgram->id,
            'status' => 'pending',
        ]);
        Sanctum::actingAs($admin);

        $this->putJson("/api/panel/applications/{$pending->id}/status", ['status' => 'accepted'])
            ->assertUnprocessable();
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertDatabaseCount('participants', 0);
    }

    public function test_expired_invitation_keeps_its_dates_and_passes_the_turn_to_next_candidate(): void
    {
        [$first, $admin] = $this->application();
        $invitedAt = now()->subDays(4);
        $expiredAt = now()->subDay();
        $first->update([
            'waitlist_invited_at' => $invitedAt,
            'waitlist_invitation_expires_at' => $expiredAt,
            'waitlist_invitation_delivery_status' => 'sent',
        ]);
        $second = Application::query()->create([
            'user_id' => $this->user('student')->id,
            'project_id' => $first->project_id,
            'period_id' => $first->period_id,
            'status' => 'waitlisted',
            'waitlist_order' => 2,
        ]);
        $this->mock(NotificationService::class)->shouldReceive('sendTemplatedEmail')->once()->andReturn(1);
        Sanctum::actingAs($admin);
        $this->postJson("/api/panel/applications/{$first->id}/waitlist-refresh")
            ->assertOk()
            ->assertJsonPath('expired_count', 1)
            ->assertJsonPath('auto_invited_application_id', $second->id);
        $this->assertSame('expired', $first->fresh()->waitlist_invitation_delivery_status);
        $this->assertTrue($first->fresh()->waitlist_invited_at->equalTo($invitedAt));
        $this->assertTrue($first->fresh()->waitlist_invitation_expires_at->equalTo($expiredAt));
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Application::class,
            'subject_id' => $first->id,
            'description' => 'waitlist_invitation_expired',
        ]);

        Sanctum::actingAs($first->user);
        $this->postJson("/api/applications/{$first->id}/waitlist-response", ['decision' => 'accept'])
            ->assertUnprocessable();
        $this->assertSame('waitlisted', $first->fresh()->status);
    }

    public function test_expired_candidate_can_only_be_reinvited_manually_with_prior_dates_logged(): void
    {
        [$application, $admin] = $this->application();
        $oldInvitedAt = now()->subDays(4);
        $oldExpiresAt = now()->subDay();
        $application->update([
            'waitlist_invited_at' => $oldInvitedAt,
            'waitlist_invitation_expires_at' => $oldExpiresAt,
            'waitlist_invitation_delivery_status' => 'sent',
        ]);
        $this->mock(NotificationService::class)->shouldReceive('sendTemplatedEmail')->once()->andReturn(1);
        $this->assertSame(1, app(WaitlistService::class)->expireOverdueInvitations($application));
        $this->assertNull(app(WaitlistService::class)->inviteNextIfSeatAvailable($application, $admin->id));

        Sanctum::actingAs($admin);
        $this->postJson("/api/panel/applications/{$application->id}/waitlist-invite")
            ->assertOk()->assertJsonPath('invitation_email_sent', true);
        $this->assertTrue($application->fresh()->waitlist_invited_at->greaterThan($oldInvitedAt));
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Application::class,
            'subject_id' => $application->id,
            'description' => 'waitlist_invitation_reissued',
        ]);
    }

    public function test_reordering_moves_the_candidate_without_duplicate_positions(): void
    {
        [$first, $admin] = $this->application();
        $second = Application::query()->create([
            'user_id' => $this->user('student')->id,
            'project_id' => $first->project_id,
            'period_id' => $first->period_id,
            'status' => 'waitlisted',
            'waitlist_order' => 2,
        ]);
        Sanctum::actingAs($admin);
        $this->putJson("/api/panel/applications/{$second->id}/waitlist-order", ['waitlist_order' => 1])
            ->assertOk()->assertJsonPath('application.waitlist_order', 1);
        $this->assertSame(2, $first->fresh()->waitlist_order);

        $this->mock(NotificationService::class)->shouldReceive('sendTemplatedEmail')->once()->andReturn(1);
        $this->assertSame($second->id, app(WaitlistService::class)->inviteNextIfSeatAvailable($second, $admin->id)?->id);
        $this->putJson("/api/panel/applications/{$first->id}/waitlist-order", ['waitlist_order' => 1])
            ->assertUnprocessable();
        $this->assertSame(2, $first->fresh()->waitlist_order);
    }

    public function test_interview_waitlist_requires_a_recorded_positive_result_before_invite_or_acceptance(): void
    {
        [$application, $admin] = $this->application();
        $application->project->update(['has_interview' => true]);
        $this->mock(NotificationService::class)->shouldReceive('sendTemplatedEmail')->andReturn(1);
        Sanctum::actingAs($admin);

        $this->postJson("/api/panel/applications/{$application->id}/waitlist-invite")
            ->assertUnprocessable();
        $this->assertNull(app(WaitlistService::class)->inviteNextIfSeatAvailable($application->fresh(), $admin->id));

        $application->update([
            'waitlist_invited_at' => now(),
            'waitlist_invitation_expires_at' => now()->addDay(),
            'waitlist_invitation_delivery_status' => 'sent',
        ]);
        Sanctum::actingAs($application->user);
        $this->postJson("/api/applications/{$application->id}/waitlist-response", ['decision' => 'accept'])
            ->assertUnprocessable();
        $this->assertDatabaseCount('participants', 0);

        $application->update([
            'waitlist_invited_at' => null,
            'waitlist_invitation_expires_at' => null,
            'waitlist_invitation_delivery_status' => null,
        ]);
        Sanctum::actingAs($admin);
        $this->putJson("/api/panel/applications/{$application->id}/interview", [
            'interview_at' => now()->addDay()->toIso8601String(),
        ])->assertOk();
        $this->putJson("/api/panel/applications/{$application->id}/status", ['status' => 'interview_passed'])
            ->assertOk();
        $this->assertNotNull($application->fresh()->interview_passed_at);
        $this->postJson("/api/panel/applications/{$application->id}/waitlist")->assertOk();
        $this->postJson("/api/panel/applications/{$application->id}/waitlist-invite")
            ->assertOk()->assertJsonPath('invitation_email_sent', true);

        Sanctum::actingAs($application->user);
        $this->postJson("/api/applications/{$application->id}/waitlist-response", ['decision' => 'accept'])
            ->assertOk()->assertJsonPath('application.status', 'accepted');
        $this->assertDatabaseCount('participants', 1);
    }

    public function test_background_waitlist_check_previews_by_default_and_only_sends_for_selected_projects(): void
    {
        [$application] = $this->application();
        $this->mock(NotificationService::class)->shouldReceive('sendTemplatedEmail')->once()->andReturn(1);

        $this->artisan('applications:advance-waitlists')->assertExitCode(0);
        $this->assertNull($application->fresh()->waitlist_invited_at);
        $this->artisan('applications:advance-waitlists --send')->assertExitCode(1);
        $this->assertNull($application->fresh()->waitlist_invited_at);

        config(['application_waitlist.auto_project_ids' => [$application->project_id]]);
        $this->artisan('applications:advance-waitlists --send')->assertExitCode(0);
        $this->assertSame('sent', $application->fresh()->waitlist_invitation_delivery_status);
        $this->artisan('applications:advance-waitlists --send')->assertExitCode(0);
        $this->assertSame('sent', $application->fresh()->waitlist_invitation_delivery_status);
    }

    public function test_invitation_email_shows_the_same_istanbul_deadline_as_the_saved_invitation(): void
    {
        config(['services.frontend.url' => 'https://kademe.example']);
        [$application, $admin] = $this->application();
        $emailData = null;
        $this->mock(NotificationService::class)
            ->shouldReceive('sendTemplatedEmail')
            ->once()
            ->andReturnUsing(function ($recipients, $subject, $view, $data) use (&$emailData) {
                $emailData = $data;

                return 1;
            });

        app(WaitlistService::class)->inviteSpecific($application, $admin->id);

        $savedDeadline = IstanbulDateTime::format($application->fresh()->waitlist_invitation_expires_at).' (Türkiye saati)';
        $this->assertSame('https://kademe.example/student/applications', $emailData['action_url']);
        $this->assertContains(['label' => 'Son yanıt tarihi', 'value' => $savedDeadline], $emailData['lines']);
        $this->assertStringContainsString($savedDeadline, $emailData['plain_text']);
        $this->assertContains(['label' => 'Dönem', 'value' => 'Current period'], $emailData['lines']);
    }

    public function test_uncertain_first_invitation_reserves_the_place_until_delivery_is_checked(): void
    {
        [$first, $admin] = $this->application();
        $second = Application::query()->create([
            'user_id' => $this->user('student')->id,
            'project_id' => $first->project_id,
            'period_id' => $first->period_id,
            'status' => 'waitlisted',
            'waitlist_order' => 2,
        ]);
        $this->mock(NotificationService::class)->shouldReceive('sendTemplatedEmail')->once()->andThrow(new \RuntimeException('Email offline'));

        $invited = app(WaitlistService::class)->inviteNextIfSeatAvailable($first, $admin->id);
        $this->assertSame($first->id, $invited?->id);
        $this->assertSame('unknown', $first->fresh()->waitlist_invitation_delivery_status);
        $this->assertNull(app(WaitlistService::class)->inviteNextIfSeatAvailable($first, $admin->id));
        $this->assertNull($second->fresh()->waitlist_invited_at);

        Sanctum::actingAs($admin);
        $this->postJson("/api/panel/applications/{$first->id}/waitlist-invite-retry")
            ->assertUnprocessable();
    }

    public function test_custom_response_duration_begins_after_successful_retry(): void
    {
        [$application, $admin] = $this->application();
        $this->mock(NotificationService::class)->shouldReceive('sendTemplatedEmail')->once()->andReturn(0);
        Sanctum::actingAs($admin);

        $this->postJson("/api/panel/applications/{$application->id}/waitlist-invite", [
            'expires_at' => now()->addHours(12)->toIso8601String(),
        ])->assertOk()->assertJsonPath('invitation_email_sent', false);
        $this->assertSame(12 * 3600, $application->fresh()->waitlist_invitation_response_seconds);

        $this->travel(1)->days();
        $this->mock(NotificationService::class)->shouldReceive('sendTemplatedEmail')->once()->andReturn(1);
        $this->postJson("/api/panel/applications/{$application->id}/waitlist-invite-retry")
            ->assertOk()->assertJsonPath('invitation_email_sent', true);

        $this->assertTrue($application->fresh()->waitlist_invitation_expires_at
            ->between(now()->addHours(12)->subSeconds(2), now()->addHours(12)->addSeconds(2)));
    }

    public function test_legacy_invitation_without_delivery_marker_remains_answerable(): void
    {
        [$application] = $this->application();
        $application->update([
            'waitlist_invited_at' => now()->subHour(),
            'waitlist_invitation_expires_at' => now()->addDay(),
        ]);
        Sanctum::actingAs($application->user);

        $this->getJson('/api/applications')
            ->assertOk()->assertJsonPath('applications.0.waitlist_invitation_active', true);
    }

    public function test_only_a_project_authorized_panel_user_can_retry_failed_delivery(): void
    {
        [$application, $admin] = $this->application();
        $application->update([
            'waitlist_invited_at' => now(),
            'waitlist_invitation_delivery_status' => 'failed',
            'waitlist_invitation_response_seconds' => 86400,
        ]);
        $this->mock(NotificationService::class)->shouldReceive('sendTemplatedEmail')->once()->andReturn(0);
        Sanctum::actingAs($this->user('student'));
        $this->postJson("/api/panel/applications/{$application->id}/waitlist-invite-retry")
            ->assertForbidden();

        Sanctum::actingAs($admin);
        $this->postJson("/api/panel/applications/{$application->id}/waitlist-invite-retry")
            ->assertOk()->assertJsonPath('invitation_email_sent', false);
        $this->assertSame('failed', $application->fresh()->waitlist_invitation_delivery_status);
        $this->assertNull($application->fresh()->waitlist_invitation_expires_at);
    }

    public function test_completed_period_does_not_retry_a_failed_invitation(): void
    {
        [$application, $admin] = $this->application();
        $application->update([
            'waitlist_invited_at' => now(),
            'waitlist_invitation_delivery_status' => 'failed',
            'waitlist_invitation_response_seconds' => 86400,
        ]);
        $application->period->update(['status' => 'completed']);
        Sanctum::actingAs($admin);

        $this->postJson("/api/panel/applications/{$application->id}/waitlist-invite-retry")
            ->assertStatus(423);
        $this->assertSame('failed', $application->fresh()->waitlist_invitation_delivery_status);
    }

    private function application(): array
    {
        $project = Project::query()->create([
            'name' => 'Waitlist Delivery', 'slug' => 'waitlist-delivery', 'type' => 'other', 'status' => 'active',
            'quota' => 2,
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id, 'name' => 'Current period', 'status' => 'active',
            'start_date' => now()->subWeek(), 'end_date' => now()->addMonth(),
        ]);
        $application = Application::query()->create([
            'user_id' => $this->user('student')->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'waitlisted',
            'waitlist_order' => 1,
        ]);

        return [$application, $this->user('super_admin')];
    }

    private function user(string $role): User
    {
        $user = User::factory()->create([
            'surname' => 'Delivery Test', 'role' => $role, 'status' => 'active', 'kvkk_consent_at' => now(), 'must_change_password' => false,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
