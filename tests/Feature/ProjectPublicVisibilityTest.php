<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\Period;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use App\Models\RolePermissionScope;
use Spatie\Permission\Models\Role;
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

    public function test_archived_program_public_visibility_changes_without_mutating_program_or_unlocking_edits(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $project = Project::query()->create(['name' => 'Arsiv Projesi', 'slug' => 'arsiv-projesi', 'type' => 'other', 'status' => 'active', 'is_public' => true]);
        $period = Period::query()->create([
            'project_id' => $project->id, 'name' => 'Eski Donem',
            'start_date' => now()->subMonths(3), 'end_date' => now()->subMonth(), 'status' => 'completed',
        ]);
        $program = Program::query()->create([
            'project_id' => $project->id, 'period_id' => $period->id, 'title' => 'Eski Program',
            'start_at' => now()->subWeeks(2), 'end_at' => now()->subWeeks(2)->addHour(),
            'status' => 'completed', 'is_public' => true,
        ]);
        $original = $program->fresh()->getRawOriginal();
        $admin = User::factory()->create(['surname' => 'Yonetici', 'role' => 'super_admin', 'status' => 'active']);
        $admin->assignRole('super_admin');
        Sanctum::actingAs($admin);

        $this->getJson('/api/activities')->assertJsonCount(1, 'programs.data');
        $this->patchJson("/api/panel/programs/{$program->id}/visibility", ['is_public' => false])
            ->assertOk()->assertJsonPath('program.is_public', false);
        $this->getJson('/api/activities')->assertJsonCount(0, 'programs.data');
        $this->getJson('/api/activities/'.$program->id)->assertNotFound();
        $this->getJson('/api/projects/'.$project->slug)->assertJsonPath('programs.summary.total', 0);
        $this->getJson('/api/homepage')->assertJsonCount(0, 'programs');
        $this->getJson('/api/panel/programs/'.$program->id)->assertJsonPath('program.is_public', false);
        $this->patchJson("/api/panel/programs/{$program->id}/visibility", ['is_featured' => true])->assertUnprocessable();
        $this->putJson('/api/panel/programs/'.$program->id, ['title' => 'Degistirildi'])->assertStatus(423);
        $this->assertSame($original, $program->fresh()->getRawOriginal());

        $this->patchJson("/api/panel/programs/{$program->id}/visibility", ['is_public' => true])
            ->assertOk()->assertJsonPath('program.is_public', true);
        $this->getJson('/api/activities')->assertJsonCount(1, 'programs.data');
        $this->getJson('/api/activities/'.$program->id)->assertOk();
        $this->getJson('/api/projects/'.$project->slug)->assertJsonPath('programs.summary.total', 1);
    }

    public function test_archived_program_visibility_obeys_project_permission_scope(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $owned = Project::query()->create(['name' => 'Yetkili Proje', 'slug' => 'yetkili-proje', 'type' => 'other', 'status' => 'active']);
        $other = Project::query()->create(['name' => 'Diger Proje', 'slug' => 'diger-proje', 'type' => 'other', 'status' => 'active']);
        $programs = collect([$owned, $other])->map(function (Project $project) {
            $period = Period::query()->create([
                'project_id' => $project->id, 'name' => 'Tamamlanan',
                'start_date' => now()->subMonths(2), 'end_date' => now()->subMonth(), 'status' => 'completed',
            ]);
            return Program::query()->create([
                'project_id' => $project->id, 'period_id' => $period->id, 'title' => $project->name,
                'start_at' => now()->subWeek(), 'end_at' => now()->subWeek()->addHour(),
                'status' => 'completed', 'is_public' => true,
            ]);
        });
        $role = Role::findOrCreate('visibility_project_coordinator', 'web');
        $role->givePermissionTo('programs.update');
        RolePermissionScope::query()->create([
            'role_name' => $role->name, 'permission_name' => 'programs.update',
            'scope_type' => 'selected_projects', 'scope_payload' => ['project_ids' => [$owned->id]],
        ]);
        $coordinator = User::factory()->create(['surname' => 'Koordinator', 'role' => 'coordinator', 'status' => 'active']);
        $coordinator->assignRole($role);
        Sanctum::actingAs($coordinator);

        $this->patchJson('/api/panel/programs/'.$programs[0]->id.'/visibility', ['is_public' => false])->assertOk();
        $this->patchJson('/api/panel/programs/'.$programs[1]->id.'/visibility', ['is_public' => false])->assertForbidden();
        $this->assertDatabaseHas('program_public_visibility_overrides', ['program_id' => $programs[0]->id, 'is_public' => false]);
        $this->assertDatabaseMissing('program_public_visibility_overrides', ['program_id' => $programs[1]->id]);
    }

    public function test_legacy_passive_period_allows_only_public_visibility_change(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $project = Project::query()->create([
            'name' => 'Eski Donem Projesi', 'slug' => 'eski-donem-projesi',
            'type' => 'other', 'status' => 'active', 'is_public' => true,
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id, 'name' => 'Eski Pasif Donem',
            'start_date' => now()->subMonths(3), 'end_date' => now()->subMonth(), 'status' => 'passive',
        ]);
        $program = Program::query()->create([
            'project_id' => $project->id, 'period_id' => $period->id,
            'title' => 'Eski Donem Etkinligi', 'start_at' => now()->subWeek(),
            'end_at' => now()->subWeek()->addHour(), 'status' => 'completed', 'is_public' => true,
        ]);
        $original = $program->fresh()->getRawOriginal();
        $admin = User::factory()->create(['surname' => 'Yonetici', 'role' => 'super_admin', 'status' => 'active']);
        $admin->assignRole('super_admin');
        Sanctum::actingAs($admin);

        $this->getJson('/api/activities')->assertJsonCount(1, 'programs.data');
        $this->patchJson("/api/panel/programs/{$program->id}/visibility", ['is_public' => false])
            ->assertOk()->assertJsonPath('program.is_public', false);
        $this->getJson('/api/activities')->assertJsonCount(0, 'programs.data');
        $this->getJson('/api/panel/programs/'.$program->id)->assertJsonPath('program.is_public', false);
        $this->patchJson("/api/panel/programs/{$program->id}/visibility", ['is_featured' => true])->assertUnprocessable();
        $this->putJson('/api/panel/programs/'.$program->id, ['title' => 'Degistirildi'])->assertStatus(423);
        $this->assertSame($original, $program->fresh()->getRawOriginal());
        $this->patchJson("/api/panel/programs/{$program->id}/visibility", ['is_public' => true])
            ->assertOk()->assertJsonPath('program.is_public', true);
        $this->getJson('/api/activities')->assertJsonCount(1, 'programs.data');
    }
}
