<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCandidate;
use App\Models\ApplicationEmailVerification;
use App\Models\ApplicationForm;
use App\Models\ApplicationWindow;
use App\Models\CommunicationLog;
use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitMembership;
use App\Models\CoordinationUnitPermissionRule;
use App\Models\Period;
use App\Models\Program;
use App\Models\Project;
use App\Models\ProjectTraining;
use App\Models\User;
use App\Services\ApplicationEmailVerificationService;
use App\Services\ApplicationNotificationRecipientService;
use App\Services\NotificationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApplicationSubmissionSafetyTest extends TestCase
{
    use RefreshDatabase;

    private ?string $sentVerificationCode = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['filesystems.media_disk' => 'public']);
        Storage::fake('public');
        Storage::fake('application_private');
        $this->seed(RolePermissionSeeder::class);
        $this->mock(NotificationService::class, function ($mock) {
            $mock->shouldReceive('sendTemplatedEmail')->andReturn(1)->byDefault();
            $mock->shouldReceive('sendEmail')->andReturnUsing(function (...$arguments) {
                if (preg_match('/kodunuz: ([0-9]{8})/', (string) ($arguments[2] ?? ''), $matches)) {
                    $this->sentVerificationCode = $matches[1];
                }

                return 1;
            })->byDefault();
        });
    }

    public static function applicationScopes(): array
    {
        return ['project application' => [false], 'training application' => [true]];
    }

    public function test_authenticated_application_remains_created_when_receipt_email_throws(): void
    {
        [$project] = $this->scope();
        Sanctum::actingAs($this->user());
        $this->mock(NotificationService::class)->shouldReceive('sendTemplatedEmail')->once()
            ->andThrow(new \RuntimeException('Mail transport unavailable'));

        $this->postJson('/api/applications', ['project_id' => $project->id, 'consent_accepted' => true])
            ->assertCreated()
            ->assertJsonPath('follow_up.applicant_email_sent', false);
        $this->assertDatabaseCount('applications', 1);

        $this->postJson('/api/applications', ['project_id' => $project->id, 'consent_accepted' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('project_id');
        $this->assertDatabaseCount('applications', 1);
    }

    public function test_guest_application_remains_created_when_receipt_email_throws(): void
    {
        [$project] = $this->scope();
        $payload = $this->guestPayload($project);
        $payload['verification_code'] = $this->requestGuestCode($project, $payload['applicant']['email']);
        $this->mock(NotificationService::class)->shouldReceive('sendTemplatedEmail')->once()
            ->andThrow(new \RuntimeException('Mail transport unavailable'));

        $this->postJson('/api/applications/public', $payload)
            ->assertCreated()
            ->assertJsonPath('follow_up.applicant_email_sent', false);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('application_candidates', 1);
        $this->assertDatabaseCount('applications', 1);
    }

    public function test_coordinator_receipt_failure_does_not_hide_successful_applicant_receipt(): void
    {
        [$project] = $this->scope();
        $project->coordinators()->attach($this->user('coordinator'));
        Sanctum::actingAs($this->user());
        $attempts = 0;
        $this->mock(NotificationService::class)->shouldReceive('sendTemplatedEmail')->twice()
            ->andReturnUsing(function () use (&$attempts) {
                if (++$attempts === 2) {
                    throw new \RuntimeException('Coordinator email failed');
                }

                return 1;
            });

        $this->postJson('/api/applications', ['project_id' => $project->id, 'consent_accepted' => true])
            ->assertCreated()
            ->assertJsonPath('follow_up.applicant_email_sent', true)
            ->assertJsonPath('follow_up.coordinators_email_sent', false);
        $this->assertDatabaseCount('applications', 1);
    }

    public function test_new_unit_coordinator_receives_project_application_without_legacy_assignment(): void
    {
        config(['coordination_authorization.mode' => 'enforce']);
        [$project] = $this->scope();
        $coordinator = $this->user('coordinator');
        $unit = CoordinationUnit::query()->create([
            'code' => 'project_'.$project->id,
            'name' => $project->name,
            'kind' => 'project',
            'project_id' => $project->id,
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
        Sanctum::actingAs($this->user());

        $this->postJson('/api/applications', ['project_id' => $project->id, 'consent_accepted' => true])
            ->assertCreated()
            ->assertJsonPath('follow_up.coordinators_email_sent', true);
        $this->assertSame([$coordinator->email], $sentTo[1]);
    }

    public function test_recipient_lookup_failure_does_not_undo_the_application_or_applicant_email(): void
    {
        [$project] = $this->scope();
        $this->mock(ApplicationNotificationRecipientService::class)
            ->shouldReceive('coordinatorEmailsFor')
            ->once()
            ->andThrow(new \RuntimeException('Recipient lookup unavailable'));
        Sanctum::actingAs($this->user());

        $this->postJson('/api/applications', ['project_id' => $project->id, 'consent_accepted' => true])
            ->assertCreated()
            ->assertJsonPath('follow_up.applicant_email_sent', true)
            ->assertJsonPath('follow_up.coordinators_email_sent', false);
        $this->assertDatabaseCount('applications', 1);
    }

    #[DataProvider('applicationScopes')]
    public function test_repeat_submission_keeps_existing_application_in_every_status(bool $trainingScoped): void
    {
        [$project, $period, $program] = $this->scope();
        Sanctum::actingAs($this->user());
        $training = null;
        if ($trainingScoped) {
            $project->update(['application_scope' => 'training']);
            $training = ProjectTraining::create(['project_id' => $project->id, 'period_id' => $period->id, 'title' => 'Training', 'is_active' => true, 'application_open' => true]);
        }
        $payload = ['project_id' => $project->id, 'training_id' => $training?->id, 'consent_accepted' => true, 'form_data' => ['answer' => 'original']];
        $this->mock(NotificationService::class)->shouldReceive('sendTemplatedEmail')->once()->andReturn(1);
        $id = $this->postJson('/api/applications', $payload)->assertCreated()->json('application.id');

        foreach (['pending', 'accepted', 'rejected', 'waitlisted', 'interview_planned', 'interview_passed', 'interview_failed'] as $status) {
            $application = Application::query()->findOrFail($id);
            $application->update(['status' => $status, 'evaluation_note' => 'Existing decision']);
            $before = $application->fresh()->getAttributes();
            $payload['form_data']['answer'] = 'replacement';

            $this->postJson('/api/applications', $payload)->assertUnprocessable()->assertJsonValidationErrors('project_id');

            $this->assertSame($before, $application->fresh()->getAttributes());
            $this->assertDatabaseCount('applications', 1);
        }
    }

    public function test_new_period_application_preserves_the_previous_period_application(): void
    {
        [$project, $period] = $this->scope();
        Sanctum::actingAs($this->user());
        $id = $this->postJson('/api/applications', ['project_id' => $project->id, 'consent_accepted' => true])->assertCreated()->json('application.id');
        $before = Application::query()->findOrFail($id)->getAttributes();
        $period->update(['status' => 'completed']);
        $nextPeriod = Period::query()->create([
            'project_id' => $project->id, 'name' => 'Next period', 'status' => 'active',
            'start_date' => now(), 'end_date' => now()->addMonth(),
        ]);
        $project->update(['current_period_id' => $nextPeriod->id]);

        $this->postJson('/api/applications', ['project_id' => $project->id, 'consent_accepted' => true])
            ->assertCreated()->assertJsonPath('application.period_id', $nextPeriod->id);

        $this->assertDatabaseCount('applications', 2);
        $this->assertSame($before, Application::query()->findOrFail($id)->getAttributes());
    }

    public function test_sessions_cannot_create_additional_applications_after_a_project_application(): void
    {
        [$project, $period, $program] = $this->scope();
        $other = $program->replicate();
        $other->fill(['start_at' => now()->addDays(2), 'end_at' => now()->addDays(2)->addHour()])->save();
        Sanctum::actingAs($this->user());

        $this->postJson('/api/applications', ['project_id' => $project->id, 'consent_accepted' => true])->assertCreated();
        foreach ([$program->id, $other->id] as $programId) {
            $this->postJson('/api/applications', ['project_id' => $project->id, 'program_id' => $programId, 'consent_accepted' => true])->assertUnprocessable()->assertJsonValidationErrors('program_id');
        }

        $this->assertDatabaseCount('applications', 1);
    }

    public function test_sessions_are_not_public_admission_targets_even_without_an_existing_application(): void
    {
        [$project, , $program] = $this->scope();
        $other = $program->replicate();
        $other->title = 'Parallel Program';
        $other->save();
        Sanctum::actingAs($this->user());

        $this->postJson('/api/applications', [
            'project_id' => $project->id,
            'program_id' => $program->id,
            'consent_accepted' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('program_id');

        $this->postJson('/api/applications', [
            'project_id' => $project->id,
            'program_id' => $other->id,
            'consent_accepted' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('program_id');

        $this->assertDatabaseCount('applications', 0);
    }

    public function test_repeated_guest_submission_creates_one_candidate_and_no_login_account(): void
    {
        [$project] = $this->scope();
        $payload = $this->guestPayload($project);
        $payload['verification_code'] = $this->requestGuestCode($project, $payload['applicant']['email']);
        $this->postJson('/api/applications/public', $payload)->assertCreated();
        $payload['applicant']['name'] = 'Replacement name';
        $this->postJson('/api/applications/public', $payload)->assertUnprocessable()->assertJsonValidationErrors('verification_code');

        $candidate = ApplicationCandidate::query()->sole();
        $this->assertSame('Guest', $candidate->name);
        $this->assertSame('guest@example.test', $candidate->email);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('applications', 1);
    }

    public function test_existing_guest_matched_account_is_not_overwritten_or_given_student_role(): void
    {
        [$project] = $this->scope();
        $user = $this->user('coordinator');
        $before = $user->fresh()->getAttributes();
        $payload = $this->guestPayload($project);
        $payload['applicant']['email'] = $user->email;
        $payload['verification_code'] = $this->requestGuestCode($project, $user->email);

        $this->postJson('/api/applications/public', $payload)->assertCreated();

        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertSame(['coordinator'], $user->fresh()->getRoleNames()->all());
        $this->assertDatabaseCount('users', 1);
    }

    public function test_guest_cannot_create_an_account_or_application_without_email_code(): void
    {
        [$project] = $this->scope();
        $payload = $this->guestPayload($project);

        $this->postJson('/api/applications/public', $payload)->assertUnprocessable()->assertJsonValidationErrors('verification_code');
        $payload['verification_code'] = '00000000';
        $this->postJson('/api/applications/public', $payload)->assertUnprocessable()->assertJsonValidationErrors('verification_code');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('applications', 0);
    }

    public function test_guest_code_is_scoped_to_the_email_and_expires(): void
    {
        [$project] = $this->scope();
        $payload = $this->guestPayload($project);
        $payload['verification_code'] = $this->requestGuestCode($project, $payload['applicant']['email']);
        $this->assertNotSame($payload['verification_code'], ApplicationEmailVerification::query()->sole()->code_hash);

        $payload['applicant']['email'] = 'other@example.test';
        $this->postJson('/api/applications/public', $payload)->assertUnprocessable()->assertJsonValidationErrors('verification_code');
        $payload['applicant']['email'] = 'guest@example.test';
        $otherProject = Project::query()->create([
            'name' => 'Other project', 'slug' => 'other-project', 'type' => 'other',
            'status' => 'active', 'application_open' => true,
        ]);
        $otherPeriod = Period::query()->create([
            'project_id' => $otherProject->id, 'name' => 'Other period', 'status' => 'active',
            'start_date' => now()->subMonth(), 'end_date' => now()->addMonth(),
        ]);
        $otherProject->update(['current_period_id' => $otherPeriod->id]);
        $payload['project_id'] = $otherProject->id;
        $this->postJson('/api/applications/public', $payload)->assertUnprocessable()->assertJsonValidationErrors('verification_code');
        $payload['project_id'] = $project->id;
        ApplicationEmailVerification::query()->sole()->update(['expires_at' => now()->subSecond()]);
        $this->postJson('/api/applications/public', $payload)->assertUnprocessable()->assertJsonValidationErrors('verification_code');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('applications', 0);
    }

    public function test_five_wrong_codes_exhaust_the_challenge_without_creating_an_account(): void
    {
        [$project] = $this->scope();
        $payload = $this->guestPayload($project);
        $this->requestGuestCode($project, $payload['applicant']['email']);
        $payload['verification_code'] = '00000000';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/applications/public', $payload)->assertUnprocessable();
        }
        $this->assertSame(5, ApplicationEmailVerification::query()->sole()->attempts);
        $payload['verification_code'] = $this->sentVerificationCode;
        $this->postJson('/api/applications/public', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_failed_code_delivery_does_not_leave_a_usable_challenge(): void
    {
        [$project] = $this->scope();
        $this->mock(NotificationService::class)
            ->shouldReceive('sendEmail')->once()->andReturn(0);

        $this->postJson('/api/applications/public/verification', [
            'project_id' => $project->id,
            'email' => 'guest@example.test',
        ])->assertStatus(503);

        $this->assertDatabaseCount('application_email_verifications', 0);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('applications', 0);
    }

    public function test_verification_code_is_redacted_from_communication_log(): void
    {
        [$project] = $this->scope();
        config(['services.resend.key' => 'test-only', 'services.resend.from' => 'noreply@example.test']);
        Http::fake(['api.resend.com/*' => Http::response(['id' => 'test-message'], 200)]);

        (new ApplicationEmailVerificationService(new NotificationService))
            ->sendCode($project, 'guest@example.test');

        $body = null;
        Http::assertSent(function ($request) use (&$body) {
            $body = $request['text'] ?? null;

            return $request->url() === 'https://api.resend.com/emails';
        });
        $this->assertMatchesRegularExpression('/kodunuz: ([0-9]{8})/', (string) $body);
        preg_match('/kodunuz: ([0-9]{8})/', (string) $body, $matches);
        $this->assertStringNotContainsString($matches[1], (string) CommunicationLog::query()->sole()->content);
        $this->assertSame('sent', CommunicationLog::query()->sole()->status);
    }

    public function test_invalid_guest_form_leaves_no_account_or_file_and_can_be_retried_with_the_same_code(): void
    {
        [$project, $period] = $this->scope();
        $this->fileForm($project, $period);
        $payload = $this->guestPayload($project);
        $payload['verification_code'] = $this->requestGuestCode($project, $payload['applicant']['email']);
        $payload['consent_accepted'] = true;
        $payload['form_files']['attachment'] = UploadedFile::fake()->create('invalid.pdf', 10);

        $this->post('/api/applications/public', $payload, ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('answer');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('applications', 0);
        $this->assertNull(ApplicationEmailVerification::query()->sole()->consumed_at);
        $this->assertSame([], Storage::disk('application_private')->allFiles('application-files'));

        $payload['form_data']['answer'] = 'corrected';
        $payload['form_files']['attachment'] = UploadedFile::fake()->create('corrected.pdf', 10);
        $this->post('/api/applications/public', $payload, ['Accept' => 'application/json'])->assertCreated();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('application_candidates', 1);
        $this->assertDatabaseCount('applications', 1);
        $this->assertNotNull(ApplicationEmailVerification::query()->sole()->consumed_at);
        $this->assertCount(1, Storage::disk('application_private')->allFiles('application-files'));
    }

    public function test_invalid_guest_program_does_not_leave_an_account(): void
    {
        [$project] = $this->scope();
        $payload = $this->guestPayload($project);
        $payload['verification_code'] = $this->requestGuestCode($project, $payload['applicant']['email']);
        $program = Program::query()->sole();
        $program->update(['status' => 'cancelled']);
        $payload['program_id'] = $program->id;

        $this->postJson('/api/applications/public', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('program_id');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('applications', 0);
        $this->assertNull(ApplicationEmailVerification::query()->sole()->consumed_at);
    }

    public function test_failed_guest_application_insert_rolls_back_the_new_account_and_code(): void
    {
        [$project, $period] = $this->scope();
        $this->fileForm($project, $period);
        $payload = $this->guestPayload($project);
        $payload['verification_code'] = $this->requestGuestCode($project, $payload['applicant']['email']);
        $payload['consent_accepted'] = true;
        $payload['form_data']['answer'] = 'valid';
        $payload['form_files']['attachment'] = UploadedFile::fake()->create('rolled-back.pdf', 10);
        Event::listen('eloquent.created: '.Application::class, function () {
            throw new \RuntimeException('Test failure after guest application insert');
        });

        $this->post('/api/applications/public', $payload, ['Accept' => 'application/json'])->assertStatus(500);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('applications', 0);
        $this->assertNull(ApplicationEmailVerification::query()->sole()->consumed_at);
        $this->assertSame([], Storage::disk('application_private')->allFiles('application-files'));
    }

    public function test_duplicate_upload_does_not_replace_original_file_or_leave_an_extra_file(): void
    {
        [$project, $period] = $this->scope();
        $this->fileForm($project, $period);
        Sanctum::actingAs($this->user());
        $payload = ['project_id' => $project->id, 'consent_accepted' => true, 'form_data' => ['answer' => 'original']];
        $payload['form_files']['attachment'] = UploadedFile::fake()->create('first.pdf', 10);
        $this->post('/api/applications', $payload, ['Accept' => 'application/json'])->assertCreated();
        $before = Application::query()->sole()->getAttributes();
        $files = Storage::disk('application_private')->allFiles('application-files');
        $payload['form_files']['attachment'] = UploadedFile::fake()->create('second.pdf', 10);

        $this->post('/api/applications', $payload, ['Accept' => 'application/json'])->assertUnprocessable();

        $this->assertSame($before, Application::query()->sole()->getAttributes());
        $this->assertCount(1, $files);
        $this->assertSame($files, Storage::disk('application_private')->allFiles('application-files'));
    }

    public function test_failed_form_validation_cleans_only_new_uploads_and_allows_a_corrected_retry(): void
    {
        [$project, $period] = $this->scope();
        $this->fileForm($project, $period);
        Storage::disk('application_private')->put('application-files/previous.pdf', 'existing file');
        Sanctum::actingAs($this->user());
        $payload = ['project_id' => $project->id, 'consent_accepted' => true];
        $payload['form_files']['attachment'] = UploadedFile::fake()->create('new.pdf', 10);

        $this->post('/api/applications', $payload, ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('answer');

        $this->assertDatabaseCount('applications', 0);
        $this->assertSame(['application-files/previous.pdf'], Storage::disk('application_private')->allFiles('application-files'));
        $payload['form_data']['answer'] = 'corrected';
        $payload['form_files']['attachment'] = UploadedFile::fake()->create('corrected.pdf', 10);
        $this->post('/api/applications', $payload, ['Accept' => 'application/json'])->assertCreated();
        $this->assertDatabaseCount('applications', 1);
        $this->assertCount(2, Storage::disk('application_private')->allFiles('application-files'));
    }

    public function test_database_failure_rolls_back_the_application_and_cleans_new_uploads(): void
    {
        [$project, $period] = $this->scope();
        $this->fileForm($project, $period);
        Sanctum::actingAs($this->user());
        $this->mock(NotificationService::class)->shouldNotReceive('sendTemplatedEmail');
        Event::listen('eloquent.created: '.Application::class, function () {
            throw new \RuntimeException('Test failure after insert');
        });

        $this->post('/api/applications', [
            'project_id' => $project->id, 'consent_accepted' => true,
            'form_data' => ['answer' => 'valid'],
            'form_files' => ['attachment' => UploadedFile::fake()->create('new.pdf', 10)],
        ], ['Accept' => 'application/json'])->assertStatus(500);

        $this->assertDatabaseCount('applications', 0);
        $this->assertSame([], Storage::disk('application_private')->allFiles('application-files'));
    }

    public function test_closed_window_and_missing_consent_still_prevent_submission(): void
    {
        [$project, $period] = $this->scope();
        $this->fileForm($project, $period);
        $window = ApplicationWindow::query()->create([
            'project_id' => $project->id, 'period_id' => $period->id, 'is_open' => false,
        ]);
        Sanctum::actingAs($this->user());
        $this->postJson('/api/applications', ['project_id' => $project->id])
            ->assertUnprocessable()->assertJsonValidationErrors('project_id');
        $window->update(['is_open' => true]);
        $this->postJson('/api/applications', ['project_id' => $project->id])
            ->assertUnprocessable()->assertJsonValidationErrors('consent_accepted');
        $this->assertDatabaseCount('applications', 0);
    }

    private function fileForm(Project $project, Period $period): void
    {
        ApplicationForm::query()->create([
            'project_id' => $project->id, 'period_id' => $period->id, 'is_active' => true, 'require_consent' => true,
            'fields' => [
                ['id' => 'attachment', 'type' => 'file', 'required' => true],
                ['id' => 'answer', 'type' => 'text', 'required' => true],
            ],
        ]);
    }

    private function user(string $role = 'student'): User
    {
        $user = User::factory()->create([
            'surname' => 'Submission', 'role' => $role, 'status' => 'active',
            'kvkk_consent_at' => now(), 'must_change_password' => false,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function guestPayload(Project $project): array
    {
        return ['project_id' => $project->id, 'consent_accepted' => true, 'applicant' => ['name' => 'Guest', 'surname' => 'Test', 'email' => 'GUEST@example.test']];
    }

    private function requestGuestCode(Project $project, string $email): string
    {
        $this->sentVerificationCode = null;
        $this->postJson('/api/applications/public/verification', [
            'project_id' => $project->id,
            'email' => $email,
        ])->assertOk();

        $this->assertMatchesRegularExpression('/^[0-9]{8}$/', (string) $this->sentVerificationCode);

        return $this->sentVerificationCode;
    }

    private function scope(): array
    {
        $project = Project::query()->create([
            'name' => 'Submission', 'slug' => 'submission', 'type' => 'other', 'status' => 'active',
            'application_open' => true, 'has_interview' => false,
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id, 'name' => 'Current period', 'status' => 'active',
            'start_date' => now()->subMonth(), 'end_date' => now()->addMonth(),
        ]);
        $project->update(['current_period_id' => $period->id]);
        $program = Program::query()->create([
            'project_id' => $project->id, 'period_id' => $period->id, 'title' => 'Program', 'status' => 'scheduled',
            'start_at' => now()->addDay(), 'end_at' => now()->addDay()->addHour(),
        ]);

        return [$project, $period, $program];
    }
}
