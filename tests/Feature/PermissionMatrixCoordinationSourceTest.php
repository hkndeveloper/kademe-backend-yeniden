<?php

namespace Tests\Feature;

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitMembership;
use App\Models\Project;
use App\Models\RolePermissionScope;
use App\Models\User;
use App\Services\CoordinationUnitBackfillService;
use App\Services\CoordinationUnitPermissionRuleSyncService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PermissionMatrixCoordinationSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('coordination_authorization.mode', 'enforce');
        $this->seed(RolePermissionSeeder::class);
        Sanctum::actingAs($this->superAdmin());
    }

    public function test_matrix_exposes_the_authoritative_business_permission_source(): void
    {
        $response = $this->getJson('/api/panel/permissions-matrix')->assertOk();

        $response
            ->assertJsonPath('authorization_management.mode', 'enforce')
            ->assertJsonPath('authorization_management.coordinator_staff_business_source', 'coordination_units')
            ->assertJsonPath('authorization_management.role_matrix_business_read_only', true)
            ->assertJsonPath('authorization_management.user_override_scope', 'membership_for_business_permissions')
            ->assertJsonPath('authorization_management.business_allow_requires_acknowledgement', false)
            ->assertJsonPath('authorization_management.coordination_units_path', '/panel/coordination-units');

        $this->assertSame(
            ['coordinator', 'staff'],
            $response->json('authorization_management.protected_roles')
        );
        $this->assertContains(
            'financial.view',
            $response->json('authorization_management.unit_business_permissions')
        );
        $this->assertNotContains(
            'permissions.matrix.update',
            $response->json('authorization_management.unit_business_permissions')
        );
    }

    public function test_enforce_matrix_update_preserves_staff_business_permissions_and_scopes(): void
    {
        $staffRole = Role::findByName('staff', 'web');
        $this->assertTrue($staffRole->hasPermissionTo('programs.view'));
        $this->assertFalse($staffRole->hasPermissionTo('financial.approve'));

        RolePermissionScope::query()->create([
            'role_name' => 'staff',
            'permission_name' => 'programs.view',
            'scope_type' => 'assigned_projects',
            'scope_payload' => [],
        ]);

        $this->putJson('/api/panel/permissions-matrix', [
            'granular_matrix' => [[
                'role' => 'staff',
                'permissions' => ['financial.approve', 'trainers.view'],
            ]],
            'granular_scopes' => [[
                'role' => 'staff',
                'scopes' => [
                    [
                        'permission_name' => 'programs.view',
                        'scope_type' => 'all',
                        'scope_payload' => [],
                    ],
                    [
                        'permission_name' => 'trainers.view',
                        'scope_type' => 'all',
                        'scope_payload' => [],
                    ],
                ],
            ]],
        ])->assertOk();

        $staffRole = $staffRole->fresh();
        $this->assertTrue($staffRole->hasPermissionTo('programs.view'));
        $this->assertFalse($staffRole->hasPermissionTo('financial.approve'));
        $this->assertTrue($staffRole->hasPermissionTo('trainers.view'));
        $this->assertDatabaseHas('role_permission_scopes', [
            'role_name' => 'staff',
            'permission_name' => 'programs.view',
            'scope_type' => 'assigned_projects',
        ]);
        $this->assertDatabaseHas('role_permission_scopes', [
            'role_name' => 'staff',
            'permission_name' => 'trainers.view',
            'scope_type' => 'all',
        ]);
    }

    public function test_business_allow_override_requires_and_uses_an_active_membership(): void
    {
        $coordinator = User::factory()->create([
            'role' => 'coordinator',
            'surname' => 'Coordinator',
            'email' => 'yf1-coordinator@test.local',
        ]);
        $coordinator->assignRole('coordinator');
        $membership = $this->projectMembership($coordinator, CoordinationUnitMembership::POSITION_COORDINATOR);

        $payload = [
            'overrides' => [[
                'permission_name' => 'financial.approve',
                'effect' => 'allow',
                'scope_type' => 'all',
                'scope_payload' => [],
            ]],
        ];

        $this->putJson("/api/panel/permissions-matrix/users/{$coordinator->id}", $payload)
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Birim isi override kaydi icin kullanicinin aktif birim uyeligi secilmelidir.']);

        $this->putJson("/api/panel/permissions-matrix/users/{$coordinator->id}", [
            'overrides' => [[
                ...$payload['overrides'][0],
                'membership_id' => $membership->id,
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('coordination_unit_membership_permission_overrides', [
            'membership_id' => $membership->id,
            'permission_name' => 'financial.approve',
            'effect' => 'allow',
            'status' => 'active',
        ]);
        $this->assertDatabaseMissing('user_permission_overrides', [
            'user_id' => $coordinator->id,
            'permission_name' => 'financial.approve',
        ]);

        $this->getJson("/api/panel/permissions-matrix/users/{$coordinator->id}")
            ->assertOk()
            ->assertJsonPath('authorization_management.user_override_scope', 'membership_for_business_permissions')
            ->assertJsonPath('authorization_management.business_allow_requires_acknowledgement', false)
            ->assertJsonPath('overrides.0.membership_id', $membership->id);

        $this->patchJson("/api/panel/coordination-unit-memberships/{$membership->id}/deactivate")
            ->assertOk();
        $this->assertDatabaseHas('coordination_unit_membership_permission_overrides', [
            'membership_id' => $membership->id,
            'permission_name' => 'financial.approve',
            'status' => 'passive',
        ]);
    }

    public function test_business_deny_override_is_scoped_to_the_selected_membership(): void
    {
        $staff = User::factory()->create([
            'role' => 'staff',
            'surname' => 'Staff',
            'email' => 'yf1-staff@test.local',
        ]);
        $staff->assignRole('staff');
        $membership = $this->projectMembership($staff, CoordinationUnitMembership::POSITION_STAFF);

        $this->putJson("/api/panel/permissions-matrix/users/{$staff->id}", [
            'overrides' => [[
                'permission_name' => 'programs.view',
                'effect' => 'deny',
                'scope_type' => 'none',
                'scope_payload' => [],
                'membership_id' => $membership->id,
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('coordination_unit_membership_permission_overrides', [
            'membership_id' => $membership->id,
            'permission_name' => 'programs.view',
            'effect' => 'deny',
            'status' => 'active',
        ]);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create([
            'role' => 'super_admin',
            'surname' => 'Admin',
            'email' => 'yf1-admin@test.local',
        ]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function projectMembership(User $user, string $position): CoordinationUnitMembership
    {
        $project = Project::query()->create([
            'name' => 'Override Project '.$user->id,
            'slug' => 'override-project-'.$user->id,
            'type' => 'other',
            'status' => 'active',
        ]);
        app(CoordinationUnitBackfillService::class)->execute(true);
        app(CoordinationUnitPermissionRuleSyncService::class)->execute(true);
        $unit = CoordinationUnit::query()->where('project_id', $project->id)->firstOrFail();

        return CoordinationUnitMembership::query()->create([
            'unit_id' => $unit->id,
            'user_id' => $user->id,
            'position' => $position,
            'is_primary' => true,
            'status' => CoordinationUnitMembership::STATUS_ACTIVE,
        ]);
    }
}
