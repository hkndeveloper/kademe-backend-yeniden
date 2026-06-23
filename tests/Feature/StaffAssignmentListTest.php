<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StaffAssignmentListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_staff_list_stays_available_after_assigning_a_project_to_staff(): void
    {
        $admin = User::factory()->create([
            'name' => 'Panel',
            'surname' => 'Admin',
            'email' => 'panel-admin@test.local',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $admin->assignRole(Role::findByName('super_admin', 'web'));

        $staff = User::factory()->create([
            'name' => 'Assigned',
            'surname' => 'Staff',
            'email' => 'assigned-list@test.local',
            'role' => 'staff',
            'status' => 'active',
        ]);
        $staff->assignRole(Role::findByName('staff', 'web'));

        $project = Project::query()->create([
            'name' => 'Assigned List Project',
            'slug' => 'assigned-list-project-'.uniqid(),
            'type' => 'other',
            'status' => 'active',
        ]);

        Sanctum::actingAs($admin);

        $this->putJson("/api/panel/staff/{$staff->id}/projects", [
            'coordinated_project_ids' => [],
            'assigned_project_ids' => [$project->id],
        ])->assertOk();

        $response = $this->getJson('/api/panel/staff?search=&role=');

        $response->assertOk()
            ->assertJsonPath('staff.data.0.projects.0.id', $project->id)
            ->assertJsonPath('staff.data.0.projects.0.assignment_type', 'staff');
    }
}
