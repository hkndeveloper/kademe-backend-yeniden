<?php

namespace Tests\Feature;

use App\Models\ApplicationWindow;
use App\Models\Period;
use App\Models\Project;
use App\Models\RolePermissionScope;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApplicationIntakeManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_scoped_coordinator_can_manage_only_own_project_application_window(): void
    {
        $managedProject = $this->project('managed-intake');
        $managedPeriod = $this->period($managedProject);
        $outsideProject = $this->project('outside-intake');
        $outsidePeriod = $this->period($outsideProject);
        $actor = $this->coordinatorFor($managedProject);

        $this->getJson("/api/panel/projects/{$managedProject->id}/application-settings")
            ->assertOk()
            ->assertJsonPath('access.manage', true)
            ->assertJsonPath('selected_period.id', $managedPeriod->id);

        $this->getJson("/api/panel/projects/{$outsideProject->id}/application-settings")
            ->assertForbidden();

        $this->patchJson("/api/panel/projects/{$managedProject->id}/application-settings", [
            'period_id' => $managedPeriod->id,
            'is_open' => true,
            'starts_at' => now()->subMinute()->toISOString(),
            'ends_at' => now()->addWeek()->toISOString(),
            'next_application_date' => null,
            'has_interview' => true,
            'quota' => 25,
            'change_note' => 'Koordinator onayi ile basvuru acildi.',
        ])
            ->assertOk()
            ->assertJsonPath('settings.is_effectively_open', true)
            ->assertJsonPath('settings.opened_by.id', $actor->id)
            ->assertJsonPath('settings.quota', 25);

        $this->assertDatabaseHas('application_windows', [
            'project_id' => $managedProject->id,
            'period_id' => $managedPeriod->id,
            'is_open' => true,
            'opened_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $this->assertDatabaseHas('projects', [
            'id' => $managedProject->id,
            'application_open' => true,
            'has_interview' => true,
            'quota' => 25,
        ]);

        $this->patchJson("/api/panel/projects/{$outsideProject->id}/application-settings", [
            'period_id' => $outsidePeriod->id,
            'is_open' => true,
            'has_interview' => false,
        ])->assertForbidden();
    }

    public function test_scheduled_window_blocks_early_submission_and_links_accepted_submission_to_window(): void
    {
        $project = $this->project('scheduled-intake');
        $period = $this->period($project);
        $this->actingSuperAdmin();

        $this->patchJson("/api/panel/projects/{$project->id}/application-settings", [
            'period_id' => $period->id,
            'is_open' => true,
            'starts_at' => now()->addDay()->toISOString(),
            'ends_at' => now()->addWeek()->toISOString(),
            'has_interview' => false,
            'quota' => null,
            'change_note' => 'Takvimli acilis.',
        ])->assertOk()->assertJsonPath('settings.effective_status', 'scheduled');

        $student = User::factory()->create([
            'surname' => 'Applicant',
            'role' => 'student',
            'status' => 'active',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');
        Sanctum::actingAs($student);

        $this->postJson('/api/applications', ['project_id' => $project->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('project_id');

        $window = ApplicationWindow::query()->where('project_id', $project->id)->firstOrFail();
        $window->update(['starts_at' => now()->subMinute()]);

        $applicationId = $this->postJson('/api/applications', ['project_id' => $project->id])
            ->assertCreated()
            ->assertJsonPath('application.application_window_id', $window->id)
            ->json('application.id');

        $this->assertDatabaseHas('applications', [
            'id' => $applicationId,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'application_window_id' => $window->id,
        ]);
    }

    public function test_closed_active_period_requires_a_next_application_date(): void
    {
        $project = $this->project('closed-intake');
        $period = $this->period($project);
        $this->actingSuperAdmin();

        $this->patchJson("/api/panel/projects/{$project->id}/application-settings", [
            'period_id' => $period->id,
            'is_open' => false,
            'has_interview' => false,
            'quota' => null,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('next_application_date');
    }

    private function actingSuperAdmin(): User
    {
        $actor = User::factory()->create([
            'surname' => 'Intake Admin',
            'role' => 'super_admin',
        ]);
        Role::findOrCreate('super_admin', 'web');
        $actor->assignRole('super_admin');
        Sanctum::actingAs($actor);

        return $actor;
    }

    private function coordinatorFor(Project $project): User
    {
        $actor = User::factory()->create([
            'surname' => 'Intake Coordinator',
            'role' => 'coordinator',
        ]);
        $project->coordinators()->attach($actor->id);

        $role = Role::findOrCreate('coordinator', 'web');
        foreach (['applications.intake.view', 'applications.intake.manage'] as $permissionName) {
            Permission::findOrCreate($permissionName, 'web');
            $role->givePermissionTo($permissionName);
            RolePermissionScope::query()->updateOrCreate(
                ['role_name' => $role->name, 'permission_name' => $permissionName],
                ['scope_type' => 'own_projects', 'scope_payload' => []]
            );
        }
        $actor->assignRole($role);
        Sanctum::actingAs($actor);

        return $actor;
    }

    private function project(string $slug): Project
    {
        return Project::query()->create([
            'name' => str_replace('-', ' ', $slug),
            'slug' => $slug,
            'type' => 'other',
            'status' => 'active',
        ]);
    }

    private function period(Project $project): Period
    {
        return Period::query()->create([
            'project_id' => $project->id,
            'name' => 'Aktif Donem',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
    }
}
