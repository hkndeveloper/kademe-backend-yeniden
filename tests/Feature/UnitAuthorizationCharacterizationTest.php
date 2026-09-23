<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\RolePermissionScope;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\PermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UnitAuthorizationCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): PermissionResolver
    {
        return app(PermissionResolver::class);
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

    public function test_selected_project_scope_is_resolved_per_action_without_cross_domain_leakage(): void
    {
        foreach (['financial.view', 'projects.participants.view'] as $permissionName) {
            Permission::findOrCreate($permissionName, 'web');
        }

        $role = Role::findOrCreate('service_scope_characterization', 'web');
        $role->givePermissionTo(['financial.view', 'projects.participants.view']);

        $financeProject = $this->project('Finance Project');
        $participantProject = $this->project('Participant Project');

        RolePermissionScope::query()->create([
            'role_name' => $role->name,
            'permission_name' => 'financial.view',
            'scope_type' => 'selected_projects',
            'scope_payload' => ['project_ids' => [$financeProject->id]],
        ]);
        RolePermissionScope::query()->create([
            'role_name' => $role->name,
            'permission_name' => 'projects.participants.view',
            'scope_type' => 'selected_projects',
            'scope_payload' => ['project_ids' => [$participantProject->id]],
        ]);

        $user = User::factory()->create([
            'role' => 'visitor',
            'surname' => 'Scoped',
            'email' => 'service-scope-characterization@test.local',
        ]);
        $user->assignRole($role);
        $user->refresh();

        $resolver = $this->resolver();

        $this->assertTrue($resolver->canAccessProject($user, 'financial.view', $financeProject->id));
        $this->assertFalse($resolver->canAccessProject($user, 'financial.view', $participantProject->id));
        $this->assertTrue($resolver->canAccessProject($user, 'projects.participants.view', $participantProject->id));
        $this->assertFalse($resolver->canAccessProject($user, 'projects.participants.view', $financeProject->id));
    }

    public function test_permission_with_none_scope_is_not_usable_for_project_or_global_access(): void
    {
        Permission::findOrCreate('financial.view', 'web');
        $role = Role::findOrCreate('permission_without_scope', 'web');
        $role->givePermissionTo('financial.view');

        $project = $this->project('No Scope Project');
        $user = User::factory()->create([
            'role' => 'visitor',
            'surname' => 'NoScope',
            'email' => 'permission-without-scope@test.local',
        ]);
        $user->assignRole($role);
        $user->refresh();

        $resolver = $this->resolver();

        $this->assertTrue($resolver->hasPermission($user, 'financial.view'));
        $this->assertSame('none', $resolver->scopeFor($user, 'financial.view')['scope_type']);
        $this->assertFalse($resolver->hasGlobalScope($user, 'financial.view'));
        $this->assertFalse($resolver->canAccessProject($user, 'financial.view', $project->id));
        $this->assertSame([], $resolver->projectIdsForPermission($user, 'financial.view'));
    }

    public function test_calendar_view_is_currently_organization_wide_for_coordinator_and_staff(): void
    {
        Permission::findOrCreate('calendar.view', 'web');
        $role = Role::findOrCreate('staff', 'web');
        $role->givePermissionTo('calendar.view');

        $project = $this->project('Legacy Calendar Project');
        $staff = User::factory()->create([
            'role' => 'staff',
            'surname' => 'Calendar',
            'email' => 'legacy-calendar-scope@test.local',
        ]);
        $staff->assignRole($role);
        $staff->refresh();

        $resolver = $this->resolver();

        $this->assertSame('all', $resolver->scopeFor($staff, 'calendar.view')['scope_type']);
        $this->assertTrue($resolver->canAccessProject($staff, 'calendar.view', $project->id));
    }

    public function test_media_unit_marker_is_currently_a_legacy_source_of_all_active_projects(): void
    {
        $firstProject = $this->project('First Media Project');
        $secondProject = $this->project('Second Media Project');

        $staff = User::factory()->create([
            'role' => 'staff',
            'surname' => 'Media',
            'email' => 'legacy-media-unit@test.local',
        ]);
        StaffProfile::query()->create([
            'user_id' => $staff->id,
            'title' => 'specialist',
            'unit' => 'Medya Koordinatörlüğü',
        ]);
        $staff->refresh();

        $manageableProjectIds = $this->resolver()->manageableProjectIdsForUser($staff);

        $this->assertEqualsCanonicalizing(
            [$firstProject->id, $secondProject->id],
            $manageableProjectIds
        );
    }
}
