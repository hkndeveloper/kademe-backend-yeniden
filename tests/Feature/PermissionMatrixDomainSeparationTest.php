<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PermissionMatrixDomainSeparationTest extends TestCase
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
            'role' => 'super_admin',
            'surname' => 'Admin',
            'email' => 'matrix-domain-admin@test.local',
        ]);
        $user->assignRole('super_admin');
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_matrix_exposes_role_permission_domain_compatibility(): void
    {
        $this->actingSuperAdmin();

        $response = $this->getJson('/api/panel/permissions-matrix')->assertOk();

        $this->assertSame('authority', $response->json('permission_domains.Users.domain'));
        $this->assertSame('participant', $response->json('permission_domains.Participant Portal.domain'));

        $compatibility = $response->json('role_permission_compatibility');
        $this->assertFalse($compatibility['student']['users.view']);
        $this->assertTrue($compatibility['student']['participant.dashboard.view']);
        $this->assertTrue($compatibility['alumni']['alumni.opportunities.view']);
    }

    public function test_student_role_matrix_ignores_authority_permissions(): void
    {
        $this->actingSuperAdmin();

        $this->putJson('/api/panel/permissions-matrix', [
            'granular_matrix' => [
                [
                    'role' => 'student',
                    'permissions' => [
                        'users.view',
                        'participant.dashboard.view',
                    ],
                ],
            ],
        ])->assertOk();

        $studentRole = Role::findByName('student', 'web');

        $this->assertFalse($studentRole->hasPermissionTo('users.view'));
        $this->assertTrue($studentRole->hasPermissionTo('participant.dashboard.view'));
    }

    public function test_student_user_override_cannot_add_authority_permission(): void
    {
        $this->actingSuperAdmin();
        $student = User::factory()->create([
            'role' => 'student',
            'surname' => 'Student',
            'email' => 'matrix-domain-student@test.local',
        ]);
        $student->assignRole('student');

        $this->putJson("/api/panel/permissions-matrix/users/{$student->id}", [
            'overrides' => [
                [
                    'permission_name' => 'users.view',
                    'effect' => 'allow',
                    'scope_type' => 'all',
                    'scope_payload' => [],
                ],
            ],
        ])->assertStatus(422);
    }

    public function test_student_user_cannot_receive_authority_role_assignment(): void
    {
        $this->actingSuperAdmin();
        $student = User::factory()->create([
            'role' => 'student',
            'surname' => 'Student',
            'email' => 'matrix-domain-student-role@test.local',
        ]);
        $student->assignRole('student');

        $this->putJson("/api/panel/permissions-matrix/users/{$student->id}/roles", [
            'roles' => ['student', 'staff'],
            'primary_role' => 'staff',
        ])->assertStatus(422);
    }

    public function test_custom_authority_role_cannot_receive_participant_permissions(): void
    {
        $this->actingSuperAdmin();

        $response = $this->postJson('/api/panel/permissions-matrix/roles', [
            'name' => 'custom_authority_role',
            'permissions' => [
                'participant.dashboard.view',
                'users.view',
            ],
        ])->assertCreated();

        $this->assertNotContains('participant.dashboard.view', $response->json('role.permissions.*.name'));
        $this->assertContains('users.view', $response->json('role.permissions.*.name'));
    }
}
