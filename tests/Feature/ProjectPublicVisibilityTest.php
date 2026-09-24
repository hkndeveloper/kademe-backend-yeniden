<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\Period;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectPublicVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_visibility_toggle_hides_and_restores_project_and_its_public_activities(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $project = Project::query()->create([
            'name' => 'Gorunurluk Projesi',
            'slug' => 'gorunurluk-projesi',
            'type' => 'other',
            'status' => 'active',
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => 'Aktif Donem',
            'start_date' => now()->startOfYear(),
            'end_date' => now()->endOfYear(),
            'status' => 'active',
        ]);
        $program = Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Kamusal Etkinlik',
            'start_at' => now()->addDay(),
            'end_at' => now()->addDays(2),
            'status' => 'scheduled',
            'is_public' => true,
        ]);

        $this->getJson('/api/projects')->assertJsonCount(1, 'projects');
        $this->getJson('/api/projects/'.$project->slug)->assertOk();
        $this->getJson('/api/activities')->assertJsonCount(1, 'programs.data');
        $this->getJson('/api/activities/'.$program->id)->assertOk();
        $this->getJson('/api/homepage')->assertJsonCount(1, 'programs');

        $coordinator = User::factory()->create(['surname' => 'Koordinator', 'role' => 'coordinator', 'status' => 'active']);
        $coordinator->assignRole('coordinator');
        Sanctum::actingAs($coordinator);
        $this->patchJson("/api/panel/projects/{$project->id}/visibility", ['is_public' => false])->assertForbidden();

        $admin = User::factory()->create(['surname' => 'Yonetici', 'role' => 'super_admin', 'status' => 'active']);
        $admin->assignRole('super_admin');
        Sanctum::actingAs($admin);
        $this->patchJson("/api/panel/projects/{$project->id}/visibility", ['is_public' => false])
            ->assertOk()->assertJsonPath('project.is_public', false);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'status' => 'active', 'is_public' => false]);

        $this->getJson('/api/projects')->assertJsonCount(0, 'projects');
        $this->getJson('/api/projects/'.$project->slug)->assertNotFound();
        $this->getJson('/api/activities')->assertJsonCount(0, 'programs.data');
        $this->getJson('/api/activities/'.$program->id)->assertNotFound();
        $hiddenHomepage = $this->getJson('/api/homepage')->assertJsonCount(0, 'projects')->assertJsonCount(0, 'programs');
        $this->assertSame('0', collect($hiddenHomepage->json('computed_homepage_stats'))->firstWhere('label', 'Aktif Proje')['value']);
        $this->assertSame('0', collect($hiddenHomepage->json('computed_homepage_stats'))->firstWhere('label', 'Yaklasan Faaliyet')['value']);
        $this->getJson('/api/panel/projects/manageable')->assertJsonPath('projects.0.is_public', false);

        $this->patchJson("/api/panel/projects/{$project->id}/visibility", ['is_public' => true])
            ->assertOk()->assertJsonPath('project.is_public', true);
        $this->getJson('/api/projects')->assertJsonCount(1, 'projects');
        $this->getJson('/api/activities')->assertJsonCount(1, 'programs.data');

        $project->update(['status' => 'passive']);
        $this->getJson('/api/activities')->assertJsonCount(0, 'programs.data');
    }
}
