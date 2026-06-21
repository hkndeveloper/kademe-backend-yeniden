<?php

namespace Tests\Feature;

use App\Models\Period;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectGalleryMetadataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_project_gallery_accepts_metadata_items_and_keeps_public_gallery_urls(): void
    {
        $admin = User::factory()->create([
            'role' => 'super_admin',
            'surname' => 'Admin',
        ]);
        $admin->assignRole('super_admin');
        Sanctum::actingAs($admin);

        $project = Project::query()->create([
            'name' => 'Galeri Projesi',
            'slug' => 'galeri-projesi',
            'type' => 'other',
            'status' => 'active',
            'application_open' => false,
            'next_application_date' => now()->addMonth()->toDateString(),
            'has_interview' => false,
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Bahar',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        $this->putJson("/api/panel/projects/{$project->id}/content", [
            'name' => $project->name,
            'slug' => $project->slug,
            'type' => $project->type,
            'short_description' => 'Kisa tanitim',
            'description' => 'Detayli tanitim',
            'cover_image_path' => 'kademe-media/projects/cover.jpg',
            'gallery_paths' => [
                'kademe-media/projects/legacy.jpg',
                [
                    'path' => 'kademe-media/projects/bahar.jpg',
                    'caption' => 'Bahar bulusmasi',
                    'year' => '2026',
                    'period_id' => $period->id,
                ],
            ],
            'application_open' => false,
            'next_application_date' => now()->addMonth()->toDateString(),
            'has_interview' => false,
            'quota' => null,
        ])
            ->assertOk()
            ->assertJsonPath('editable.gallery_paths.0.path', 'kademe-media/projects/legacy.jpg')
            ->assertJsonPath('editable.gallery_paths.1.caption', 'Bahar bulusmasi')
            ->assertJsonPath('project.gallery_items.1.period_name', '2026 Bahar');

        $this->getJson("/api/projects/{$project->slug}")
            ->assertOk()
            ->assertJsonPath('project.gallery_items.1.caption', 'Bahar bulusmasi')
            ->assertJsonPath('project.gallery_items.1.period_name', '2026 Bahar')
            ->assertJsonCount(2, 'project.gallery');
    }
}
