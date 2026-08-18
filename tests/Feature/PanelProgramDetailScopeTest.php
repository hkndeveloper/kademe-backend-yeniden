<?php

namespace Tests\Feature;

use App\Models\Period;
use App\Models\Program;
use App\Models\Project;
use App\Models\RolePermissionScope;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PanelProgramDetailScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_program_detail_respects_programs_view_selected_project_scope(): void
    {
        $allowedProject = $this->project('allowed-program-project');
        $outsideProject = $this->project('outside-program-project');
        $allowedProgram = $this->program($allowedProject, 'Kapsamdaki Program');
        $outsideProgram = $this->program($outsideProject, 'Kapsam Disi Program');

        $role = Role::findOrCreate('selected_program_detail_viewer', 'web');
        Permission::findOrCreate('programs.view', 'web');
        $role->givePermissionTo('programs.view');
        RolePermissionScope::query()->create([
            'role_name' => $role->name,
            'permission_name' => 'programs.view',
            'scope_type' => 'selected_projects',
            'scope_payload' => ['project_ids' => [$allowedProject->id]],
        ]);

        $actor = User::factory()->create([
            'name' => 'Program',
            'surname' => 'Viewer',
            'role' => 'coordinator',
        ]);
        $actor->assignRole($role);
        Sanctum::actingAs($actor);

        $this->getJson("/api/panel/programs/{$allowedProgram->id}")
            ->assertOk()
            ->assertJsonPath('program.id', $allowedProgram->id)
            ->assertJsonPath('program.title', 'Kapsamdaki Program')
            ->assertJsonPath('program.project.id', $allowedProject->id)
            ->assertJsonPath('program.attendance_count', 0)
            ->assertJsonPath('program.feedback_count', 0);

        $this->getJson("/api/panel/programs/{$outsideProgram->id}")
            ->assertForbidden();
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

    private function program(Project $project, string $title): Program
    {
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => 'Aktif Donem',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        return Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => $title,
            'description' => 'Detay testi',
            'location' => 'KADEME',
            'radius_meters' => 100,
            'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addHour(),
            'credit_deduction' => 10,
            'target_audience' => ['student'],
            'status' => 'scheduled',
        ]);
    }
}
