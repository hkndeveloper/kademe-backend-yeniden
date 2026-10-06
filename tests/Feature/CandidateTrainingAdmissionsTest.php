<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCandidate;
use App\Models\ApplicationEmailVerification;
use App\Models\Period;
use App\Models\Program;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\ProjectTraining;
use App\Models\User;
use App\Services\ApplicationTrackingService;
use App\Services\NotificationService;
use App\Services\TrainingAccessService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CandidateTrainingAdmissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->seed(RolePermissionSeeder::class);
        $this->mock(NotificationService::class, fn ($mock) => $mock->shouldReceive('sendTemplatedEmail')->andReturn(1)->byDefault());
        Password::shouldReceive('sendResetLink')->andReturn(Password::RESET_LINK_SENT)->byDefault();
    }

    private function project(string $scope = 'project', bool $interview = false): Project
    {
        $project = Project::create(['name' => 'Admissions', 'slug' => 'admissions-'.uniqid(), 'type' => 'other', 'status' => 'active', 'is_public' => true, 'application_open' => true, 'has_interview' => $interview, 'application_scope' => $scope]);
        $period = Period::create(['project_id' => $project->id, 'name' => '2026', 'status' => 'active', 'start_date' => now()->subDay(), 'end_date' => now()->addMonth()]);
        $project->forceFill(['current_period_id' => $period->id])->save();

        return $project;
    }

    private function submit(Project $project, ?ProjectTraining $training = null, string $email = 'candidate@example.test')
    {
        ApplicationEmailVerification::updateOrCreate(['project_id' => $project->id, 'email' => $email], ['code_hash' => hash_hmac('sha256', '12345678', (string) config('app.key')), 'expires_at' => now()->addMinutes(10), 'attempts' => 0, 'consumed_at' => null]);

        return $this->postJson('/api/applications/public', ['project_id' => $project->id, 'training_id' => $training?->id, 'consent_accepted' => true, 'verification_code' => '12345678', 'applicant' => ['name' => 'Ada', 'surname' => 'Aday', 'email' => $email, 'phone' => '05550000000']]);
    }

    private function administrator(): User
    {
        $user = User::factory()->create(['surname' => 'Admin', 'role' => 'super_admin', 'status' => 'active', 'must_change_password' => false]);
        $user->assignRole('super_admin');
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_submission_does_not_create_a_login_account_and_tracking_is_private(): void
    {
        $project = $this->project();
        $response = $this->submit($project)->assertCreated()->assertJsonPath('application.user_id', null);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('application_candidates', 1);
        $application = Application::sole();
        $url = $response->json('tracking_url');
        parse_str(parse_url($url, PHP_URL_FRAGMENT), $fragment);
        $this->getJson('/api/applications/track/'.$application->id)->assertNotFound();
        $this->getJson('/api/applications/track/'.$application->id, ['X-Application-Token' => $fragment['token']])->assertOk()->assertJsonPath('application.status', 'pending')->assertJsonMissingPath('application.evaluation_note');
        $application->update(['tracking_expires_at' => now()->subSecond()]);
        $this->getJson('/api/applications/track/'.$application->id, ['X-Application-Token' => $fragment['token']])->assertNotFound();
    }

    public function test_project_application_is_once_per_period_and_registration_is_disabled(): void
    {
        $project = $this->project();
        $this->submit($project)->assertCreated();
        $this->submit($project)->assertUnprocessable();
        $this->postJson('/api/auth/register', [])->assertForbidden();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('applications', 1);
    }

    public function test_acceptance_creates_one_account_and_only_the_selected_training_is_enrolled(): void
    {
        $project = $this->project('training', true);
        $first = ProjectTraining::create(['project_id' => $project->id, 'period_id' => $project->current_period_id, 'title' => 'Liderlik', 'is_active' => true, 'application_open' => true, 'quota' => 1]);
        $second = ProjectTraining::create(['project_id' => $project->id, 'period_id' => $project->current_period_id, 'title' => 'Dijital', 'is_active' => true, 'application_open' => true]);
        $this->submit($project, $first)->assertCreated()->assertJsonPath('application.has_interview_snapshot', false);
        $this->submit($project, $second)->assertCreated();
        $this->submit($project, $first)->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
        $admin = $this->administrator();
        $applications = Application::orderBy('id')->get();
        $this->putJson('/api/panel/applications/'.$applications[0]->id.'/status', ['status' => 'accepted'])->assertOk();
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('training_enrollments', 1);
        $this->assertDatabaseMissing('training_enrollments', ['training_id' => $second->id]);
        $this->putJson('/api/panel/applications/'.$applications[1]->id.'/status', ['status' => 'accepted'])->assertOk()->assertJsonPath('follow_up.password_link_sent', false);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('participants', 1);
        $this->assertDatabaseCount('training_enrollments', 2);
        $this->assertNotEquals($admin->id, ApplicationCandidate::sole()->user_id);
    }

    public function test_positive_interview_is_required_and_snapshot_does_not_change_with_project_settings(): void
    {
        $project = $this->project('project', true);
        $this->submit($project)->assertCreated();
        $application = Application::sole();
        $project->update(['has_interview' => false]);
        $this->administrator();
        $this->putJson('/api/panel/applications/'.$application->id.'/status', ['status' => 'accepted'])->assertUnprocessable();
        $this->putJson('/api/panel/applications/'.$application->id.'/status', ['status' => 'interview_planned', 'interview_at' => now()->addDay()->toIso8601String()])->assertOk();
        $this->assertDatabaseCount('users', 1);
        $this->putJson('/api/panel/applications/'.$application->id.'/status', ['status' => 'interview_passed'])->assertOk();
        $this->putJson('/api/panel/applications/'.$application->id.'/status', ['status' => 'accepted'])->assertOk();
        $this->assertDatabaseCount('users', 2);
    }

    public function test_closed_and_wrong_training_targets_are_rejected_without_creating_accounts(): void
    {
        $project = $this->project('training');
        $training = ProjectTraining::create(['project_id' => $project->id, 'period_id' => $project->current_period_id, 'title' => 'Closed', 'application_open' => false]);
        $this->submit($project)->assertUnprocessable();
        $this->submit($project, $training)->assertUnprocessable();
        $this->getJson('/api/application-targets')->assertOk()->assertJsonCount(0, 'targets');
        $training->update(['application_open' => true]);
        $this->getJson('/api/application-targets')->assertOk()->assertJsonCount(1, 'targets');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_panel_can_search_and_display_an_applicant_without_a_user_account(): void
    {
        $project = $this->project();
        $this->submit($project)->assertCreated();
        $this->administrator();
        $this->getJson('/api/panel/applications?search=candidate@example.test')->assertOk()->assertJsonPath('applications.data.0.user.email', 'candidate@example.test');
    }

    public function test_failed_acceptance_does_not_create_an_account_or_enrollment(): void
    {
        $project = $this->project('training');
        $training = ProjectTraining::create(['project_id' => $project->id, 'period_id' => $project->current_period_id, 'title' => 'Full', 'application_open' => true, 'quota' => 0]);
        $this->submit($project, $training)->assertCreated();
        $this->administrator();
        $this->putJson('/api/panel/applications/'.Application::sole()->id.'/status', ['status' => 'accepted'])->assertUnprocessable();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('participants', 0);
        $this->assertNull(ApplicationCandidate::sole()->user_id);
    }

    public function test_guest_waitlist_acceptance_creates_an_account_and_sends_activation_only_after_acceptance(): void
    {
        $project = $this->project();
        $this->submit($project)->assertCreated();
        $application = Application::sole();
        $application->update(['status' => 'waitlisted', 'waitlist_order' => 1, 'waitlist_invited_at' => now(), 'waitlist_invitation_expires_at' => now()->addDay(), 'waitlist_invitation_delivery_status' => 'sent']);
        parse_str(parse_url(app(ApplicationTrackingService::class)->issue($application), PHP_URL_FRAGMENT), $fragment);
        $this->assertDatabaseCount('users', 0);
        $this->postJson('/api/applications/track/'.$application->id.'/waitlist-response', ['decision' => 'accept'], ['X-Application-Token' => $fragment['token']])
            ->assertOk()->assertJsonPath('follow_up.activation_link_sent', true);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('participants', 1);
    }

    public function test_accepted_training_does_not_unlock_other_training_sessions(): void
    {
        $project = $this->project('training');
        $first = ProjectTraining::create(['project_id' => $project->id, 'period_id' => $project->current_period_id, 'title' => 'First', 'application_open' => true]);
        $second = ProjectTraining::create(['project_id' => $project->id, 'period_id' => $project->current_period_id, 'title' => 'Second', 'application_open' => true]);
        $this->submit($project, $first)->assertCreated();
        $this->administrator();
        $this->putJson('/api/panel/applications/'.Application::sole()->id.'/status', ['status' => 'accepted'])->assertOk();
        $user = Application::sole()->user;
        $access = app(TrainingAccessService::class);
        foreach ([$first, $second] as $training) {
            $module = ProjectModule::create(['project_id' => $project->id, 'period_id' => $project->current_period_id, 'training_id' => $training->id, 'title' => $training->title, 'is_active' => true]);
            $program = Program::create(['project_id' => $project->id, 'period_id' => $project->current_period_id, 'project_module_id' => $module->id, 'title' => 'Session', 'type' => 'workshop', 'status' => 'scheduled', 'start_at' => now()->addDay(), 'end_at' => now()->addDay()->addHour()]);
            $this->assertSame($training->id === $first->id, $access->canAccessProgram($user, $program));
        }
    }

    public function test_open_targets_follow_training_availability_and_scope(): void
    {
        $first = $this->project();
        $this->getJson('/api/application-targets')->assertJsonCount(1, 'targets');
        $second = $this->project();
        $this->getJson('/api/application-targets')->assertJsonCount(2, 'targets');
        $second->update(['application_open' => false]);
        $first->update(['application_scope' => 'training']);
        $this->getJson('/api/application-targets')->assertJsonCount(0, 'targets');
        $this->getJson('/api/projects/'.$first->slug)->assertJsonPath('project.is_application_open', false);
    }
}
