<?php

namespace Tests\Feature;

use App\Models\Period;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProjectSpecialModulesMentorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_pergel_special_modules_list_after_mentor_is_created_with_blank_optional_fields(): void
    {
        $admin = User::factory()->create([
            'name' => 'Panel',
            'surname' => 'Admin',
            'email' => 'panel-mentor-admin@test.local',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $admin->assignRole(Role::findByName('super_admin', 'web'));

        $project = Project::query()->create([
            'name' => 'Pergel Fellowship',
            'slug' => 'pergel-fellowship-'.uniqid(),
            'type' => 'pergel_fellowship',
            'status' => 'active',
        ]);

        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Pergel',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/panel/projects/{$project->id}/special-modules/mentors", [
            'name' => 'Bos Alanli Mentor',
            'expertise' => '',
            'bio' => '',
            'photo_path' => '',
        ])->assertCreated();

        $this->getJson("/api/panel/projects/{$project->id}/special-modules?period_id={$period->id}")
            ->assertOk()
            ->assertJsonPath('project.id', $project->id)
            ->assertJsonPath('mentors.0.name', 'Bos Alanli Mentor')
            ->assertJsonPath('applicable_modules.1', 'mentors');
    }
}