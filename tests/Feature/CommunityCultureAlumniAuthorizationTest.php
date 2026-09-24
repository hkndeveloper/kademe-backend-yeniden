<?php

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\Period;
use App\Models\Project;
use App\Models\User;
use App\Services\PermissionResolver;
use App\Support\PanelModuleCatalog;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommunityCultureAlumniAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.media_disk' => 'public']);
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        config()->set('coordination_authorization.mode', 'enforce');
    }

    public function test_community_culture_can_manage_alumni_across_its_six_projects_with_role_boundaries(): void
    {
        $coordinator = User::query()->where('email', 'demo.coordinator.community.culture@kademe.org')->firstOrFail();
        $staff = User::query()->where('email', 'demo.staff.community.culture@kademe.org')->firstOrFail();
        $projects = Project::query()->orderBy('id')->take(6)->get();
        $this->assertCount(6, $projects);
        $resolver = app(PermissionResolver::class);

        foreach ($projects as $project) {
            foreach (['projects.alumni.view', 'projects.alumni.manage', 'projects.student_cv.view', 'certificates.create', 'alumni_opportunities.manage'] as $permission) {
                $this->assertTrue($resolver->canAccessProject($coordinator, $permission, (int) $project->id), "{$permission} / {$project->id}");
            }
            $this->assertTrue($resolver->canAccessProject($staff, 'projects.alumni.view', (int) $project->id));
            $this->assertFalse($resolver->canAccessProject($staff, 'projects.participants.manage', (int) $project->id));
            $this->assertFalse($resolver->canAccessProject($coordinator, 'projects.participants.manage', (int) $project->id));
        }

        $moduleIds = collect(app(PanelModuleCatalog::class)->visibleFor($coordinator)['modules'])->pluck('id')->all();
        foreach (['participants', 'certificates', 'alumni_opportunities_panel'] as $moduleId) {
            $this->assertContains($moduleId, $moduleIds);
        }

        $outside = Project::query()->create(['name' => 'Scope Disi', 'slug' => 'scope-disi', 'type' => 'other', 'status' => 'active']);
        $period = Period::query()->create([
            'project_id' => $outside->id,
            'name' => 'Scope Disi Donem',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $outsideStudent = User::factory()->create(['surname' => 'Dis Proje', 'role' => 'student', 'status' => 'active']);
        $outsideStudent->assignRole('student');
        $outsideParticipant = Participant::query()->create([
            'user_id' => $outsideStudent->id,
            'project_id' => $outside->id,
            'period_id' => $period->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($coordinator);
        $this->getJson('/api/panel/participants')->assertOk()->assertJsonCount(6, 'projects')->assertJsonCount(12, 'participants');
        $this->getJson('/api/panel/certificates')->assertOk();
        $this->getJson('/api/panel/alumni-opportunities')->assertOk();
        $this->getJson('/api/panel/participants?project_id='.$outside->id)->assertForbidden();
        $this->patchJson("/api/panel/participants/{$outsideParticipant->id}/graduation", ['graduation_status' => 'graduated'])->assertForbidden();
        $this->postJson('/api/panel/alumni-opportunities', ['title' => 'Kapsam disi', 'kind' => 'job', 'project_id' => $outside->id])->assertForbidden();
        $this->postJson('/api/panel/alumni-opportunities', ['title' => 'Projesiz', 'kind' => 'job'])->assertForbidden();
        $this->postJson('/api/panel/certificates', [
            'user_id' => $outsideStudent->id,
            'project_id' => $projects[0]->id,
            'type' => 'graduation',
        ])->assertUnprocessable();

        $project = $projects[1];
        $student = User::query()->where('email', sprintf('demo.student.p%02d@kademe.org', $project->id))->firstOrFail();
        $participant = Participant::query()->where('project_id', $project->id)->where('user_id', $student->id)->firstOrFail();
        $this->patchJson("/api/panel/participants/{$participant->id}/graduation", ['graduation_status' => 'graduated'])->assertOk();
        $this->assertSame('graduated', $participant->fresh()->graduation_status);
        $this->postJson('/api/panel/credits/adjust', ['participant_id' => $participant->id, 'amount' => 5, 'reason' => 'Yetki siniri'])->assertForbidden();
        $this->postJson('/api/panel/alumni-opportunities', [
            'title' => 'Mezun agi', 'kind' => 'network', 'project_id' => $project->id,
            'target_audience' => ['alumni'],
        ])->assertCreated();

        $alumni = User::query()->where('email', sprintf('demo.alumni.p%02d@kademe.org', $project->id))->firstOrFail();
        $alumniParticipant = Participant::query()->where('project_id', $project->id)->where('user_id', $alumni->id)->firstOrFail();
        $this->getJson("/api/panel/participants/{$alumniParticipant->id}/cv")->assertOk();
        $this->patchJson("/api/panel/participants/{$alumniParticipant->id}/public-visibility", [
            'public_alumni_visible' => true,
        ])->assertOk()->assertJsonPath('participant.user.public_alumni_visible', true);
        $this->postJson('/api/panel/certificates', [
            'user_id' => $alumni->id,
            'project_id' => $project->id,
            'period_id' => $alumniParticipant->period_id,
            'type' => 'achievement',
        ])->assertCreated()->assertJsonPath('certificate.type', 'achievement');

        Sanctum::actingAs($staff);
        $staffParticipants = $this->getJson('/api/panel/participants')->assertOk()->assertJsonCount(6, 'projects')->assertJsonCount(7, 'participants');
        $this->assertTrue(collect($staffParticipants->json('participants'))->every(fn (array $row) => $row['graduation_status'] === 'graduated'));
        $this->getJson('/api/panel/certificates')->assertOk();
        $this->getJson('/api/panel/alumni-opportunities')->assertOk();
        $this->patchJson("/api/panel/participants/{$participant->id}/graduation", ['graduation_status' => 'not_completed', 'graduation_note' => 'Test'])->assertForbidden();
        $this->postJson('/api/panel/alumni-opportunities', ['title' => 'Yetkisiz', 'kind' => 'job', 'project_id' => $project->id])->assertForbidden();
    }
}
