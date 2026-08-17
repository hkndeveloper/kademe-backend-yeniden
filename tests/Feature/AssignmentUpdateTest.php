<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentAttachment;
use App\Models\AssignmentSubmission;
use App\Models\Period;
use App\Models\Project;
use App\Models\RolePermissionScope;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AssignmentUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_panel_assignment_update_edits_fields_and_appends_attachment(): void
    {
        $project = $this->project('assignment-edit-project');
        $period = $this->period($project);
        $actor = $this->actorWithAssignmentUpdateAccess($project);
        $assignment = $this->assignment($project, $period, $actor, ['title' => 'Eski Odev']);
        $disk = config('filesystems.media_disk', config('filesystems.default', 'public'));
        Storage::fake($disk);

        $response = $this->withHeader('Accept', 'application/json')->post("/api/panel/assignments/{$assignment->id}", [
            '_method' => 'PUT',
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Yeni Odev',
            'description' => 'Guncellenen aciklama',
            'due_date' => '2026-08-15T10:30:00+03:00',
            'attachments' => [UploadedFile::fake()->create('brief.pdf', 10, 'application/pdf')],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Odev guncellendi.')
            ->assertJsonPath('assignment.title', 'Yeni Odev')
            ->assertJsonPath('assignment.attachments.0.original_name', 'brief.pdf');

        $this->assertDatabaseHas('assignments', [
            'id' => $assignment->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Yeni Odev',
            'description' => 'Guncellenen aciklama',
        ]);

        $attachment = AssignmentAttachment::query()->where('assignment_id', $assignment->id)->first();
        $this->assertNotNull($attachment);
        Storage::disk($disk)->assertExists($attachment->file_path);
    }

    public function test_panel_assignment_update_blocks_project_or_period_change_when_submission_exists(): void
    {
        $project = $this->project('assignment-locked-project');
        $period = $this->period($project);
        $otherProject = $this->project('assignment-other-project');
        $otherPeriod = $this->period($otherProject);
        $actor = $this->actorWithAllAssignmentUpdateAccess();
        $assignment = $this->assignment($project, $period, $actor);

        AssignmentSubmission::query()->create([
            'assignment_id' => $assignment->id,
            'user_id' => User::factory()->create(['role' => 'student', 'surname' => 'Student'])->id,
            'status' => 'submitted',
        ]);

        $this->putJson("/api/panel/assignments/{$assignment->id}", [
            'project_id' => $otherProject->id,
            'period_id' => $otherPeriod->id,
            'title' => 'Tasima Denemesi',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Teslimi olan odevin proje veya donemi degistirilemez.');

        $this->assertDatabaseHas('assignments', [
            'id' => $assignment->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => $assignment->title,
        ]);
    }

    public function test_panel_assignment_update_rejects_unmanageable_project(): void
    {
        $managedProject = $this->project('assignment-managed-project');
        $otherProject = $this->project('assignment-forbidden-project');
        $otherPeriod = $this->period($otherProject);
        $actor = $this->actorWithAssignmentUpdateAccess($managedProject);
        $assignment = $this->assignment($otherProject, $otherPeriod, $actor);

        $this->putJson("/api/panel/assignments/{$assignment->id}", [
            'project_id' => $otherProject->id,
            'period_id' => $otherPeriod->id,
            'title' => 'Yetkisiz Guncelleme',
        ])->assertForbidden();

        $this->assertDatabaseHas('assignments', [
            'id' => $assignment->id,
            'title' => $assignment->title,
        ]);
    }

    private function actorWithAssignmentUpdateAccess(Project $project): User
    {
        $actor = User::factory()->create([
            'name' => 'Assignment',
            'surname' => 'Editor',
            'role' => 'coordinator',
        ]);
        $project->coordinators()->attach($actor->id);

        $role = Role::findOrCreate('assignment_editor', 'web');
        Permission::findOrCreate('assignments.update', 'web');
        $role->givePermissionTo('assignments.update');
        RolePermissionScope::query()->updateOrCreate(
            ['role_name' => 'assignment_editor', 'permission_name' => 'assignments.update'],
            ['scope_type' => 'own_projects', 'scope_payload' => []]
        );
        $actor->assignRole($role);
        Sanctum::actingAs($actor);

        return $actor;
    }

    private function actorWithAllAssignmentUpdateAccess(): User
    {
        $actor = User::factory()->create([
            'name' => 'Assignment',
            'surname' => 'Supervisor',
            'role' => 'coordinator',
        ]);

        $role = Role::findOrCreate('assignment_supervisor', 'web');
        Permission::findOrCreate('assignments.update', 'web');
        $role->givePermissionTo('assignments.update');
        RolePermissionScope::query()->updateOrCreate(
            ['role_name' => 'assignment_supervisor', 'permission_name' => 'assignments.update'],
            ['scope_type' => 'all', 'scope_payload' => []]
        );
        $actor->assignRole($role);
        Sanctum::actingAs($actor);

        return $actor;
    }

    private function assignment(Project $project, Period $period, User $actor, array $overrides = []): Assignment
    {
        return Assignment::query()->create(array_merge([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Odev',
            'description' => 'Aciklama',
            'created_by' => $actor->id,
        ], $overrides));
    }

    private function period(Project $project): Period
    {
        return Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Aktif Donem',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'active',
        ]);
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
}