<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\RolePermissionScope;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Support\PanelModuleCatalog;
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
        $profile = collect($response->json('modules'))->firstWhere('id', 'profile');

        $this->assertNotNull($periods);
        $this->assertSame('authority', $periods['panel_type']);
        $this->assertSame(['periods.view'], $periods['entry_permissions']);
        $this->assertSame($periods['entry_permissions'], $periods['view_permissions']);
        $this->assertSame('organization', $periods['context_mode']);
        $this->assertSame('standard', $periods['navigation_mode']);
        $this->assertSame(['all'], $periods['scope_modes']);
        $this->assertContains('periods.view', $periods['enabled_actions']);
        $this->assertSame('all', $periods['scopes']['periods.view']['scope_type']);
        $this->assertNotNull($profile);
        $this->assertTrue($profile['always_visible']);
        $this->assertSame('self_service', $profile['context_mode']);
        $this->assertSame([], $profile['entry_permissions']);
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

    public function test_action_without_entry_permission_does_not_expose_module(): void
    {
        Permission::findOrCreate('periods.create', 'web');
        $role = Role::findOrCreate('period_action_only', 'web');
        $role->givePermissionTo('periods.create');
        RolePermissionScope::query()->create([
            'role_name' => $role->name,
            'permission_name' => 'periods.create',
            'scope_type' => 'all',
            'scope_payload' => [],
        ]);
        $user = User::factory()->create([
            'role' => 'visitor',
            'surname' => 'ActionOnly',
            'email' => 'period-action-only@test.local',
        ]);
        $user->assignRole($role);
        Sanctum::actingAs($user);

        $modules = collect($this->getJson('/api/panel/modules')->assertOk()->json('modules'));

        $this->assertFalse($modules->contains(fn (array $module) => $module['id'] === 'periods'));
    }

    public function test_operational_view_permission_exposes_dashboard_module_without_dashboard_specific_permission(): void
    {
        Permission::findOrCreate('programs.view', 'web');
        $role = Role::findOrCreate('program_dashboard_viewer', 'web');
        $role->givePermissionTo('programs.view');

        RolePermissionScope::query()->create([
            'role_name' => 'program_dashboard_viewer',
            'permission_name' => 'programs.view',
            'scope_type' => 'all',
            'scope_payload' => [],
        ]);

        $user = User::factory()->create([
            'role' => 'visitor',
            'surname' => 'Dashboard',
            'email' => 'program-dashboard@test.local',
        ]);
        $user->assignRole('program_dashboard_viewer');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/panel/modules')->assertOk();
        $dashboard = collect($response->json('modules'))->firstWhere('id', 'dashboard');

        $this->assertNotNull($dashboard);
        $this->assertContains('programs.view', $dashboard['view_permissions']);
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

    public function test_staff_and_unit_members_navigation_is_decided_by_manifest_scope(): void
    {
        $project = Project::query()->create([
            'name' => 'Staff Scope Project',
            'slug' => 'staff-scope-project',
            'type' => 'other',
            'status' => 'active',
        ]);
        Permission::findOrCreate('staff.view', 'web');

        $globalRole = Role::findOrCreate('global_staff_viewer', 'web');
        $globalRole->givePermissionTo('staff.view');
        RolePermissionScope::query()->create([
            'role_name' => $globalRole->name,
            'permission_name' => 'staff.view',
            'scope_type' => 'all',
            'scope_payload' => [],
        ]);
        $globalUser = User::factory()->create(['role' => 'visitor', 'surname' => 'GlobalStaff']);
        $globalUser->assignRole($globalRole);
        $globalModules = collect(app(PanelModuleCatalog::class)->visibleFor($globalUser)['modules']);
        $this->assertNotNull($globalModules->firstWhere('id', 'staff'));
        $this->assertNull($globalModules->firstWhere('id', 'members'));

        $unitRole = Role::findOrCreate('unit_staff_viewer', 'web');
        $unitRole->givePermissionTo('staff.view');
        RolePermissionScope::query()->create([
            'role_name' => $unitRole->name,
            'permission_name' => 'staff.view',
            'scope_type' => 'selected_projects',
            'scope_payload' => ['project_ids' => [$project->id]],
        ]);
        $unitUser = User::factory()->create(['role' => 'visitor', 'surname' => 'UnitStaff']);
        $unitUser->assignRole($unitRole);
        $unitModules = collect(app(PanelModuleCatalog::class)->visibleFor($unitUser)['modules']);
        $this->assertNull($unitModules->firstWhere('id', 'staff'));
        $this->assertNotNull($unitModules->firstWhere('id', 'members'));
    }

    public function test_project_family_module_is_visible_only_when_scope_matches_required_project_type(): void
    {
        $project = Project::query()->create([
            'name' => 'Diplomasi360 Test',
            'slug' => 'diplomasi360-test',
            'type' => 'diplomasi360',
            'status' => 'active',
        ]);

        Permission::findOrCreate('projects.internships.view', 'web');
        $role = Role::findOrCreate('diplomasi_family_viewer', 'web');
        $role->givePermissionTo('projects.internships.view');

        RolePermissionScope::query()->create([
            'role_name' => 'diplomasi_family_viewer',
            'permission_name' => 'projects.internships.view',
            'scope_type' => 'selected_projects',
            'scope_payload' => ['project_ids' => [$project->id]],
        ]);

        $user = User::factory()->create([
            'role' => 'visitor',
            'surname' => 'Diplomasi',
            'email' => 'diplomasi-family@test.local',
        ]);
        $user->assignRole('diplomasi_family_viewer');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/panel/modules')->assertOk();
        $module = collect($response->json('modules'))->firstWhere('id', 'diplomasi360');

        $this->assertNotNull($module);
        $this->assertSame('/panel/diplomasi360', $module['href']);
        $this->assertContains('projects.internships.view', $module['enabled_actions']);
        $this->assertSame([$project->id], $module['matched_project_ids']);
    }

    public function test_project_family_module_is_hidden_when_action_scope_points_to_other_project_type(): void
    {
        $project = Project::query()->create([
            'name' => 'Eurodesk Test',
            'slug' => 'eurodesk-test',
            'type' => 'eurodesk',
            'status' => 'active',
        ]);

        Permission::findOrCreate('projects.internships.view', 'web');
        $role = Role::findOrCreate('diplomasi_family_wrong_scope', 'web');
        $role->givePermissionTo('projects.internships.view');

        RolePermissionScope::query()->create([
            'role_name' => 'diplomasi_family_wrong_scope',
            'permission_name' => 'projects.internships.view',
            'scope_type' => 'selected_projects',
            'scope_payload' => ['project_ids' => [$project->id]],
        ]);

        $user = User::factory()->create([
            'role' => 'visitor',
            'surname' => 'WrongScope',
            'email' => 'diplomasi-wrong-scope@test.local',
        ]);
        $user->assignRole('diplomasi_family_wrong_scope');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/panel/modules')->assertOk();
        $modules = collect($response->json('modules'));

        $this->assertFalse($modules->contains(fn (array $module) => $module['id'] === 'diplomasi360'));
    }

    public function test_project_family_module_is_hidden_when_no_required_project_exists(): void
    {
        Permission::findOrCreate('projects.rewards.view', 'web');
        $role = Role::findOrCreate('kademe_family_without_project', 'web');
        $role->givePermissionTo('projects.rewards.view');

        RolePermissionScope::query()->create([
            'role_name' => 'kademe_family_without_project',
            'permission_name' => 'projects.rewards.view',
            'scope_type' => 'all',
            'scope_payload' => [],
        ]);

        $user = User::factory()->create([
            'role' => 'visitor',
            'surname' => 'NoProject',
            'email' => 'kademe-no-project@test.local',
        ]);
        $user->assignRole('kademe_family_without_project');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/panel/modules')->assertOk();
        $modules = collect($response->json('modules'));

        $this->assertFalse($modules->contains(fn (array $module) => $module['id'] === 'kademe_plus'));
        $this->assertFalse($modules->contains(fn (array $module) => $module['id'] === 'zirve_kademe'));
    }

    public function test_project_specific_modules_are_grouped_under_the_same_sidebar_section(): void
    {
        $modules = collect(config('panel_modules.modules'))->keyBy('id');

        foreach (['diplomasi360', 'pergel', 'eurodesk', 'kademe_plus', 'zirve_kademe', 'kpd'] as $moduleId) {
            $this->assertSame('project_special_modules', $modules->get($moduleId)['section'] ?? null, $moduleId);
        }
    }

    public function test_config_exports_only_the_canonical_entry_permission_contract(): void
    {
        $modules = collect(config('panel_modules.modules'));

        $this->assertNotEmpty($modules);
        foreach ($modules as $module) {
            $this->assertArrayHasKey('entry_permissions', $module, $module['id'] ?? 'unknown');
            $this->assertArrayNotHasKey('view_permissions', $module, $module['id'] ?? 'unknown');
            $this->assertArrayHasKey('context_mode', $module, $module['id'] ?? 'unknown');
        }
    }
}
