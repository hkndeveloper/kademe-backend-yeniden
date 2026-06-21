<?php

namespace Tests\Feature;

use App\Models\RolePermissionScope;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PanelModuleCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_custom_role_sees_module_when_action_and_scope_are_assigned(): void
    {
        Permission::findOrCreate('periods.view', 'web');
        $role = Role::findOrCreate('period_manifest_viewer', 'web');
        $role->givePermissionTo('periods.view');

        RolePermissionScope::query()->create([
            'role_name' => 'period_manifest_viewer',
            'permission_name' => 'periods.view',
            'scope_type' => 'all',
            'scope_payload' => [],
        ]);

        $user = User::factory()->create([
            'role' => 'visitor',
            'surname' => 'Viewer',
            'email' => 'period-manifest@test.local',
        ]);
        $user->assignRole('period_manifest_viewer');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/panel/modules')->assertOk();
        $periods = collect($response->json('modules'))->firstWhere('id', 'periods');

        $this->assertNotNull($periods);
        $this->assertSame('authority', $periods['panel_type']);
        $this->assertContains('periods.view', $periods['enabled_actions']);
        $this->assertSame('all', $periods['scopes']['periods.view']['scope_type']);
    }

    public function test_permission_without_usable_scope_does_not_expose_module(): void
    {
        Permission::findOrCreate('periods.view', 'web');
        $role = Role::findOrCreate('period_manifest_no_scope', 'web');
        $role->givePermissionTo('periods.view');

        $user = User::factory()->create([
            'role' => 'visitor',
            'surname' => 'NoScope',
            'email' => 'period-no-scope@test.local',
        ]);
        $user->assignRole('period_manifest_no_scope');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/panel/modules')->assertOk();

        $this->assertFalse(
            collect($response->json('modules'))->contains(fn (array $module) => $module['id'] === 'periods')
        );
    }

    public function test_user_deny_override_removes_role_module_from_manifest(): void
    {
        Permission::findOrCreate('periods.view', 'web');
        $role = Role::findOrCreate('period_manifest_denied', 'web');
        $role->givePermissionTo('periods.view');

        RolePermissionScope::query()->create([
            'role_name' => 'period_manifest_denied',
            'permission_name' => 'periods.view',
            'scope_type' => 'all',
            'scope_payload' => [],
        ]);

        $user = User::factory()->create([
            'role' => 'visitor',
            'surname' => 'Denied',
            'email' => 'period-denied@test.local',
        ]);
        $user->assignRole('period_manifest_denied');
        UserPermissionOverride::query()->create([
            'user_id' => $user->id,
            'permission_name' => 'periods.view',
            'effect' => 'deny',
            'scope_type' => null,
            'scope_payload' => [],
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/panel/modules')->assertOk();

        $this->assertFalse(
            collect($response->json('modules'))->contains(fn (array $module) => $module['id'] === 'periods')
        );
    }

    public function test_user_allow_override_adds_module_with_override_scope(): void
    {
        Permission::findOrCreate('periods.view', 'web');
        $user = User::factory()->create([
            'role' => 'visitor',
            'surname' => 'Allowed',
            'email' => 'period-allowed@test.local',
        ]);
        UserPermissionOverride::query()->create([
            'user_id' => $user->id,
            'permission_name' => 'periods.view',
            'effect' => 'allow',
            'scope_type' => 'all',
            'scope_payload' => [],
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/panel/modules')->assertOk();
        $periods = collect($response->json('modules'))->firstWhere('id', 'periods');

        $this->assertNotNull($periods);
        $this->assertSame('all', $periods['scopes']['periods.view']['scope_type']);
    }

    public function test_student_portal_modules_are_resolved_from_participant_permissions(): void
    {
        $user = User::factory()->create([
            'role' => 'student',
            'surname' => 'Student',
            'email' => 'student-manifest@test.local',
        ]);
        $user->assignRole('student');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/panel/modules')->assertOk();
        $modules = collect($response->json('modules'));

        $this->assertTrue($modules->contains(fn (array $module) => $module['id'] === 'participant_dashboard'));
        $this->assertTrue($modules->contains(fn (array $module) => $module['id'] === 'participant_programs'));
        $this->assertFalse($modules->contains(fn (array $module) => $module['id'] === 'periods'));
        $this->assertFalse($modules->contains(fn (array $module) => $module['id'] === 'profile' && $module['panel_type'] === 'authority'));

        $programs = $modules->firstWhere('id', 'participant_programs');
        $this->assertContains('participant.programs.view', $programs['enabled_actions']);
        $this->assertSame('self', $programs['scopes']['participant.programs.view']['scope_type']);
    }
}
