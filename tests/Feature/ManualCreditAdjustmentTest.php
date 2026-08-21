<?php

namespace Tests\Feature;

use App\Models\CreditLog;
use App\Models\Participant;
use App\Models\Period;
use App\Models\Project;
use App\Models\RolePermissionScope;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ManualCreditAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_panel_credit_adjustment_updates_participant_and_returns_log(): void
    {
        $project = $this->project('credit-adjust-project');
        $period = $this->period($project);
        $participant = $this->participant($project, $period, 100);
        $actor = $this->actorWithParticipantManageAccess($project);

        $response = $this->postJson('/api/panel/credits/adjust', [
            'participant_id' => $participant->id,
            'amount' => 15,
            'reason' => 'Aylik manuel kredi guncellemesi',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Kredi basariyla guncellendi.')
            ->assertJsonPath('current_credit', 115)
            ->assertJsonPath('log.amount', 15)
            ->assertJsonPath('log.type', 'manual_adjust')
            ->assertJsonPath('log.created_by', $actor->id);

        $this->assertDatabaseHas('participants', [
            'id' => $participant->id,
            'credit' => 115,
        ]);
        $this->assertDatabaseHas('credit_logs', [
            'participant_id' => $participant->id,
            'amount' => 15,
            'type' => 'manual_adjust',
            'reason' => 'Aylik manuel kredi guncellemesi',
            'created_by' => $actor->id,
        ]);
    }

    public function test_panel_credit_adjustment_rejects_unmanageable_project_participant(): void
    {
        $managedProject = $this->project('credit-managed-project');
        $otherProject = $this->project('credit-other-project');
        $period = $this->period($otherProject);
        $participant = $this->participant($otherProject, $period, 80);
        $this->actorWithParticipantManageAccess($managedProject);

        $this->postJson('/api/panel/credits/adjust', [
            'participant_id' => $participant->id,
            'amount' => -10,
            'reason' => 'Yetkisiz deneme',
        ])->assertForbidden();

        $this->assertDatabaseHas('participants', [
            'id' => $participant->id,
            'credit' => 80,
        ]);
        $this->assertSame(0, CreditLog::query()->where('participant_id', $participant->id)->count());
    }

    public function test_panel_credit_adjustment_requires_non_zero_amount(): void
    {
        $project = $this->project('credit-zero-project');
        $period = $this->period($project);
        $participant = $this->participant($project, $period, 70);
        $this->actorWithParticipantManageAccess($project);

        $this->postJson('/api/panel/credits/adjust', [
            'participant_id' => $participant->id,
            'amount' => 0,
            'reason' => 'Gecersiz miktar',
        ])->assertStatus(422);

        $this->assertDatabaseHas('participants', [
            'id' => $participant->id,
            'credit' => 70,
        ]);
    }

    public function test_credit_adjustment_is_resolution_in_closing_but_locked_in_completed_period(): void
    {
        $project = $this->project('credit-lifecycle-project');
        $closing = $this->period($project, 'closing');
        $closingParticipant = $this->participant($project, $closing, 80);
        $actor = $this->actorWithParticipantManageAccess($project);

        $this->postJson('/api/panel/credits/adjust', [
            'participant_id' => $closingParticipant->id,
            'amount' => 5,
            'reason' => 'Kapanis kredi mutabakati',
        ])->assertOk()->assertJsonPath('current_credit', 85);

        $completed = $this->period($project, 'completed');
        $completedParticipant = $this->participant($project, $completed, 70);
        Sanctum::actingAs($actor);

        $this->postJson('/api/panel/credits/adjust', [
            'participant_id' => $completedParticipant->id,
            'amount' => 5,
            'reason' => 'Arsivde yasak olmali',
        ])
            ->assertStatus(423)
            ->assertJsonPath('message', 'Tamamlanmis donem arsiv modundadir. Degisiklik icin arsiv duzeltme yetkisi gerekir.');
    }

    private function actorWithParticipantManageAccess(Project $project): User
    {
        $actor = User::factory()->create([
            'name' => 'Credit',
            'surname' => 'Coordinator',
            'role' => 'coordinator',
        ]);
        $project->coordinators()->attach($actor->id);

        $role = Role::findOrCreate('coordinator', 'web');
        Permission::findOrCreate('projects.participants.manage', 'web');
        $role->givePermissionTo('projects.participants.manage');
        RolePermissionScope::query()->updateOrCreate(
            ['role_name' => 'coordinator', 'permission_name' => 'projects.participants.manage'],
            ['scope_type' => 'own_projects', 'scope_payload' => []]
        );
        $actor->assignRole($role);
        Sanctum::actingAs($actor);

        return $actor;
    }

    private function participant(Project $project, Period $period, int $credit): Participant
    {
        $student = User::factory()->create([
            'surname' => 'Student',
            'role' => 'student',
        ]);

        return Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
            'credit' => $credit,
            'enrolled_at' => now(),
        ]);
    }

    private function period(Project $project, string $status = 'active'): Period
    {
        return Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 '.strtoupper($status).' Donem',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => $status,
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
