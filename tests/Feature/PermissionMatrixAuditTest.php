<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PermissionMatrixAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function actingSuperAdmin(): User
    {
        $user = User::factory()->create([
            'name' => 'Test',
            'surname' => 'Admin',
            'email' => 'super@test.local',
            'role' => 'super_admin',
        ]);

        Role::findOrCreate('super_admin', 'web');
        $user->assignRole('super_admin');

        return $user;
    }

    private function matrixPayloadForAllRoles(): array
    {
        return [
            'matrix' => collect(['super_admin', 'coordinator', 'staff', 'student', 'alumni', 'visitor'])
                ->map(fn (string $role) => ['role' => $role, 'permissions' => []])
                ->values()
                ->all(),
        ];
    }

    public function test_role_matrix_update_writes_audit_entry(): void
    {
        $admin = $this->actingSuperAdmin();
        Sanctum::actingAs($admin);

        $response = $this->putJson('/api/admin/permissions-matrix', $this->matrixPayloadForAllRoles());

        $response->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'permissions',
            'description' => 'permission_role_matrix.updated',
        ]);

        $this->assertSame(1, Activity::query()->where('log_name', 'permissions')->count());
    }

    public function test_user_override_update_writes_audit_entry(): void
    {
        $admin = $this->actingSuperAdmin();
        $staff = User::factory()->create([
            'name' => 'Staff',
            'surname' => 'User',
            'email' => 'staff@test.local',
            'role' => 'staff',
        ]);
        Role::findOrCreate('staff', 'web');
        $staff->assignRole('staff');

        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/admin/permissions-matrix/users/{$staff->id}", [
            'overrides' => [
                [
                    'permission_name' => 'periods.view',
                    'effect' => 'allow',
                    'scope_type' => 'selected_projects',
                    'scope_payload' => ['project_ids' => [1]],
                ],
            ],
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'permissions',
            'description' => 'user_permission_overrides.updated',
        ]);
    }

    public function test_audit_endpoint_returns_logs_for_super_admin(): void
    {
        $admin = $this->actingSuperAdmin();
        Sanctum::actingAs($admin);

        $this->putJson('/api/admin/permissions-matrix', $this->matrixPayloadForAllRoles())->assertOk();

        $audit = $this->getJson('/api/admin/permissions-matrix/audit');
        $audit->assertOk();
        $audit->assertJsonPath('logs.0.description', 'permission_role_matrix.updated');
    }
}
