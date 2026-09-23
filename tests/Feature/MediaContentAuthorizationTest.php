<?php

namespace Tests\Feature;

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitMembership;
use App\Models\Period;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use App\Services\CoordinationUnitBackfillService;
use App\Services\CoordinationUnitPermissionRuleSyncService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MediaContentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $coordinator;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->project = Project::query()->create([
            'name' => 'Media Scope Project',
            'slug' => 'media-scope-project',
            'type' => 'other',
            'status' => 'active',
            'application_open' => false,
            'next_application_date' => now()->addMonth()->toDateString(),
            'has_interview' => false,
        ]);
        Period::query()->create([
            'project_id' => $this->project->id,
            'name' => '2026 Media',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        app(CoordinationUnitBackfillService::class)->execute(true);
        app(CoordinationUnitPermissionRuleSyncService::class)->execute(true);
        $media = CoordinationUnit::query()->where('code', 'service_media')->firstOrFail();

        $this->coordinator = $this->authority('coordinator', 'Media Coordinator');
        $this->staff = $this->authority('staff', 'Media Staff');
        $this->membership($media, $this->coordinator, CoordinationUnitMembership::POSITION_COORDINATOR);
        $this->membership($media, $this->staff, CoordinationUnitMembership::POSITION_STAFF);

        config()->set('coordination_authorization.mode', 'enforce');
    }

    private function authority(string $role, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'surname' => 'User',
            'role' => $role,
            'status' => 'active',
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function membership(CoordinationUnit $unit, User $user, string $position): void
    {
        CoordinationUnitMembership::query()->create([
            'unit_id' => $unit->id,
            'user_id' => $user->id,
            'position' => $position,
            'is_primary' => true,
            'status' => CoordinationUnitMembership::STATUS_ACTIVE,
        ]);
    }

    public function test_media_coordinator_can_edit_public_content_without_structural_project_leakage(): void
    {
        Sanctum::actingAs($this->coordinator);

        $this->getJson('/api/panel/projects/manageable?permission=projects.public_content.view')
            ->assertOk()
            ->assertJsonPath('projects.0.id', $this->project->id)
            ->assertJsonMissingPath('projects.0.slug')
            ->assertJsonMissingPath('projects.0.participant_summary')
            ->assertJsonMissingPath('projects.0.is_application_open')
            ->assertJsonMissingPath('projects.0.periods.0.start_date');

        $this->getJson("/api/panel/projects/{$this->project->id}/content")
            ->assertOk()
            ->assertJsonPath('capabilities.update_public_content', true)
            ->assertJsonPath('capabilities.update_gallery', true)
            ->assertJsonPath('capabilities.update_structure', false)
            ->assertJsonMissingPath('editable.slug')
            ->assertJsonMissingPath('editable.type')
            ->assertJsonMissingPath('editable.application_open');

        $this->patchJson("/api/panel/projects/{$this->project->id}/public-content", [
            'short_description' => 'Yeni kamusal ozet',
            'description' => 'Yeni kamusal aciklama',
            'cover_image_path' => 'kademe-media/projects/new-cover.jpg',
            'slug' => 'degistirilemez',
        ])->assertOk();

        $this->putJson("/api/panel/projects/{$this->project->id}/gallery", [
            'gallery_paths' => [[
                'path' => 'kademe-media/projects/gallery.jpg',
                'caption' => 'Media galerisi',
            ]],
        ])->assertOk();

        $this->project->refresh();
        $this->assertSame('media-scope-project', $this->project->slug);
        $this->assertSame('Yeni kamusal ozet', $this->project->short_description);
        $this->assertSame('Media galerisi', $this->project->gallery_paths[0]['caption']);

        $this->putJson("/api/panel/projects/{$this->project->id}/content", [
            'name' => 'Yapisal Degisiklik',
            'slug' => 'yapisal-degisiklik',
            'type' => 'other',
        ])->assertForbidden();

        $this->getJson('/api/panel/financials')->assertForbidden();
        $this->getJson('/api/panel/participants')->assertForbidden();
        $this->getJson('/api/panel/site-settings')->assertForbidden();
    }

    public function test_blog_publish_is_coordinator_only_and_scoped_users_cannot_create_global_announcements(): void
    {
        Sanctum::actingAs($this->coordinator);

        $published = $this->postJson('/api/panel/content/blogs', [
            'title' => 'Yayindaki Proje Haberi',
            'slug' => 'yayindaki-proje-haberi',
            'content' => 'Koordinator tarafindan yayimlandi.',
            'project_id' => $this->project->id,
            'status' => 'published',
        ])->assertCreated()->json('blog');

        $this->postJson('/api/panel/announcements', [
            'title' => 'Global Olmamali',
            'content' => 'Proje baglantisi yok.',
        ])->assertUnprocessable();

        $this->postJson('/api/panel/announcements', [
            'title' => 'Proje Duyurusu',
            'content' => 'Yalnizca sorumluluk projesi.',
            'project_id' => $this->project->id,
        ])->assertCreated();

        Sanctum::actingAs($this->staff);

        $this->postJson('/api/panel/content/blogs', [
            'title' => 'Personel Taslagi',
            'slug' => 'personel-taslagi',
            'content' => 'Yayina alinmayi bekler.',
            'project_id' => $this->project->id,
            'status' => 'draft',
        ])->assertCreated();

        $this->postJson('/api/panel/content/blogs', [
            'title' => 'Personel Yayini',
            'slug' => 'personel-yayini',
            'content' => 'Yetkisiz yayin denemesi.',
            'project_id' => $this->project->id,
            'status' => 'published',
        ])->assertForbidden();

        $this->putJson('/api/panel/content/blogs/'.$published['id'], [
            'title' => $published['title'],
            'slug' => $published['slug'],
            'content' => 'Yayindaki icerigi personel degistiremez.',
            'project_id' => $this->project->id,
            'status' => 'published',
        ])->assertForbidden();

        $this->getJson('/api/panel/content')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $published['id'],
                'publish' => false,
                'update' => false,
            ]);
    }

    public function test_media_staff_can_manage_program_photos_but_cannot_change_program_core_fields(): void
    {
        $period = Period::query()->where('project_id', $this->project->id)->firstOrFail();
        $program = Program::query()->create([
            'project_id' => $this->project->id,
            'period_id' => $period->id,
            'title' => 'Media Programi',
            'start_at' => now(),
            'end_at' => now()->addHour(),
            'status' => 'active',
        ]);
        Storage::fake(config('filesystems.media_disk', config('filesystems.default', 'public')));
        Sanctum::actingAs($this->staff);

        $this->post("/api/panel/programs/{$program->id}/photos", [
            'photo' => UploadedFile::fake()->createWithContent(
                'program.png',
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
            ),
            'caption' => 'Program acilisi',
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('photo.caption', 'Program acilisi');

        $this->putJson("/api/panel/programs/{$program->id}", [
            'title' => 'Yetkisiz baslik degisikligi',
        ])->assertForbidden();

        $this->assertSame('Media Programi', $program->fresh()->title);
    }
}
