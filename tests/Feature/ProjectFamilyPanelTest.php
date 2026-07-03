<?php

namespace Tests\Feature;

use App\Models\EurodeskProject;
use App\Models\Internship;
use App\Models\Mentor;
use App\Models\Participant;
use App\Models\Period;
use App\Models\Project;
use App\Models\RewardTier;
use App\Models\RewardAward;
use App\Models\ProjectModuleEnrollment;
use App\Models\ProjectModule;
use App\Models\RolePermissionScope;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProjectFamilyPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_project_family_endpoint_returns_scoped_family_project_data(): void
    {
        $project = $this->project('Diplomasi A', 'diplomasi-a', 'diplomasi360');
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Bahar',
            'start_date' => '2026-03-01',
            'end_date' => '2026-06-30',
            'status' => 'active',
        ]);
        $participantUser = User::factory()->create(['role' => 'student', 'surname' => 'Student']);
        $participant = Participant::query()->create([
            'user_id' => $participantUser->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
        ]);
        Internship::query()->create([
            'participant_id' => $participant->id,
            'company_name' => 'KADEME',
            'position' => 'Intern',
            'start_date' => '2026-04-01',
            'end_date' => '2026-05-01',
            'description' => 'Program staji',
            'document_path' => 'internship-documents/test.pdf',
        ]);

        $actor = $this->userWithProjectScope('family_diplomasi_viewer', 'projects.internships.view', [$project->id]);
        Sanctum::actingAs($actor);

        $response = $this->getJson("/api/panel/project-families/diplomasi360?project_id={$project->id}&period_id={$period->id}")
            ->assertOk();

        $response->assertJsonPath('family.key', 'diplomasi360');
        $response->assertJsonPath('selected_project.id', $project->id);
        $response->assertJsonPath('projects.0.id', $project->id);
        $this->assertTrue($response->json('access')['projects.internships.view']);
        $response->assertJsonPath('summary.1.id', 'internships');
        $response->assertJsonPath('summary.1.value', 1);
        $response->assertJsonPath('data.internships.0.participant_id', $participant->id);
        $response->assertJsonPath('data.internships.0.document_path', 'internship-documents/test.pdf');
        $response->assertJsonPath('data.participants.0.id', $participant->id);
        $this->assertTrue(collect($response->json('tabs'))->firstWhere('id', 'internships')['visible']);
    }

    public function test_pergel_family_endpoint_returns_mentors_participants_and_assignments(): void
    {
        $project = $this->project('Pergel A', 'pergel-a', 'pergel_fellowship');
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Bahar',
            'start_date' => '2026-03-01',
            'end_date' => '2026-06-30',
            'status' => 'active',
        ]);
        $participantUser = User::factory()->create([
            'role' => 'student',
            'surname' => 'Participant',
        ]);
        $participant = Participant::query()->create([
            'user_id' => $participantUser->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
        ]);
        $mentor = Mentor::query()->create([
            'project_id' => $project->id,
            'name' => 'Pergel Mentor',
            'expertise' => 'Leadership',
        ]);
        $mentor->participants()->attach($participant->id, [
            'period_id' => $period->id,
            'note' => 'Ilk gorusme',
        ]);

        $actor = $this->userWithProjectScope('family_pergel_viewer', 'projects.mentors.view', [$project->id]);
        Sanctum::actingAs($actor);

        $response = $this->getJson("/api/panel/project-families/pergel?project_id={$project->id}&period_id={$period->id}")
            ->assertOk();

        $response->assertJsonPath('family.key', 'pergel');
        $response->assertJsonPath('data.mentors.0.name', 'Pergel Mentor');
        $response->assertJsonPath('data.mentors.0.participants_count', 1);
        $response->assertJsonPath('data.mentors.0.assigned_participants.0.id', $participant->id);
        $response->assertJsonPath('data.participants.0.id', $participant->id);
    }
    public function test_eurodesk_family_endpoint_returns_projects_partnerships_and_summary(): void
    {
        $project = $this->project('Eurodesk A', 'eurodesk-a', 'eurodesk');
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Bahar',
            'start_date' => '2026-03-01',
            'end_date' => '2026-06-30',
            'status' => 'active',
        ]);
        $eurodeskProject = EurodeskProject::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Genclik Hibesi',
            'partner_organizations' => ['Ortak Kurum'],
            'grant_amount' => 1500,
            'grant_status' => 'approved',
            'start_date' => '2026-04-01',
            'end_date' => '2026-05-01',
        ]);
        $eurodeskProject->partnerships()->create([
            'organization_name' => 'Ortak Kurum',
            'country' => 'Turkiye',
            'contact_info' => 'Eurodesk ofisi',
        ]);

        $actor = $this->userWithProjectScope('family_eurodesk_viewer', 'projects.eurodesk.view', [$project->id]);
        Sanctum::actingAs($actor);

        $response = $this->getJson("/api/panel/project-families/eurodesk?project_id={$project->id}&period_id={$period->id}")
            ->assertOk();

        $response->assertJsonPath('family.key', 'eurodesk');
        $response->assertJsonPath('data.eurodesk_projects.0.id', $eurodeskProject->id);
        $response->assertJsonPath('data.eurodesk_projects.0.partnerships.0.organization_name', 'Ortak Kurum');
        $response->assertJsonPath('data.eurodesk_summary.total_projects', 1);
        $response->assertJsonPath('data.eurodesk_summary.approved_projects', 1);
        $response->assertJsonPath('data.eurodesk_summary.partnership_count', 1);
        $response->assertJsonPath('data.eurodesk_summary.countries.0', 'Turkiye');
    }
    public function test_kademe_family_endpoint_returns_rewards_modules_and_enrollments_for_manage_scope(): void
    {
        $project = $this->project('Kademe Plus A', 'kademe-plus-a', 'kademe_plus');
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Bahar',
            'start_date' => '2026-03-01',
            'end_date' => '2026-06-30',
            'status' => 'active',
        ]);
        $participantUser = User::factory()->create([
            'role' => 'student',
            'surname' => 'Rewarded',
        ]);
        $participant = Participant::query()->create([
            'user_id' => $participantUser->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
            'credit' => 100,
        ]);
        $tier = RewardTier::query()->create([
            'project_id' => $project->id,
            'name' => 'Kademe 1',
            'min_badges' => 0,
            'min_credits' => 50,
            'reward_description' => 'Hediye Seti',
        ]);
        $award = RewardAward::query()->create([
            'project_id' => $project->id,
            'participant_id' => $participant->id,
            'reward_tier_id' => $tier->id,
            'reward_name' => 'Hediye Seti',
            'status' => 'given',
            'awarded_at' => now(),
        ]);
        $module = ProjectModule::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Liderlik Modulu',
            'description' => 'Modul aciklamasi',
            'sort_order' => 1,
            'is_active' => true,
            'application_open' => true,
            'requires_consent' => true,
            'requires_coordinator_approval' => true,
            'outcomes' => ['Liderlik'],
        ]);
        $enrollment = ProjectModuleEnrollment::query()->create([
            'project_module_id' => $module->id,
            'user_id' => $participantUser->id,
            'participant_id' => $participant->id,
            'status' => 'pending',
            'consented_at' => now(),
        ]);

        $actor = $this->userWithProjectScope('family_kademe_manager', 'projects.rewards.manage', [$project->id]);
        Sanctum::actingAs($actor);

        $response = $this->getJson("/api/panel/project-families/kademe-plus?project_id={$project->id}&period_id={$period->id}")
            ->assertOk();

        $response->assertJsonPath('family.key', 'kademe-plus');
        $response->assertJsonPath('data.reward_tiers.0.id', $tier->id);
        $response->assertJsonPath('data.reward_awards.0.id', $award->id);
        $response->assertJsonPath('data.reward_eligible_participants.0.participant_id', $participant->id);
        $response->assertJsonPath('data.kademe_modules.0.id', $module->id);
        $response->assertJsonPath('data.kademe_modules.0.enrollments.0.id', $enrollment->id);
        $response->assertJsonPath('data.pending_enrollments_count', 1);
    }
    public function test_project_family_endpoint_denies_when_scope_points_to_other_project_type(): void
    {
        $this->project('Diplomasi A', 'diplomasi-a', 'diplomasi360');
        $eurodesk = $this->project('Eurodesk A', 'eurodesk-a', 'eurodesk');

        $actor = $this->userWithProjectScope('family_wrong_type_viewer', 'projects.internships.view', [$eurodesk->id]);
        Sanctum::actingAs($actor);

        $this->getJson('/api/panel/project-families/diplomasi360')->assertForbidden();
    }

    public function test_project_family_endpoint_returns_only_scoped_family_projects(): void
    {
        $allowed = $this->project('Diplomasi A', 'diplomasi-a', 'diplomasi360');
        $this->project('Diplomasi B', 'diplomasi-b', 'diplomasi360');
        $this->project('Eurodesk A', 'eurodesk-a', 'eurodesk');

        $actor = $this->userWithProjectScope('family_limited_viewer', 'projects.internships.view', [$allowed->id]);
        Sanctum::actingAs($actor);

        $response = $this->getJson('/api/panel/project-families/diplomasi360')->assertOk();

        $this->assertSame([$allowed->id], collect($response->json('projects'))->pluck('id')->all());
        $response->assertJsonPath('selected_project.id', $allowed->id);
    }

    public function test_project_family_endpoint_denies_unscoped_family_project_selection(): void
    {
        $allowed = $this->project('Diplomasi A', 'diplomasi-a', 'diplomasi360');
        $blocked = $this->project('Diplomasi B', 'diplomasi-b', 'diplomasi360');

        $actor = $this->userWithProjectScope('family_one_project_viewer', 'projects.internships.view', [$allowed->id]);
        Sanctum::actingAs($actor);

        $this->getJson("/api/panel/project-families/diplomasi360?project_id={$blocked->id}")
            ->assertForbidden();
    }

    private function project(string $name, string $slug, string $type): Project
    {
        return Project::query()->create([
            'name' => $name,
            'slug' => $slug,
            'type' => $type,
            'status' => 'active',
        ]);
    }

    /**
     * @param  list<int>  $projectIds
     */
    private function userWithProjectScope(string $roleName, string $permissionName, array $projectIds): User
    {
        Permission::findOrCreate($permissionName, 'web');

        $role = Role::findOrCreate($roleName, 'web');
        $role->givePermissionTo($permissionName);

        RolePermissionScope::query()->create([
            'role_name' => $roleName,
            'permission_name' => $permissionName,
            'scope_type' => 'selected_projects',
            'scope_payload' => ['project_ids' => $projectIds],
        ]);

        $user = User::factory()->create([
            'role' => 'visitor',
            'surname' => $roleName,
            'email' => "{$roleName}@test.local",
        ]);
        $user->assignRole($roleName);

        return $user;
    }
}
