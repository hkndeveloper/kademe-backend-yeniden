<?php

namespace Tests\Feature;

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitMembership;
use App\Models\Project;
use App\Models\User;
use App\Services\CoordinationUnitBackfillService;
use App\Services\CoordinationUnitPermissionRuleSyncService;
use App\Support\CoordinationUnitCatalog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CoordinationOrganizationContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function project(string $name): Project
    {
        return Project::query()->create([
            'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'type' => 'other',
            'status' => 'active',
        ]);
    }

    private function authority(string $role): User
    {
        $user = User::factory()->create([
            'surname' => 'Context',
            'role' => $role,
            'status' => 'active',
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function prepareProjectMembership(User $user, string $position): Project
    {
        $project = $this->project('Context Project');
        app(CoordinationUnitBackfillService::class)->execute(true);
        app(CoordinationUnitPermissionRuleSyncService::class)->execute(true);
        $unit = CoordinationUnit::query()
            ->where('code', CoordinationUnitCatalog::projectUnitCode($project->id))
            ->firstOrFail();

        CoordinationUnitMembership::query()->create([
            'unit_id' => $unit->id,
            'user_id' => $user->id,
            'position' => $position,
            'is_primary' => true,
        ]);

        return $project;
    }

    public function test_legacy_auth_payload_exposes_non_authoritative_organization_context_without_changing_legacy_context(): void
    {
        $staff = $this->authority('staff');
        $project = $this->prepareProjectMembership($staff, CoordinationUnitMembership::POSITION_STAFF);
        Sanctum::actingAs($staff);
        config()->set('coordination_authorization.mode', 'legacy');

        $response = $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.organization_context.schema_version', 3)
            ->assertJsonPath('user.organization_context.available', true)
            ->assertJsonPath('user.organization_context.authorization_mode', 'legacy')
            ->assertJsonPath('user.organization_context.authoritative', false)
            ->assertJsonPath('user.organization_context.unit_memberships.0.position', 'staff');

        $response->assertJsonMissingPath('user.authorization_context.unit_memberships');
        $membership = $response->json('user.organization_context.unit_memberships.0');
        $this->assertSame([$project->id], $membership['project_ids_by_permission']['dashboard.staff.view']);
        $this->assertSame($project->name, $response->json('user.organization_context.projects.0.name'));
        $this->assertContains('dashboard.staff.view', $response->json('user.effective_permissions'));
        $this->assertSame(
            'assigned_projects',
            $response->json('user.permission_scopes')['dashboard.staff.view']['scope_type']
        );
    }

    public function test_enforce_auth_payload_marks_unit_context_authoritative_and_keeps_action_specific_projects(): void
    {
        $coordinator = $this->authority('coordinator');
        $project = $this->prepareProjectMembership($coordinator, CoordinationUnitMembership::POSITION_COORDINATOR);
        Sanctum::actingAs($coordinator);
        config()->set('coordination_authorization.mode', 'enforce');

        $response = $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.organization_context.authoritative', true)
            ->assertJsonPath('user.organization_context.authorization_mode', 'enforce');

        $membership = $response->json('user.organization_context.unit_memberships.0');
        $this->assertSame([$project->id], $membership['project_ids_by_permission']['projects.view']);
        $this->assertContains('dashboard.coordinator.view', $response->json('user.effective_permissions'));
        $this->assertContains('projects.view', $membership['permissions']);
    }

    public function test_pilot_auth_payload_is_authoritative_only_for_allowlisted_user(): void
    {
        $pilot = $this->authority('coordinator');
        $legacy = $this->authority('staff');
        $this->prepareProjectMembership($pilot, CoordinationUnitMembership::POSITION_COORDINATOR);
        config()->set('coordination_authorization.mode', 'pilot');
        config()->set('coordination_authorization.pilot_user_ids', [$pilot->id]);

        Sanctum::actingAs($pilot);
        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.organization_context.authorization_mode', 'pilot')
            ->assertJsonPath('user.organization_context.authoritative', true);

        Sanctum::actingAs($legacy);
        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.organization_context.authorization_mode', 'pilot')
            ->assertJsonPath('user.organization_context.authoritative', false);
    }

    public function test_super_admin_remains_non_authoritative_for_unit_context_in_enforce_mode(): void
    {
        $admin = $this->authority('super_admin');
        Sanctum::actingAs($admin);
        config()->set('coordination_authorization.mode', 'enforce');

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.organization_context.authoritative', false)
            ->assertJsonPath('user.organization_context.authorization_mode', 'enforce');
    }

    public function test_login_payload_includes_the_same_organization_context_contract(): void
    {
        $staff = $this->authority('staff');
        $project = $this->prepareProjectMembership($staff, CoordinationUnitMembership::POSITION_STAFF);
        config()->set('coordination_authorization.mode', 'legacy');

        $response = $this->postJson('/api/auth/login', [
            'email' => $staff->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('user.organization_context.schema_version', 3)
            ->assertJsonPath('user.organization_context.authoritative', false)
            ->assertJsonPath('user.organization_context.projects.0.id', $project->id);

        $this->assertNotEmpty($response->json('access_token'));
    }

    public function test_staff_dashboard_module_uses_canonical_route_and_explicit_dashboard_action(): void
    {
        $staff = $this->authority('staff');
        Sanctum::actingAs($staff);

        $response = $this->getJson('/api/panel/modules')->assertOk();
        $dashboard = collect($response->json('modules'))->firstWhere('id', 'dashboard');

        $this->assertNotNull($dashboard);
        $this->assertSame('/panel/dashboard', $dashboard['href']);
        $this->assertContains('dashboard.staff.view', $dashboard['enabled_actions']);
        $this->assertSame(
            'assigned_projects',
            $dashboard['scopes']['dashboard.staff.view']['scope_type']
        );
    }
}
