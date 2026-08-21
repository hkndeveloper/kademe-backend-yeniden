<?php

namespace Tests\Feature;

use App\Models\Period;
use App\Models\Project;
use App\Models\RolePermissionScope;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PeriodLifecycleScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_global_scope_can_list_view_and_activate_periods_in_any_project(): void
    {
        $admin = $this->userWithRole('super_admin');
        $firstProject = $this->project('global-period-first');
        $secondProject = $this->project('global-period-second');
        $firstPeriod = $this->period($firstProject, 'Ilk Donem');
        $secondPeriod = $this->period($secondProject, 'Ikinci Donem');
        Sanctum::actingAs($admin);

        $this->getJson('/api/panel/periods')
            ->assertOk()
            ->assertJsonCount(2, 'periods');
        $this->getJson("/api/panel/periods/{$secondPeriod->id}")
            ->assertOk()
            ->assertJsonPath('period.id', $secondPeriod->id);
        $this->postJson("/api/panel/periods/{$firstPeriod->id}/activate", [
            'reason' => 'Global kapsam aktivasyon testi.',
        ])->assertOk()->assertJsonPath('period.status', 'active');
    }

    public function test_own_projects_scope_cannot_read_or_mutate_another_projects_period(): void
    {
        $coordinator = $this->userWithRole('coordinator');
        $ownProject = $this->project('own-period-project');
        $otherProject = $this->project('other-period-project');
        $ownProject->coordinators()->attach($coordinator->id);
        $ownPeriod = $this->period($ownProject, 'Koordinator Donemi');
        $otherPeriod = $this->period($otherProject, 'Baska Donem');
        Sanctum::actingAs($coordinator);

        $this->getJson('/api/panel/periods')
            ->assertOk()
            ->assertJsonCount(1, 'periods')
            ->assertJsonPath('periods.0.id', $ownPeriod->id);
        $this->getJson("/api/panel/periods/{$ownPeriod->id}")->assertOk();
        $this->getJson("/api/panel/periods/{$otherPeriod->id}")->assertForbidden();

        $this->postJson("/api/panel/periods/{$ownPeriod->id}/activate", [
            'reason' => 'Kendi projesi aktivasyon testi.',
        ])->assertOk();
        $this->postJson("/api/panel/periods/{$otherPeriod->id}/activate", [
            'reason' => 'Kapsam disi aktivasyon denemesi.',
        ])->assertForbidden();

        $this->assertSame('planned', $otherPeriod->fresh()->status);
    }

    public function test_assigned_projects_scope_uses_staff_project_assignments_on_lifecycle_endpoints(): void
    {
        $staffRole = Role::findByName('staff', 'web');
        $staffRole->givePermissionTo(['periods.view', 'periods.activate']);
        foreach (['periods.view', 'periods.activate'] as $permission) {
            RolePermissionScope::query()->create([
                'role_name' => $staffRole->name,
                'permission_name' => $permission,
                'scope_type' => 'assigned_projects',
                'scope_payload' => [],
            ]);
        }

        $staff = $this->userWithRole('staff');
        $assignedProject = $this->project('assigned-period-project');
        $unassignedProject = $this->project('unassigned-period-project');
        $staff->assignedProjects()->attach($assignedProject->id);
        $assignedPeriod = $this->period($assignedProject, 'Atanmis Donem');
        $unassignedPeriod = $this->period($unassignedProject, 'Atanmamis Donem');
        Sanctum::actingAs($staff);

        $this->getJson('/api/panel/periods')
            ->assertOk()
            ->assertJsonCount(1, 'periods')
            ->assertJsonPath('periods.0.id', $assignedPeriod->id);
        $this->getJson("/api/panel/periods/{$assignedPeriod->id}")->assertOk();
        $this->getJson("/api/panel/periods/{$unassignedPeriod->id}")->assertForbidden();

        $this->postJson("/api/panel/periods/{$assignedPeriod->id}/activate", [
            'reason' => 'Atanmis proje aktivasyon testi.',
        ])->assertOk();
        $this->postJson("/api/panel/periods/{$unassignedPeriod->id}/activate", [
            'reason' => 'Atanmamis proje aktivasyon denemesi.',
        ])->assertForbidden();

        $this->assertSame('planned', $unassignedPeriod->fresh()->status);
    }

    public function test_user_without_period_permission_is_forbidden_before_project_scope_is_considered(): void
    {
        $student = $this->userWithRole('student');
        $project = $this->project('unauthorized-period-project');
        $period = $this->period($project, 'Yetkisiz Donem');
        Sanctum::actingAs($student);

        $this->getJson('/api/panel/periods')->assertForbidden();
        $this->getJson("/api/panel/periods/{$period->id}")->assertForbidden();
        $this->postJson("/api/panel/periods/{$period->id}/activate", [
            'reason' => 'Yetkisiz aktivasyon denemesi.',
        ])->assertForbidden();

        $this->assertSame('planned', $period->fresh()->status);
        $this->assertDatabaseCount('period_lifecycle_events', 0);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create([
            'name' => str($role)->headline()->toString(),
            'surname' => 'Scope',
            'role' => $role,
        ]);
        $user->assignRole(Role::findByName($role, 'web'));

        return $user;
    }

    private function project(string $slug): Project
    {
        return Project::query()->create([
            'name' => str($slug)->headline()->toString(),
            'slug' => $slug,
            'type' => 'other',
            'status' => 'active',
        ]);
    }

    private function period(Project $project, string $name): Period
    {
        return Period::query()->create([
            'project_id' => $project->id,
            'name' => $name,
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'credit_start_amount' => 100,
            'credit_threshold' => 75,
            'status' => 'planned',
        ]);
    }
}
