<?php

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\Period;
use App\Models\Project;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\PermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PermissionResolverScopeTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): PermissionResolver
    {
        return app(PermissionResolver::class);
    }

    public function test_allow_override_grants_permission_with_selected_projects_scope(): void
    {
        Permission::findOrCreate('applications.view', 'web');

        $staff = User::factory()->create([
            'name' => 'A',
            'surname' => 'B',
            'email' => 'staff-scope@test.local',
            'role' => 'staff',
        ]);
        Role::findOrCreate('staff', 'web');
        $staff->assignRole('staff');

        UserPermissionOverride::query()->create([
            'user_id' => $staff->id,
            'permission_name' => 'applications.view',
            'effect' => 'allow',
            'scope_type' => 'selected_projects',
            'scope_payload' => ['project_ids' => [7, 8]],
        ]);

        $staff->refresh();
        $resolver = $this->resolver();

        $this->assertTrue($resolver->hasPermission($staff, 'applications.view'));
        $this->assertTrue($resolver->canAccessProject($staff, 'applications.view', 7));
        $this->assertTrue($resolver->canAccessProject($staff, 'applications.view', 8));
        $this->assertFalse($resolver->canAccessProject($staff, 'applications.view', 99));
    }

    public function test_deny_override_removes_role_permission(): void
    {
        Permission::findOrCreate('periods.view', 'web');

        $staff = User::factory()->create([
            'name' => 'C',
            'surname' => 'D',
            'email' => 'staff-deny@test.local',
            'role' => 'staff',
        ]);
        Role::findOrCreate('staff', 'web');
        $staff->assignRole('staff');
        $staff->givePermissionTo('periods.view');

        UserPermissionOverride::query()->create([
            'user_id' => $staff->id,
            'permission_name' => 'periods.view',
            'effect' => 'deny',
            'scope_type' => null,
            'scope_payload' => [],
        ]);

        $staff->refresh();
        $resolver = $this->resolver();

        $this->assertFalse($resolver->hasPermission($staff, 'periods.view'));
        $this->assertFalse($resolver->canAccessProject($staff, 'periods.view', 1));
    }

    public function test_deny_wildcard_prefix_blocks_nested_permissions(): void
    {
        foreach (['periods.view', 'periods.export'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $user = User::factory()->create([
            'name' => 'E',
            'surname' => 'F',
            'email' => 'wild@test.local',
            'role' => 'staff',
        ]);
        Role::findOrCreate('staff', 'web');
        $user->assignRole('staff');
        $user->givePermissionTo(['periods.view', 'periods.export']);

        UserPermissionOverride::query()->create([
            'user_id' => $user->id,
            'permission_name' => 'periods.*',
            'effect' => 'deny',
            'scope_type' => null,
            'scope_payload' => [],
        ]);

        $user->refresh();
        $resolver = $this->resolver();

        $this->assertFalse($resolver->hasPermission($user, 'periods.view'));
        $this->assertFalse($resolver->hasPermission($user, 'periods.export'));
    }

    public function test_student_self_scope_requires_participation(): void
    {
        Permission::findOrCreate('programs.view', 'web');

        $student = User::factory()->create([
            'name' => 'G',
            'surname' => 'H',
            'email' => 'student-self@test.local',
            'role' => 'student',
        ]);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');
        $student->givePermissionTo('programs.view');

        $project = Project::query()->create([
            'name' => 'Test Proje',
            'slug' => 'test-proje-self-'.uniqid(),
            'type' => 'other',
            'status' => 'active',
        ]);

        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => 'Donem 1',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
        ]);

        $student->refresh();
        $resolver = $this->resolver();

        $this->assertTrue($resolver->hasPermission($student, 'programs.view'));
        $this->assertTrue($resolver->canAccessProject($student, 'programs.view', $project->id));
        $this->assertFalse($resolver->canAccessProject($student, 'programs.view', $project->id + 9999));
    }

    public function test_super_admin_can_access_any_project_when_permission_present(): void
    {
        Permission::findOrCreate('financial.view', 'web');

        $admin = User::factory()->create([
            'name' => 'I',
            'surname' => 'J',
            'email' => 'super-scope@test.local',
            'role' => 'super_admin',
        ]);
        Role::findOrCreate('super_admin', 'web');
        $admin->assignRole('super_admin');
        $admin->givePermissionTo('financial.view');

        $admin->refresh();
        $resolver = $this->resolver();

        $this->assertTrue($resolver->canAccessProject($admin, 'financial.view', 1));
        $this->assertTrue($resolver->canAccessProject($admin, 'financial.view', 999999));
    }
}
