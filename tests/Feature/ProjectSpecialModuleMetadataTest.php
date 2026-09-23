<?php

namespace Tests\Feature;

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitPermissionRule;
use App\Models\Project;
use App\Models\User;
use App\Services\CoordinationUnitBackfillService;
use App\Services\CoordinationUnitPermissionRuleSyncService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectSpecialModuleMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_metadata_update_reconciles_the_project_family_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['role' => 'super_admin', 'surname' => 'Admin']);
        $admin->assignRole('super_admin');
        Sanctum::actingAs($admin);

        $project = Project::query()->create([
            'name' => 'Diplomasi360',
            'slug' => 'metadata-api-project',
            'type' => 'diplomasi360',
            'status' => 'active',
        ]);
        app(CoordinationUnitBackfillService::class)->execute(true);
        app(CoordinationUnitPermissionRuleSyncService::class)->execute(true);
        $unit = CoordinationUnit::query()->where('project_id', $project->id)->firstOrFail();

        $this->getJson("/api/panel/projects/{$project->id}/content")
            ->assertOk()
            ->assertJsonPath('editable.special_modules', null)
            ->assertJsonPath('editable.special_modules_inherited', true)
            ->assertJsonPath('editable.applicable_special_modules.1', 'internships')
            ->assertJsonFragment(['key' => 'assignments', 'label' => 'Pergel Odevleri']);

        $this->putJson("/api/panel/projects/{$project->id}/content", [
            'name' => $project->name,
            'slug' => $project->slug,
            'type' => $project->type,
            'special_modules' => ['digital_bohca', 'assignments'],
            'short_description' => null,
            'description' => null,
            'cover_image_path' => null,
            'gallery_paths' => [],
        ])
            ->assertOk()
            ->assertJsonPath('editable.special_modules.1', 'assignments')
            ->assertJsonPath('editable.special_modules_inherited', false)
            ->assertJsonPath('editable.applicable_special_modules.1', 'assignments');

        $this->assertTrue(CoordinationUnitPermissionRule::query()
            ->active()
            ->where('unit_id', $unit->id)
            ->where('position', 'coordinator')
            ->where('permission_name', 'assignments.view')
            ->exists());
        $this->assertFalse(CoordinationUnitPermissionRule::query()
            ->active()
            ->where('unit_id', $unit->id)
            ->where('position', 'coordinator')
            ->where('permission_name', 'projects.internships.view')
            ->exists());
    }
}
