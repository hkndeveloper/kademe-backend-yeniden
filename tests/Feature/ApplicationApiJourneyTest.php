<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationForm;
use App\Models\Period;
use App\Models\Project;
use App\Models\ProjectTraining;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * HTTP journey tests: real controllers, verification, decisions, enrollment,
 * notifications and password broker. Only the external mail transport is faked.
 */
class ApplicationApiJourneyTest extends TestCase
{
    use RefreshDatabase;

    private array $messages = [];

    private bool $mailFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['services.resend.key' => 'test-only', 'services.resend.from' => 'noreply@example.test', 'services.frontend.url' => 'https://frontend.example.test']);
        Http::preventStrayRequests();
        Http::fake(['api.resend.com/*' => function ($request) {
            $this->messages[] = $request->data();

            return Http::response(['id' => 'isolated-test-message'], $this->mailFails ? 503 : 200);
        }]);
    }

    public static function scenarios(): array
    {
        return [
            'without interview accepted' => [false, true, false],
            'without interview rejected' => [false, false, false],
            'with successful interview accepted' => [true, true, false],
            'with unsuccessful interview rejected' => [true, false, false],
            'training accepted without interview' => [true, true, true],
        ];
    }

    private function target(bool $interview, bool $training = false): array
    {
        $project = Project::create(['name' => 'Journey', 'slug' => 'journey', 'type' => 'other', 'status' => 'active', 'is_public' => true, 'application_open' => true, 'has_interview' => $interview, 'application_scope' => $training ? 'training' : 'project']);
        $period = Period::create(['project_id' => $project->id, 'name' => '2026', 'status' => 'active', 'start_date' => now()->subDay(), 'end_date' => now()->addMonth()]);
        $project->update(['current_period_id' => $period->id]);
        $course = $training ? ProjectTraining::create(['project_id' => $project->id, 'period_id' => $period->id, 'title' => 'Liderlik', 'is_active' => true, 'application_open' => true, 'quota' => 2]) : null;
        ApplicationForm::create(['project_id' => $project->id, 'period_id' => $period->id, 'training_id' => $course?->id, 'is_active' => true, 'require_consent' => true, 'consent_text' => 'Başvuru koşullarını kabul ediyorum.', 'fields' => [['id' => 'motivation', 'type' => 'text', 'label' => 'Neden katılmak istiyorsunuz?', 'required' => true]]]);

        return [$project, $course];
    }

    private function verifiedPayload(Project $project, ?ProjectTraining $course): array
    {
        $email = 'candidate@example.test';
        $this->postJson('/api/applications/public/verification', ['project_id' => $project->id, 'email' => $email])->assertOk();
        $text = end($this->messages)['text'];
        $this->assertSame(1, preg_match('/kodunuz: ([0-9]{8})/', $text, $matches));
        $form = $this->getJson('/api/projects/'.$project->slug.'/application-form'.($course ? '?training_id='.$course->id : ''))->assertOk();

        return ['project_id' => $project->id, 'training_id' => $course?->id, 'application_form_id' => $form->json('application_form.id'), 'expected_consent_text' => $form->json('application_consent_text'), 'consent_accepted' => true, 'verification_code' => $matches[1], 'form_data' => ['motivation' => 'Kendimi geliştirmek istiyorum.'], 'applicant' => ['name' => 'Ada', 'surname' => 'Aday', 'email' => $email]];
    }

    private function adminToken(): string
    {
        $admin = User::factory()->create(['email' => 'admin@example.test', 'surname' => 'Admin', 'password' => Hash::make('AdminTest123!'), 'role' => 'super_admin', 'status' => 'active', 'must_change_password' => false, 'kvkk_consent_at' => now()]);
        $admin->assignRole('super_admin');

        return $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'AdminTest123!'])->assertOk()->json('access_token');
    }

    private function decide(int $id, string $token, array $data)
    {
        app('auth')->forgetGuards();

        return $this->putJson('/api/panel/applications/'.$id.'/status', $data, ['Authorization' => 'Bearer '.$token]);
    }

    #[DataProvider('scenarios')]
    public function test_complete_application_decision_and_account_journey(bool $interview, bool $accepted, bool $training): void
    {
        [$project, $course] = $this->target($interview, $training);
        $payload = $this->verifiedPayload($project, $course);
        $response = $this->postJson('/api/applications/public', $payload)->assertCreated()->assertJsonPath('application.user_id', null);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('participants', 0);
        $application = Application::sole();
        $this->assertSame($interview && ! $training, $application->has_interview_snapshot);
        $this->assertSame($payload['form_data'], $application->form_data);
        parse_str(parse_url($response->json('tracking_url'), PHP_URL_FRAGMENT), $fragment);
        $this->getJson('/api/applications/track/'.$application->id)->assertNotFound();
        $this->getJson('/api/applications/track/'.$application->id, ['X-Application-Token' => $fragment['token']])->assertOk()->assertJsonPath('application.status', 'pending');
        $this->postJson('/api/applications/public', $payload)->assertUnprocessable();
        $token = $this->adminToken();
        if ($interview && ! $training) {
            $this->decide($application->id, $token, ['status' => 'accepted'])->assertUnprocessable();
            $this->decide($application->id, $token, ['status' => 'interview_planned', 'interview_at' => now()->addDay()->toIso8601String()])->assertOk();
            $this->decide($application->id, $token, ['status' => 'accepted'])->assertUnprocessable();
            $this->assertDatabaseMissing('users', ['email' => $payload['applicant']['email']]);
            $this->decide($application->id, $token, ['status' => $accepted ? 'interview_passed' : 'interview_failed'])->assertOk();
            if (! $accepted) {
                $this->decide($application->id, $token, ['status' => 'accepted'])->assertUnprocessable();
            }
        }
        $status = $accepted ? 'accepted' : 'rejected';
        $decision = $this->decide($application->id, $token, ['status' => $status, 'rejection_reason' => $accepted ? null : 'Bu dönem kontenjan değerlendirmesi.', 'evaluation_note' => 'Sadece yönetici için özel not'])->assertOk();
        app('auth')->forgetGuards();
        $this->getJson('/api/applications/track/'.$application->id, ['X-Application-Token' => $fragment['token']])->assertOk()->assertJsonPath('application.status', $status)->assertJsonMissingPath('application.evaluation_note');
        $this->assertDatabaseCount('applications', 1);
        if (! $accepted) {
            $this->assertDatabaseMissing('users', ['email' => $payload['applicant']['email']]);
            $this->assertDatabaseCount('participants', 0);
            $this->assertDatabaseCount('password_reset_tokens', 0);

            return;
        }
        $decision->assertJsonPath('follow_up.password_link_sent', true);
        $user = User::where('email', $payload['applicant']['email'])->sole();
        $this->assertTrue($user->must_change_password);
        $this->assertTrue($user->hasRole('student'));
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('participants', 1);
        $this->assertDatabaseCount('training_enrollments', $training ? 1 : 0);
        $activation = collect($this->messages)->first(fn ($message) => str_contains($message['text'], '/auth/reset-password?'));
        $this->assertNotNull($activation);
        $this->assertSame(1, preg_match('~https://frontend\.example\.test/auth/reset-password\?[^\s]+~', $activation['text'], $link));
        parse_str(parse_url($link[0], PHP_URL_QUERY), $query);
        $password = ['token' => $query['token'], 'email' => $query['email'], 'password' => 'CandidateTest123!', 'password_confirmation' => 'CandidateTest123!'];
        $this->postJson('/api/auth/reset-password', $password)->assertOk();
        $this->assertFalse($user->fresh()->must_change_password);
        $this->postJson('/api/auth/reset-password', $password)->assertUnprocessable();
        $login = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => $password['password']])->assertOk();
        app('auth')->forgetGuards();
        $this->getJson('/api/auth/me', ['Authorization' => 'Bearer '.$login->json('access_token')])->assertOk()->assertJsonPath('user.email', $user->email);
    }

    public function test_invalid_code_and_incomplete_form_can_be_corrected_without_creating_an_account(): void
    {
        [$project, $course] = $this->target(false);
        $payload = $this->verifiedPayload($project, $course);
        $wrong = $payload;
        $wrong['verification_code'] = $payload['verification_code'] === '11111111' ? '22222222' : '11111111';
        $this->postJson('/api/applications/public', $wrong)->assertUnprocessable();
        $incomplete = $payload;
        $incomplete['form_data'] = [];
        $this->postJson('/api/applications/public', $incomplete)->assertUnprocessable();
        $this->assertDatabaseCount('application_candidates', 0);
        $this->assertDatabaseCount('users', 0);
        $this->postJson('/api/applications/public', $payload)->assertCreated();
        $this->assertDatabaseCount('applications', 1);
    }

    public function test_failed_mail_transport_cannot_issue_a_usable_verification_challenge(): void
    {
        [$project] = $this->target(false);
        $this->mailFails = true;
        $this->postJson('/api/applications/public/verification', ['project_id' => $project->id, 'email' => 'candidate@example.test'])->assertStatus(503);
        $this->assertDatabaseCount('application_email_verifications', 0);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('applications', 0);
    }
}
