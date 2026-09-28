<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationForm;
use App\Models\Period;
use App\Models\Project;
use App\Models\User;
use App\Services\ApplicationConsentService;
use App\Services\NotificationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApplicationConsentTest extends TestCase
{
    use RefreshDatabase;

    private ?string $verificationCode = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->mock(NotificationService::class, function ($mock) {
            $mock->shouldReceive('sendTemplatedEmail')->andReturn(1);
            $mock->shouldReceive('sendEmail')->andReturnUsing(function (...$arguments) {
                preg_match('/kodunuz: ([0-9]{8})/', (string) ($arguments[2] ?? ''), $matches);
                $this->verificationCode = $matches[1] ?? null;

                return 1;
            });
        });
    }

    private function scope(): array
    {
        $project = Project::query()->create([
            'name' => 'Onay Projesi',
            'slug' => 'onay-projesi',
            'type' => 'other',
            'status' => 'active',
            'is_public' => true,
            'application_open' => true,
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => 'Aktif Donem',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $project->update(['current_period_id' => $period->id]);

        return [$project, $period];
    }

    public function test_application_without_form_requires_consent_and_records_exact_text(): void
    {
        [$project, $period] = $this->scope();
        $student = User::factory()->create([
            'surname' => 'Aday',
            'role' => 'student',
            'status' => 'active',
            'kvkk_consent_at' => now(),
        ]);
        $student->assignRole('student');
        Sanctum::actingAs($student);

        $this->getJson('/api/projects/'.$project->slug.'/application-form')
            ->assertOk()
            ->assertJsonPath('application_form', null)
            ->assertJsonPath('application_consent_text', ApplicationConsentService::GENERAL_TEXT);
        $this->postJson('/api/applications', ['project_id' => $project->id])
            ->assertUnprocessable()->assertJsonValidationErrors('consent_accepted');
        $this->postJson('/api/applications', ['project_id' => $project->id, 'consent_accepted' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('consent_accepted');
        $this->postJson('/api/applications', [
            'project_id' => $project->id,
            'consent_accepted' => true,
            'expected_consent_text' => 'Eski kosullar',
        ])->assertUnprocessable()->assertJsonValidationErrors('expected_consent_text');
        $this->assertDatabaseCount('applications', 0);

        $this->postJson('/api/applications', [
            'project_id' => $project->id,
            'consent_accepted' => true,
            'expected_consent_text' => ApplicationConsentService::GENERAL_TEXT,
        ])
            ->assertCreated()
            ->assertJsonPath('application.consent_text_snapshot', ApplicationConsentService::GENERAL_TEXT);
        $application = Application::query()->firstOrFail();
        $this->assertSame($period->id, $application->period_id);
        $this->assertNotNull($application->consent_accepted_at);
        $this->assertSame(ApplicationConsentService::GENERAL_TEXT, $application->consent_text_snapshot);
    }

    public function test_additional_terms_are_snapshotted_even_if_old_form_did_not_require_consent(): void
    {
        [$project, $period] = $this->scope();
        $form = ApplicationForm::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'fields' => [['id' => 'motivation', 'type' => 'text', 'label' => 'Motivasyon', 'required' => true]],
            'require_consent' => false,
            'consent_text' => 'Bu programa katilim kurallarini okudum.',
            'is_active' => true,
        ]);
        $student = User::factory()->create([
            'surname' => 'Aday',
            'role' => 'student',
            'status' => 'active',
            'kvkk_consent_at' => now(),
        ]);
        $student->assignRole('student');
        Sanctum::actingAs($student);
        $displayedText = ApplicationConsentService::GENERAL_TEXT."\n\nBu programa katilim kurallarini okudum.";

        $this->getJson('/api/projects/'.$project->slug.'/application-form')
            ->assertOk()
            ->assertJsonPath('application_form.id', $form->id)
            ->assertJsonPath('application_consent_text', $displayedText);
        $payload = [
            'project_id' => $project->id,
            'application_form_id' => $form->id,
            'expected_consent_text' => $displayedText,
            'form_data' => ['motivation' => 'Katılmak istiyorum'],
        ];
        $this->postJson('/api/applications', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('consent_accepted');
        $this->postJson('/api/applications', $payload + ['consent_accepted' => true])
            ->assertCreated();

        $application = Application::query()->firstOrFail();
        $this->assertSame($displayedText, $application->consent_text_snapshot);
        $this->assertNotNull($application->consent_accepted_at);
        $form->update(['consent_text' => 'Yeni metin']);
        $this->assertSame($displayedText, $application->fresh()->consent_text_snapshot);
    }

    public function test_legacy_application_is_not_given_an_invented_consent_receipt(): void
    {
        [$project, $period] = $this->scope();
        $legacy = Application::query()->create([
            'user_id' => User::factory()->create(['surname' => 'Eski'])->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'pending',
        ]);

        $this->assertNull($legacy->fresh()->consent_text_snapshot);
        $this->assertNull($legacy->consent_accepted_at);
    }

    public function test_guest_can_accept_terms_after_initial_refusal_without_losing_verification(): void
    {
        [$project] = $this->scope();
        $this->postJson('/api/applications/public/verification', [
            'project_id' => $project->id,
            'email' => 'onay-ziyaretci@example.test',
        ])->assertOk();
        $this->assertMatchesRegularExpression('/^[0-9]{8}$/', (string) $this->verificationCode);

        $payload = [
            'project_id' => $project->id,
            'verification_code' => $this->verificationCode,
            'expected_consent_text' => ApplicationConsentService::GENERAL_TEXT,
            'applicant' => [
                'name' => 'Ziyaretci',
                'surname' => 'Aday',
                'email' => 'onay-ziyaretci@example.test',
            ],
        ];
        $this->postJson('/api/applications/public', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('consent_accepted');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('applications', 0);

        $this->postJson('/api/applications/public', $payload + ['consent_accepted' => true])
            ->assertCreated()
            ->assertJsonPath('application.consent_text_snapshot', ApplicationConsentService::GENERAL_TEXT);
        $this->assertDatabaseCount('users', 1);
        $this->assertNotNull(Application::query()->sole()->consent_accepted_at);
    }
}
