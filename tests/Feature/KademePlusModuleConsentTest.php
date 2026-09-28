<?php

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\Period;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\ProjectModuleEnrollment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class KademePlusModuleConsentTest extends TestCase
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
            'surname' => 'Modül',
            'role' => $role,
            'status' => 'active',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate($role, 'web');
        $user->assignRole($role);

        return $user;
    }

    private function projectWithStudent(): array
    {
        $student = $this->actor('student');
        $project = Project::query()->create([
            'name' => 'KADEME+',
            'slug' => 'kademe-plus-onay-test',
            'type' => 'kademe_plus',
            'status' => 'active',
            'application_open' => true,
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Güz',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonths(6),
            'credit_start_amount' => 100,
            'credit_threshold' => 75,
            'status' => 'active',
        ]);
        $participant = Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
            'credit' => 100,
        ]);

        return [$project, $student, $participant];
    }

    public function test_legacy_module_without_faq_or_warning_still_shows_information_and_requires_real_consent(): void
    {
        [$project, $student] = $this->projectWithStudent();
        $module = ProjectModule::query()->create([
            'project_id' => $project->id,
            'title' => 'Eski modül',
            'is_active' => true,
            'application_open' => true,
            'requires_consent' => false,
            'requires_coordinator_approval' => true,
        ]);
        Sanctum::actingAs($student);

        $specials = $this->getJson('/api/dashboard/project-specials')->assertOk();
        $projectRow = collect($specials->json('projects'))->firstWhere('project.id', $project->id);
        $moduleRow = collect($projectRow['kademe_modules'])->firstWhere('id', $module->id);
        $this->assertTrue($moduleRow['requires_consent']);
        $this->assertNotEmpty($moduleRow['faq_items']);
        $this->assertNotEmpty($moduleRow['warning_text']);
        $this->assertStringContainsString('Sık sorulan sorular', $moduleRow['application_consent_text']);
        $this->assertStringContainsString('Uyarılar ve yaptırımlar', $moduleRow['application_consent_text']);

        $url = "/api/dashboard/projects/{$project->id}/kademe-modules/{$module->id}/enroll";
        $this->postJson($url, ['expected_consent_hash' => $moduleRow['application_consent_hash']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('accepted_terms');
        $this->assertDatabaseCount('project_module_enrollments', 0);

        $this->postJson($url, [
            'accepted_terms' => true,
            'expected_consent_hash' => $moduleRow['application_consent_hash'],
        ])->assertCreated()
            ->assertJsonPath('enrollment.status', 'pending')
            ->assertJsonPath('enrollment.consent_text_snapshot', $moduleRow['application_consent_text']);

        $enrollment = ProjectModuleEnrollment::query()->sole();
        $this->assertNotNull($enrollment->consented_at);
        $module->update(['warning_text' => 'Yeni uyarı.']);
        $this->assertSame($moduleRow['application_consent_text'], $enrollment->fresh()->consent_text_snapshot);
        $this->getJson('/api/dashboard/project-specials')
            ->assertOk()
            ->assertJsonPath('projects.0.kademe_modules.0.enrollment.consent_text_snapshot', $moduleRow['application_consent_text']);

        $admin = $this->actor('super_admin');
        Sanctum::actingAs($admin);
        $this->getJson("/api/panel/projects/{$project->id}/special-modules")
            ->assertOk()
            ->assertJsonPath('kademe_modules.0.enrollments.0.consent_text_snapshot', $moduleRow['application_consent_text']);
        $this->getJson("/api/panel/project-families/kademe-plus?project_id={$project->id}")
            ->assertOk()
            ->assertJsonPath('data.kademe_modules.0.enrollments.0.consent_text_snapshot', $moduleRow['application_consent_text']);
        $this->deleteJson("/api/admin/projects/{$project->id}/special-modules/kademe-modules/{$module->id}")
            ->assertUnprocessable();
        $this->assertDatabaseHas('project_module_enrollments', ['id' => $enrollment->id]);
        $this->putJson("/api/admin/projects/{$project->id}/special-modules/kademe-modules/{$module->id}", [
            'application_open' => false,
            'is_active' => false,
        ])->assertOk();
    }

    public function test_changed_module_information_rejects_old_display_and_admin_cannot_disable_consent(): void
    {
        [$project, $student] = $this->projectWithStudent();
        $admin = $this->actor('super_admin');
        Sanctum::actingAs($admin);

        $created = $this->postJson("/api/admin/projects/{$project->id}/special-modules/kademe-modules", [
            'title' => 'Yeni modül',
            'requires_consent' => false,
            'requires_coordinator_approval' => false,
            'warning_text' => 'İlk uyarı.',
            'faq_items' => [['question' => 'Ne zaman?', 'answer' => 'Duyurulunca.']],
        ])->assertCreated();
        $id = $created->json('kademe_module.id');
        $this->assertTrue(ProjectModule::query()->findOrFail($id)->requires_consent);

        Sanctum::actingAs($student);
        $first = $this->getJson('/api/dashboard/project-specials')->assertOk();
        $oldHash = $first->json('projects.0.kademe_modules.0.application_consent_hash');

        Sanctum::actingAs($admin);
        $this->putJson("/api/admin/projects/{$project->id}/special-modules/kademe-modules/{$id}", [
            'requires_consent' => false,
            'warning_text' => 'Güncellenmiş uyarı.',
        ])->assertOk();
        $this->assertTrue(ProjectModule::query()->findOrFail($id)->requires_consent);

        Sanctum::actingAs($student);
        $url = "/api/dashboard/projects/{$project->id}/kademe-modules/{$id}/enroll";
        $this->postJson($url, [
            'accepted_terms' => true,
            'expected_consent_hash' => $oldHash,
        ])->assertUnprocessable()->assertJsonValidationErrors('expected_consent_hash');
        $this->assertDatabaseCount('project_module_enrollments', 0);

        $current = $this->getJson('/api/dashboard/project-specials')->assertOk();
        $this->postJson($url, [
            'accepted_terms' => true,
            'expected_consent_hash' => $current->json('projects.0.kademe_modules.0.application_consent_hash'),
        ])->assertCreated()->assertJsonPath('enrollment.status', 'approved');
    }

    public function test_old_enrollment_is_not_assigned_an_invented_terms_snapshot(): void
    {
        [$project, $student, $participant] = $this->projectWithStudent();
        $module = ProjectModule::query()->create([
            'project_id' => $project->id,
            'title' => 'Önceki modül',
            'is_active' => true,
        ]);
        ProjectModuleEnrollment::query()->create([
            'project_module_id' => $module->id,
            'user_id' => $student->id,
            'participant_id' => $participant->id,
            'status' => 'approved',
            'consented_at' => now()->subMonth(),
        ]);
        Sanctum::actingAs($student);

        $this->getJson('/api/dashboard/project-specials')
            ->assertOk()
            ->assertJsonPath('projects.0.kademe_modules.0.enrollment.consent_text_snapshot', null);
    }
}
