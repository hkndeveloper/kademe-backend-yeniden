<?php

namespace Tests\Feature;

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitMembership;
use App\Models\CoordinationUnitPermissionRule;
use App\Models\CoordinationUnitProjectResponsibility;
use App\Models\Project;
use App\Models\User;
use App\Services\ApplicationNotificationRecipientService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationNotificationRecipientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_legacy_project_coordinator_is_kept_but_stale_or_inactive_people_are_excluded(): void
    {
        config(['coordination_authorization.mode' => 'legacy']);
        $project = $this->project('application-mail-a');
        $other = $this->project('application-mail-b');
        $current = $this->user('coordinator');
        $stale = $this->user('coordinator');
        $inactive = $this->user('coordinator', 'passive');
        $expired = $this->user('coordinator');
        $staff = $this->user('staff');
        $unitOnly = $this->user('coordinator');
        $project->coordinators()->attach([$current->id, $stale->id, $inactive->id, $expired->id, $staff->id]);
        $this->membership($stale, $this->unit($other), 'coordinator');
        $projectUnit = $this->unit($project);
        $this->membership($unitOnly, $projectUnit, 'coordinator');
        $this->membership($expired, $projectUnit, 'coordinator')->update(['ends_at' => now()->subDay()]);

        $this->assertSame([$current->email], app(ApplicationNotificationRecipientService::class)->coordinatorEmailsFor($project));
    }

    public function test_authoritative_unit_coordinator_needs_active_project_duty_and_application_permission(): void
    {
        config(['coordination_authorization.mode' => 'enforce']);
        $project = $this->project('application-mail-unit-a');
        $other = $this->project('application-mail-unit-b');
        $projectUnit = $this->unit($project);
        $otherUnit = $this->unit($other);
        $this->applicationViewRule($projectUnit);
        $this->applicationViewRule($otherUnit);

        $current = $this->user('coordinator');
        $this->membership($current, $projectUnit, 'coordinator');
        $stale = $this->user('coordinator');
        $project->coordinators()->attach($stale->id);
        $this->membership($stale, $otherUnit, 'coordinator');
        $denied = $this->user('coordinator');
        $deniedMembership = $this->membership($denied, $projectUnit, 'coordinator');
        $deniedMembership->permissionOverrides()->create([
            'permission_name' => 'applications.view',
            'effect' => 'deny',
            'status' => 'active',
        ]);
        $secondary = $this->user('coordinator');
        $this->membership($secondary, $otherUnit, 'coordinator', true);
        $this->membership($secondary, $projectUnit, 'coordinator');

        $mediaUnit = CoordinationUnit::query()->create([
            'code' => 'service_media', 'name' => 'Medya', 'kind' => 'service', 'status' => 'active',
        ]);
        CoordinationUnitProjectResponsibility::query()->create([
            'unit_id' => $mediaUnit->id,
            'project_id' => $project->id,
            'service_domain' => 'media',
            'status' => 'active',
        ]);
        $media = $this->user('coordinator');
        $this->membership($media, $mediaUnit, 'coordinator');

        $this->assertEqualsCanonicalizing(
            [$current->email, $secondary->email],
            app(ApplicationNotificationRecipientService::class)->coordinatorEmailsFor($project)
        );
    }

    private function project(string $slug): Project
    {
        return Project::query()->create([
            'name' => $slug,
            'slug' => $slug,
            'type' => 'other',
            'status' => 'active',
        ]);
    }

    private function user(string $role, string $status = 'active'): User
    {
        $user = User::factory()->create(['surname' => 'Bildirim', 'role' => $role, 'status' => $status]);
        $user->assignRole($role);

        return $user;
    }

    private function unit(Project $project): CoordinationUnit
    {
        return CoordinationUnit::query()->firstOrCreate(['project_id' => $project->id], [
            'code' => 'project_'.$project->id,
            'name' => $project->name,
            'kind' => 'project',
            'status' => 'active',
        ]);
    }

    private function membership(User $user, CoordinationUnit $unit, string $position, bool $primary = false): CoordinationUnitMembership
    {
        return CoordinationUnitMembership::query()->create([
            'unit_id' => $unit->id,
            'user_id' => $user->id,
            'position' => $position,
            'is_primary' => $primary,
            'status' => 'active',
        ]);
    }

    private function applicationViewRule(CoordinationUnit $unit): void
    {
        CoordinationUnitPermissionRule::query()->create([
            'unit_id' => $unit->id,
            'position' => 'coordinator',
            'permission_name' => 'applications.view',
            'effect' => 'allow',
            'scope_source' => 'linked_project',
            'status' => 'active',
        ]);
    }
}
