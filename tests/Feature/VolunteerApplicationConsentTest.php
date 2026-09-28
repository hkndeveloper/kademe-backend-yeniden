<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Models\VolunteerApplication;
use App\Models\VolunteerOpportunity;
use App\Services\ApplicationConsentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VolunteerApplicationConsentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function applicant(string $role): User
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

    private function opportunity(?string $extraTerms = null): VolunteerOpportunity
    {
        $project = Project::query()->create([
            'name' => 'Gönüllülük Projesi',
            'slug' => 'gonulluluk-projesi',
            'type' => 'diplomasi360',
            'status' => 'active',
        ]);

        return VolunteerOpportunity::query()->create([
            'project_id' => $project->id,
            'title' => 'Etkinlik gönüllüsü',
            'description' => 'Başvuru açıklaması',
            'consent_text' => $extraTerms,
            'status' => 'open',
        ]);
    }

    public function test_student_must_accept_current_text_and_keeps_exact_receipt_after_opportunity_changes(): void
    {
        $student = $this->applicant('student');
        $opportunity = $this->opportunity('Etkinlik saatinde hazır bulunulmalıdır.');
        Sanctum::actingAs($student);

        $text = app(ApplicationConsentService::class)->textWithAdditional($opportunity->consent_text);
        $this->getJson('/api/volunteer/opportunities')
            ->assertOk()
            ->assertJsonPath('opportunities.0.application_consent_text', $text);

        $payload = [
            'motivation_text' => 'Bu gönüllülük çalışmasına düzenli olarak destek vermek istiyorum.',
            'accepted_terms' => true,
            'expected_consent_text' => $text,
        ];

        $this->postJson("/api/volunteer/opportunities/{$opportunity->id}/apply", [
            ...$payload,
            'accepted_terms' => false,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('volunteer_applications', 0);

        $opportunity->update(['consent_text' => 'Güncellenmiş özel koşul.']);
        $this->postJson("/api/volunteer/opportunities/{$opportunity->id}/apply", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expected_consent_text');
        $this->assertDatabaseCount('volunteer_applications', 0);

        $updatedText = app(ApplicationConsentService::class)->textWithAdditional($opportunity->fresh()->consent_text);
        $this->postJson("/api/volunteer/opportunities/{$opportunity->id}/apply", [
            ...$payload,
            'expected_consent_text' => $updatedText,
        ])->assertCreated()
            ->assertJsonPath('application.consent_text_snapshot', $updatedText);

        $application = VolunteerApplication::query()->sole();
        $this->assertNotNull($application->consent_accepted_at);
        $opportunity->update(['consent_text' => 'Daha sonraki koşul.']);
        $this->assertSame($updatedText, $application->fresh()->consent_text_snapshot);
        $this->getJson('/api/volunteer/opportunities')
            ->assertOk()
            ->assertJsonPath('my_applications.0.consent_text_snapshot', $updatedText)
            ->assertJsonPath('opportunities.0.my_application.consent_text_snapshot', $updatedText);
    }

    public function test_alumni_uses_same_mandatory_terms_and_old_application_has_no_invented_receipt(): void
    {
        $alumni = $this->applicant('alumni');
        $opportunity = $this->opportunity();
        Sanctum::actingAs($alumni);

        $text = app(ApplicationConsentService::class)->textWithAdditional(null);
        $this->postJson("/api/volunteer/opportunities/{$opportunity->id}/apply", [
            'motivation_text' => 'Bu gönüllülük çalışmasına düzenli olarak destek vermek istiyorum.',
            'accepted_terms' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('expected_consent_text');

        $this->postJson("/api/volunteer/opportunities/{$opportunity->id}/apply", [
            'motivation_text' => 'Bu gönüllülük çalışmasına düzenli olarak destek vermek istiyorum.',
            'accepted_terms' => true,
            'expected_consent_text' => $text,
        ])->assertCreated()->assertJsonPath('application.consent_text_snapshot', $text);

        $application = VolunteerApplication::query()->sole();
        $application->update([
            'consent_text_snapshot' => null,
            'consent_accepted_at' => null,
        ]);

        $this->getJson('/api/volunteer/opportunities')
            ->assertOk()
            ->assertJsonPath('my_applications.0.consent_text_snapshot', null)
            ->assertJsonPath('my_applications.0.consent_accepted_at', null);
    }

    public function test_coordinator_panel_can_set_terms_and_read_applicant_receipt(): void
    {
        $admin = $this->applicant('super_admin');
        $student = $this->applicant('student');
        $project = $this->opportunity()->project;
        Sanctum::actingAs($admin);

        $created = $this->postJson('/api/panel/volunteer/opportunities', [
            'project_id' => $project->id,
            'title' => 'Yeni gönüllü ilanı',
            'description' => 'Bu ilana başvuranlar etkinlikte görev alır.',
            'status' => 'open',
            'consent_text' => 'Bu ilanın özel koşulu.',
        ])->assertCreated();
        $id = $created->json('opportunity.id');
        $this->assertSame('Bu ilanın özel koşulu.', VolunteerOpportunity::query()->findOrFail($id)->consent_text);

        Sanctum::actingAs($student);
        $text = app(ApplicationConsentService::class)->textWithAdditional('Bu ilanın özel koşulu.');
        $this->postJson("/api/volunteer/opportunities/{$id}/apply", [
            'motivation_text' => 'Bu gönüllülük çalışmasına düzenli olarak destek vermek istiyorum.',
            'accepted_terms' => true,
            'expected_consent_text' => $text,
        ])->assertCreated();

        Sanctum::actingAs($admin);
        $panel = $this->getJson("/api/panel/volunteer/opportunities?project_id={$project->id}")
            ->assertOk();
        $row = collect($panel->json('opportunities.data'))->firstWhere('id', $id);
        $this->assertSame($text, $row['applications'][0]['consent_text_snapshot'] ?? null);
    }
}
