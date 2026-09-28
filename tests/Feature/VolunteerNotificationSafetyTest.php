<?php

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\Project;
use App\Models\RolePermissionScope;
use App\Models\User;
use App\Models\VolunteerApplication;
use App\Models\VolunteerOpportunity;
use App\Services\ApplicationConsentService;
use App\Services\NotificationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class VolunteerNotificationSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create([
            'surname' => 'Gönüllü',
            'role' => $role,
            'status' => 'active',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate($role, 'web');
        $user->assignRole($role);

        return $user;
    }

    private function opportunity(): VolunteerOpportunity
    {
        $project = Project::query()->create([
            'name' => 'Gönüllülük Bildirimi',
            'slug' => 'gonulluluk-bildirimi',
            'type' => 'diplomasi360',
            'status' => 'active',
        ]);

        return VolunteerOpportunity::query()->create([
            'project_id' => $project->id,
            'title' => 'Etkinlik gönüllüsü',
            'description' => 'Gönüllülük çalışması',
            'status' => 'open',
        ]);
    }

    private function pendingApplication(VolunteerOpportunity $opportunity, User $user): VolunteerApplication
    {
        return VolunteerApplication::query()->create([
            'volunteer_opportunity_id' => $opportunity->id,
            'user_id' => $user->id,
            'motivation_text' => 'Bu etkinlikte gönüllü olmak istiyorum.',
            'status' => 'pending',
        ]);
    }

    public function test_receipt_email_identifies_the_opportunity_and_opens_the_users_volunteer_page(): void
    {
        config(['services.frontend.url' => 'https://kademe.example']);
        $opportunity = $this->opportunity();
        $student = $this->actor('student');
        $emailData = null;
        $this->mock(NotificationService::class)
            ->shouldReceive('sendTemplatedEmail')
            ->once()
            ->andReturnUsing(function ($recipients, $subject, $view, $data) use (&$emailData) {
                $emailData = $data;

                return 1;
            });
        Sanctum::actingAs($student);

        $this->postJson("/api/volunteer/opportunities/{$opportunity->id}/apply", [
            'motivation_text' => 'Bu çalışmada gönüllü olmak istiyorum.',
            'accepted_terms' => true,
            'expected_consent_text' => app(ApplicationConsentService::class)->textWithAdditional(null),
        ])->assertCreated()->assertJsonPath('application.receipt_email_status', 'sent');

        $this->assertSame('https://kademe.example/student/volunteer', $emailData['action_url']);
        $this->assertContains(['label' => 'Gönüllülük ilanı', 'value' => 'Etkinlik gönüllüsü'], $emailData['lines']);
        $this->assertContains(['label' => 'Durum', 'value' => 'Değerlendirme bekliyor'], $emailData['lines']);
    }

    public function test_failed_receipt_keeps_one_application_and_can_be_retried_without_resubmission(): void
    {
        $opportunity = $this->opportunity();
        $student = $this->actor('student');
        $this->mock(NotificationService::class)
            ->shouldReceive('sendTemplatedEmail')
            ->twice()
            ->andReturn(0, 1);
        Sanctum::actingAs($student);

        $payload = [
            'motivation_text' => 'Bu gönüllülük çalışmasına düzenli olarak destek vermek istiyorum.',
            'accepted_terms' => true,
            'expected_consent_text' => app(ApplicationConsentService::class)->textWithAdditional(null),
        ];
        $created = $this->postJson("/api/volunteer/opportunities/{$opportunity->id}/apply", $payload)
            ->assertCreated()
            ->assertJsonPath('follow_up.receipt_email_sent', false)
            ->assertJsonPath('application.receipt_email_status', 'failed');
        $applicationId = $created->json('application.id');
        $this->assertDatabaseCount('volunteer_applications', 1);
        $this->postJson("/api/volunteer/opportunities/{$opportunity->id}/apply", $payload)
            ->assertUnprocessable();
        $this->getJson('/api/volunteer/opportunities')
            ->assertOk()
            ->assertJsonPath('my_applications.0.receipt_email_status', 'failed');

        Sanctum::actingAs($this->actor('super_admin'));
        $this->postJson("/api/panel/volunteer/applications/{$applicationId}/notification-retry", ['type' => 'receipt'])
            ->assertOk()
            ->assertJsonPath('sent', true)
            ->assertJsonPath('application.receipt_email_status', 'sent');
        $this->assertDatabaseCount('volunteer_applications', 1);
        $this->postJson("/api/panel/volunteer/applications/{$applicationId}/notification-retry", ['type' => 'receipt'])
            ->assertUnprocessable();
    }

    public function test_failed_decision_can_be_retried_without_repeating_acceptance_or_sending_on_same_status(): void
    {
        $opportunity = $this->opportunity();
        $application = $this->pendingApplication($opportunity, $this->actor('student'));
        $this->mock(NotificationService::class)
            ->shouldReceive('sendTemplatedEmail')
            ->twice()
            ->andReturn(0, 1);
        Sanctum::actingAs($this->actor('super_admin'));

        $this->putJson("/api/panel/volunteer/applications/{$application->id}", ['status' => 'accepted'])
            ->assertOk()
            ->assertJsonPath('follow_up.decision_email_sent', false)
            ->assertJsonPath('application.decision_email_status', 'failed');
        $key = $application->fresh()->decision_email_key;
        $this->assertNotNull($key);

        $this->putJson("/api/panel/volunteer/applications/{$application->id}", ['status' => 'accepted'])
            ->assertOk()
            ->assertJsonPath('follow_up.decision_email_sent', null)
            ->assertJsonPath('application.decision_email_status', 'failed');
        $this->assertSame($key, $application->fresh()->decision_email_key);

        $this->postJson("/api/panel/volunteer/applications/{$application->id}/notification-retry", ['type' => 'decision'])
            ->assertOk()
            ->assertJsonPath('sent', true)
            ->assertJsonPath('application.decision_email_status', 'sent');
        $this->postJson("/api/panel/volunteer/applications/{$application->id}/notification-retry", ['type' => 'decision'])
            ->assertUnprocessable();
        $this->assertSame('accepted', $application->fresh()->status);
        $this->assertDatabaseCount((new Participant)->getTable(), 0);
    }

    public function test_exception_is_marked_unknown_without_automatic_duplicate_retry(): void
    {
        $opportunity = $this->opportunity();
        $application = $this->pendingApplication($opportunity, $this->actor('student'));
        $this->mock(NotificationService::class)
            ->shouldReceive('sendTemplatedEmail')
            ->once()
            ->andThrow(new \RuntimeException('Belirsiz gönderim sonucu'));
        Sanctum::actingAs($this->actor('super_admin'));

        $this->putJson("/api/panel/volunteer/applications/{$application->id}", ['status' => 'rejected'])
            ->assertOk()
            ->assertJsonPath('application.status', 'rejected')
            ->assertJsonPath('application.decision_email_status', 'unknown');
        $this->postJson("/api/panel/volunteer/applications/{$application->id}/notification-retry", ['type' => 'decision'])
            ->assertUnprocessable();
    }

    public function test_old_untracked_record_and_other_project_cannot_use_retry(): void
    {
        $allowedOpportunity = $this->opportunity();
        $outsideProject = Project::query()->create([
            'name' => 'Diğer Gönüllülük',
            'slug' => 'diger-gonulluluk',
            'type' => 'other',
            'status' => 'active',
        ]);
        $outsideOpportunity = VolunteerOpportunity::query()->create([
            'project_id' => $outsideProject->id,
            'title' => 'Diğer ilan',
            'description' => 'Başka proje',
            'status' => 'open',
        ]);
        $oldApplication = $this->pendingApplication($allowedOpportunity, $this->actor('student'));
        $outsideApplication = $this->pendingApplication($outsideOpportunity, $this->actor('student'));
        $outsideApplication->update(['decision_email_status' => 'failed', 'decision_email_key' => fake()->uuid()]);

        $coordinator = $this->actor('coordinator');
        $allowedOpportunity->project->coordinators()->attach($coordinator->id);
        $role = Role::findOrCreate('coordinator', 'web');
        $role->givePermissionTo(Permission::findOrCreate('volunteer.manage', 'web'));
        RolePermissionScope::query()->updateOrCreate(
            ['role_name' => 'coordinator', 'permission_name' => 'volunteer.manage'],
            ['scope_type' => 'own_projects', 'scope_payload' => []],
        );
        Sanctum::actingAs($coordinator);

        $this->postJson("/api/panel/volunteer/applications/{$oldApplication->id}/notification-retry", ['type' => 'decision'])
            ->assertUnprocessable();
        $this->postJson("/api/panel/volunteer/applications/{$outsideApplication->id}/notification-retry", ['type' => 'decision'])
            ->assertForbidden();
        $this->assertSame('failed', $outsideApplication->fresh()->decision_email_status);
    }

    public function test_late_email_result_cannot_overwrite_a_newer_decision_delivery_state(): void
    {
        $opportunity = $this->opportunity();
        $application = $this->pendingApplication($opportunity, $this->actor('student'));
        $newerKey = (string) Str::uuid();
        $this->mock(NotificationService::class)
            ->shouldReceive('sendTemplatedEmail')
            ->once()
            ->andReturnUsing(function () use ($application, $newerKey) {
                $application->fresh()->update([
                    'status' => 'rejected',
                    'decision_email_status' => 'pending',
                    'decision_email_key' => $newerKey,
                ]);

                return 1;
            });
        Sanctum::actingAs($this->actor('super_admin'));

        $this->putJson("/api/panel/volunteer/applications/{$application->id}", ['status' => 'accepted'])
            ->assertOk()
            ->assertJsonPath('application.status', 'rejected')
            ->assertJsonPath('application.decision_email_status', 'pending')
            ->assertJsonPath('follow_up.decision_email_status', 'unknown');
        $this->assertSame($newerKey, $application->fresh()->decision_email_key);
    }
}
