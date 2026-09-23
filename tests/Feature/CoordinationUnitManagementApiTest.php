<?php

namespace Tests\Feature;

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitMembership;
use App\Models\CoordinationUnitPermissionRule;
use App\Models\CoordinationUnitProjectResponsibility;
use App\Models\Project;
use App\Models\RolePermissionScope;
use App\Models\User;
use App\Services\CoordinationUnitBackfillService;
use App\Services\CoordinationUnitPermissionRuleSyncService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CoordinationUnitManagementApiTest extends TestCase
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

    private function authority(string $role = 'super_admin'): User
    {
        $user = User::factory()->create([
            'surname' => 'Authority',
            'role' => $role,
            'status' => 'active',
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function prepareCatalog(): array
    {
        $first = $this->project('Pergel');
        $second = $this->project('Diplomasi');
        app(CoordinationUnitBackfillService::class)->execute(true);
        app(CoordinationUnitPermissionRuleSyncService::class)->execute(true);

        return [$first, $second];
    }

    public function test_management_endpoints_require_authentication_and_global_permission_scope(): void
    {
        $this->getJson('/api/panel/coordination-units')->assertUnauthorized();

        $role = Role::findOrCreate('unit_viewer_without_scope', 'web');
        $role->givePermissionTo(Permission::findByName('coordination_units.view', 'web'));
        $viewer = $this->authority('staff');
        $viewer->syncRoles([$role]);
        Sanctum::actingAs($viewer);

        $this->getJson('/api/panel/coordination-units')->assertForbidden();
    }

    public function test_super_admin_sees_unit_module_catalog_and_management_options(): void
    {
        $this->prepareCatalog();
        Sanctum::actingAs($this->authority());

        $modules = $this->getJson('/api/panel/modules')->assertOk()->json('modules');
        $this->assertNotNull(collect($modules)->firstWhere('id', 'coordination_units'));

        $response = $this->getJson('/api/panel/coordination-units')
            ->assertOk()
            ->assertJsonPath('authorization_mode', 'legacy')
            ->assertJsonCount(2, 'options.projects')
            ->assertJsonStructure([
                'units' => [[
                    'id', 'code', 'name', 'kind', 'status', 'project',
                    'memberships', 'responsibilities', 'permission_rules',
                ]],
                'options' => ['projects', 'users', 'service_domains', 'permission_groups', 'scope_sources', 'positions'],
            ]);

        $this->assertCount(5, $response->json('units'));
    }

    public function test_permission_rules_are_hidden_when_viewer_only_has_unit_view_permission(): void
    {
        $this->prepareCatalog();
        $role = Role::findOrCreate('unit_catalog_viewer', 'web');
        $role->givePermissionTo('coordination_units.view');
        RolePermissionScope::query()->create([
            'role_name' => $role->name,
            'permission_name' => 'coordination_units.view',
            'scope_type' => 'all',
            'scope_payload' => [],
        ]);
        $viewer = User::factory()->create([
            'surname' => 'Catalog',
            'role' => 'staff',
            'status' => 'active',
        ]);
        $viewer->assignRole($role);
        Sanctum::actingAs($viewer);

        $response = $this->getJson('/api/panel/coordination-units')->assertOk();

        $response->assertJsonMissingPath('options.permission_groups');
        $response->assertJsonMissingPath('units.0.permission_rules');
    }

    public function test_unit_creation_enforces_immutable_kind_project_shape_and_uses_passivation(): void
    {
        $project = $this->project('New Project');
        Sanctum::actingAs($this->authority());

        $this->postJson('/api/panel/coordination-units', [
            'code' => 'project_without_project',
            'name' => 'Invalid Project Unit',
            'kind' => CoordinationUnit::KIND_PROJECT,
        ])->assertUnprocessable();

        $this->postJson('/api/panel/coordination-units', [
            'code' => 'service_with_project',
            'name' => 'Invalid Service Unit',
            'kind' => CoordinationUnit::KIND_SERVICE,
            'project_id' => $project->id,
        ])->assertUnprocessable();

        $unitId = $this->postJson('/api/panel/coordination-units', [
            'code' => 'project_new_project',
            'name' => 'New Project Coordination',
            'kind' => CoordinationUnit::KIND_PROJECT,
            'project_id' => $project->id,
        ])->assertCreated()->json('unit.id');

        $serviceUnitId = $this->postJson('/api/panel/coordination-units', [
            'code' => 'service_new_project_media',
            'name' => 'New Project Media Service',
            'kind' => CoordinationUnit::KIND_SERVICE,
        ])->assertCreated()->json('unit.id');

        $this->postJson("/api/panel/coordination-units/{$serviceUnitId}/responsibilities", [
            'project_id' => $project->id,
            'service_domain' => 'media',
            'is_primary' => true,
        ])->assertOk()->assertJsonPath('responsibility.project_id', $project->id);

        $this->putJson("/api/panel/coordination-units/{$unitId}", [
            'status' => CoordinationUnit::STATUS_PASSIVE,
            'name' => 'Renamed Coordination',
            'kind' => CoordinationUnit::KIND_SERVICE,
        ])->assertOk()->assertJsonPath('unit.status', CoordinationUnit::STATUS_PASSIVE);

        $unit = CoordinationUnit::query()->findOrFail($unitId);
        $this->assertSame(CoordinationUnit::KIND_PROJECT, $unit->kind);
        $this->assertNull($unit->deleted_at);
        $this->assertDatabaseHas('coordination_unit_project_responsibilities', [
            'unit_id' => $serviceUnitId,
            'project_id' => $project->id,
            'service_domain' => 'media',
            'status' => CoordinationUnitProjectResponsibility::STATUS_ACTIVE,
        ]);
    }

    public function test_memberships_support_multiple_units_keep_one_primary_and_preserve_history(): void
    {
        $this->prepareCatalog();
        $admin = $this->authority();
        $staff = $this->authority('staff');
        $firstUnit = CoordinationUnit::query()->where('code', 'service_media')->firstOrFail();
        $secondUnit = CoordinationUnit::query()->where('code', 'service_purchase_organization')->firstOrFail();
        Sanctum::actingAs($admin);

        $firstMembershipId = $this->postJson("/api/panel/coordination-units/{$firstUnit->id}/memberships", [
            'user_id' => $staff->id,
            'position' => CoordinationUnitMembership::POSITION_STAFF,
            'is_primary' => true,
        ])->assertOk()->assertJsonPath('membership.is_primary', true)->json('membership.id');

        $this->postJson("/api/panel/coordination-units/{$secondUnit->id}/memberships", [
            'user_id' => $staff->id,
            'position' => CoordinationUnitMembership::POSITION_COORDINATOR,
            'is_primary' => true,
        ])->assertOk()->assertJsonPath('membership.is_primary', true);

        $this->assertCount(2, $staff->coordinationUnitMemberships()->active()->get());
        $this->assertSame(1, $staff->coordinationUnitMemberships()->active()->where('is_primary', true)->count());

        $this->patchJson("/api/panel/coordination-unit-memberships/{$firstMembershipId}/deactivate")
            ->assertOk();

        $this->assertDatabaseHas('coordination_unit_memberships', [
            'id' => $firstMembershipId,
            'status' => CoordinationUnitMembership::STATUS_PASSIVE,
            'is_primary' => false,
        ]);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'coordination_units',
            'description' => 'coordination_unit.membership_deactivated',
        ]);
    }

    public function test_students_cannot_be_assigned_to_a_coordination_unit(): void
    {
        $this->prepareCatalog();
        Sanctum::actingAs($this->authority());
        $student = $this->authority('student');
        $unit = CoordinationUnit::query()->where('code', 'service_media')->firstOrFail();

        $this->postJson("/api/panel/coordination-units/{$unit->id}/memberships", [
            'user_id' => $student->id,
            'position' => CoordinationUnitMembership::POSITION_STAFF,
        ])->assertUnprocessable();
    }

    public function test_service_responsibility_is_project_scoped_primary_and_passivated_not_deleted(): void
    {
        [$project] = $this->prepareCatalog();
        Sanctum::actingAs($this->authority());
        $projectUnit = CoordinationUnit::query()->where('project_id', $project->id)->firstOrFail();
        $serviceUnit = CoordinationUnit::query()->where('code', 'service_media')->firstOrFail();

        $this->postJson("/api/panel/coordination-units/{$projectUnit->id}/responsibilities", [
            'project_id' => $project->id,
            'service_domain' => 'media',
        ])->assertUnprocessable();

        $responsibilityId = $this->postJson("/api/panel/coordination-units/{$serviceUnit->id}/responsibilities", [
            'project_id' => $project->id,
            'service_domain' => 'media',
            'is_primary' => true,
        ])->assertOk()->assertJsonPath('responsibility.is_primary', true)->json('responsibility.id');

        $this->patchJson("/api/panel/coordination-unit-responsibilities/{$responsibilityId}/deactivate")
            ->assertOk();

        $this->assertDatabaseHas('coordination_unit_project_responsibilities', [
            'id' => $responsibilityId,
            'status' => 'passive',
        ]);
        $this->assertSame(0, CoordinationUnitProjectResponsibility::onlyTrashed()->count());
    }

    public function test_permission_rule_validation_lifecycle_and_authorization_preview_are_available(): void
    {
        $this->prepareCatalog();
        $admin = $this->authority();
        $coordinator = $this->authority('coordinator');
        $serviceUnit = CoordinationUnit::query()->where('code', 'service_media')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->postJson("/api/panel/coordination-units/{$serviceUnit->id}/permission-rules", [
            'position' => CoordinationUnitMembership::POSITION_COORDINATOR,
            'permission_name' => 'staff.view',
            'scope_source' => CoordinationUnitPermissionRule::SCOPE_LINKED_PROJECT,
        ])->assertUnprocessable();

        $ruleId = $this->postJson("/api/panel/coordination-units/{$serviceUnit->id}/permission-rules", [
            'position' => CoordinationUnitMembership::POSITION_COORDINATOR,
            'permission_name' => 'staff.view',
            'scope_source' => CoordinationUnitPermissionRule::SCOPE_OWN_UNIT,
        ])->assertOk()->json('permission_rule.id');

        $this->patchJson("/api/panel/coordination-unit-permission-rules/{$ruleId}/deactivate")
            ->assertOk();
        $this->assertDatabaseHas('coordination_unit_permission_rules', [
            'id' => $ruleId,
            'status' => 'passive',
        ]);

        $this->getJson("/api/panel/coordination-units/authorization-preview?user_id={$coordinator->id}")
            ->assertOk()
            ->assertJsonPath('configured_mode', 'legacy')
            ->assertJsonStructure([
                'user' => ['id', 'name', 'email', 'role'],
                'legacy' => ['effective_permissions', 'scopes', 'contexts'],
                'coordination_units' => ['effective_permissions', 'scopes', 'contexts'],
                'diff' => ['legacy_only_permissions', 'unit_only_permissions', 'scope_differences'],
            ]);

        $this->assertGreaterThan(0, Activity::query()->where('log_name', 'coordination_units')->count());
    }
}
