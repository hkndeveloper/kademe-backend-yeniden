<?php

namespace Tests\Feature;

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitMembership;
use App\Models\CoordinationUnitPermissionRule;
use App\Models\CoordinationUnitProjectResponsibility;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use App\Services\CoordinationCutoverReadinessService;
use App\Services\PermissionResolver;
use App\Support\CoordinationUnitCatalog;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoProjectRoleDataSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DemoCoordinationSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_builds_an_idempotent_full_coordination_acceptance_fixture(): void
    {
        $this->seed(DatabaseSeeder::class);

        $projects = Project::query()->where('status', 'active')->orderBy('id')->get();
        $this->assertCount(6, $projects);
        $this->assertSame(9, CoordinationUnit::query()->active()->count());
        $this->assertSame(24, CoordinationUnitProjectResponsibility::query()->active()->count());
        $this->assertGreaterThan(0, CoordinationUnitPermissionRule::query()->active()->count());
        $this->assertSame(12, Program::query()->count());

        foreach ($projects as $project) {
            $suffix = str_pad((string) $project->id, 2, '0', STR_PAD_LEFT);
            $unit = CoordinationUnit::query()
                ->where('code', CoordinationUnitCatalog::projectUnitCode((int) $project->id))
                ->firstOrFail();
            $coordinator = User::query()->where('email', "demo.coordinator.p{$suffix}@kademe.org")->firstOrFail();
            $staff = User::query()->where('email', "demo.staff.p{$suffix}@kademe.org")->firstOrFail();

            $this->assertTrue($project->coordinators()->whereKey($coordinator->id)->exists());
            $this->assertTrue($project->assignedStaff()->whereKey($staff->id)->exists());
            $this->assertDatabaseHas('coordination_unit_memberships', [
                'unit_id' => $unit->id,
                'user_id' => $coordinator->id,
                'position' => CoordinationUnitMembership::POSITION_COORDINATOR,
                'is_primary' => true,
                'status' => CoordinationUnitMembership::STATUS_ACTIVE,
            ]);
            $this->assertDatabaseHas('programs', [
                'project_id' => $project->id,
                'program_kind' => Program::KIND_CORE_PROGRAM,
                'managing_unit_id' => null,
                'status' => 'active',
            ]);
            $this->assertDatabaseHas('programs', [
                'project_id' => $project->id,
                'program_kind' => Program::KIND_COMMUNITY_EVENT,
                'status' => 'active',
            ]);
            $this->assertDatabaseHas('coordination_unit_memberships', [
                'unit_id' => $unit->id,
                'user_id' => $staff->id,
                'position' => CoordinationUnitMembership::POSITION_STAFF,
                'is_primary' => true,
                'status' => CoordinationUnitMembership::STATUS_ACTIVE,
            ]);
        }

        foreach (array_keys(CoordinationUnitCatalog::serviceUnits()) as $unitCode) {
            $unit = CoordinationUnit::query()->where('code', $unitCode)->firstOrFail();
            $memberships = CoordinationUnitMembership::query()->active()->where('unit_id', $unit->id)->get();

            $this->assertGreaterThanOrEqual(2, $memberships->count());
            $this->assertGreaterThanOrEqual(1, $memberships->where('position', CoordinationUnitMembership::POSITION_COORDINATOR)->count());
            $this->assertGreaterThanOrEqual(1, $memberships->where('position', CoordinationUnitMembership::POSITION_STAFF)->count());
        }

        $mediaCoordinator = User::query()->where('email', 'demo.coordinator.media@kademe.org')->firstOrFail();
        $projectStaff = User::query()->where('email', 'demo.staff.p01@kademe.org')->firstOrFail();
        $this->assertSame(2, $mediaCoordinator->coordinationUnitMemberships()->active()->count());
        $this->assertSame(2, $projectStaff->coordinationUnitMemberships()->active()->count());
        $this->assertSame(1, $mediaCoordinator->coordinationUnitMemberships()->active()->where('is_primary', true)->count());
        $this->assertSame(1, $projectStaff->coordinationUnitMemberships()->active()->where('is_primary', true)->count());

        $legacyExample = User::query()->where('email', 'koordinator@kademe.org')->firstOrFail();
        $this->assertSame(1, $legacyExample->coordinationUnitMemberships()->active()->count());

        $enforceReadiness = app(CoordinationCutoverReadinessService::class)->inspect('enforce');
        $this->assertTrue($enforceReadiness['summary']['technical_ready']);
        $this->assertTrue($enforceReadiness['summary']['membership_data_ready']);
        $this->assertTrue($enforceReadiness['summary']['requested_target_ready']);
        $this->assertSame(19, $enforceReadiness['summary']['active_authority_user_count']);
        $this->assertSame(21, $enforceReadiness['summary']['active_membership_count']);
        $this->assertSame(
            0,
            User::query()->where('email', 'like', 'demo.%@kademe.org')->whereNull('kvkk_consent_at')->count()
        );

        $this->assertDemoAuthorizationMatrix($projects);

        $countsBeforeSecondRun = $this->coordinationCounts();
        $this->app->call([
            $this->app->make(DemoProjectRoleDataSeeder::class),
            'run',
        ]);

        $this->assertSame($countsBeforeSecondRun, $this->coordinationCounts());
        $this->assertTrue(
            app(CoordinationCutoverReadinessService::class)->inspect('enforce')['summary']['requested_target_ready']
        );
    }

    public function test_demo_seeder_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('yalniz local/testing ortaminda calistirilabilir');

        $this->app->call([
            $this->app->make(DemoProjectRoleDataSeeder::class),
            'run',
        ]);
    }

    public function test_demo_seeder_does_not_restore_an_admin_disabled_unit_rule(): void
    {
        $this->seed(DatabaseSeeder::class);
        $media = CoordinationUnit::query()->where('code', 'service_media')->firstOrFail();
        $rule = CoordinationUnitPermissionRule::query()
            ->where('unit_id', $media->id)
            ->where('position', CoordinationUnitMembership::POSITION_COORDINATOR)
            ->where('permission_name', 'projects.public_content.update')
            ->firstOrFail();
        $rule->update([
            'status' => CoordinationUnitPermissionRule::STATUS_PASSIVE,
            'ends_at' => now(),
        ]);

        $this->app->call([
            $this->app->make(DemoProjectRoleDataSeeder::class),
            'run',
        ]);

        $this->assertDatabaseHas('coordination_unit_permission_rules', [
            'id' => $rule->id,
            'status' => CoordinationUnitPermissionRule::STATUS_PASSIVE,
        ]);
        $this->assertFalse(CoordinationUnitPermissionRule::query()
            ->active()
            ->where('unit_id', $media->id)
            ->where('position', CoordinationUnitMembership::POSITION_COORDINATOR)
            ->where('permission_name', 'projects.public_content.update')
            ->exists());
    }

    private function assertDemoAuthorizationMatrix(Collection $projects): void
    {
        config()->set('coordination_authorization.mode', 'enforce');
        $resolver = app(PermissionResolver::class);
        $projectIds = $projects->pluck('id')->map(fn ($id) => (int) $id)->all();

        $mediaCoordinator = User::query()->where('email', 'demo.coordinator.media@kademe.org')->firstOrFail();
        $this->assertTrue($resolver->hasPermission($mediaCoordinator, 'projects.public_content.update'));
        $this->assertSame($projectIds, $resolver->projectIdsForPermission($mediaCoordinator, 'projects.public_content.update'));
        $this->assertFalse($resolver->hasPermission($mediaCoordinator, 'financial.view'));

        $purchaseStaff = User::query()->where('email', 'demo.staff.purchase.organization@kademe.org')->firstOrFail();
        $this->assertTrue($resolver->hasPermission($purchaseStaff, 'financial.view'));
        $this->assertFalse($resolver->hasPermission($purchaseStaff, 'financial.approve'));
        $this->assertSame($projectIds, $resolver->projectIdsForPermission($purchaseStaff, 'financial.view'));

        $communityStaff = User::query()->where('email', 'demo.staff.community.culture@kademe.org')->firstOrFail();
        $this->assertTrue($resolver->hasPermission($communityStaff, 'programs.community_event.attendance.manage'));
        $this->assertFalse($resolver->hasPermission($communityStaff, 'programs.community_event.attendance.export'));
        $this->assertFalse($resolver->hasPermission($communityStaff, 'financial.view'));

        $firstProject = $projects->firstOrFail();
        $suffix = str_pad((string) $firstProject->id, 2, '0', STR_PAD_LEFT);
        $projectStaff = User::query()->where('email', "demo.staff.p{$suffix}@kademe.org")->firstOrFail();
        $this->assertTrue($resolver->hasPermission($projectStaff, 'programs.attendance.manage'));
        $this->assertSame(
            [(int) $firstProject->id],
            $resolver->projectIdsForPermission($projectStaff, 'programs.attendance.manage')
        );
        $this->assertFalse($resolver->hasPermission($projectStaff, 'financial.approve'));
    }

    private function coordinationCounts(): array
    {
        return [
            'users' => User::query()->count(),
            'units' => CoordinationUnit::query()->count(),
            'memberships' => CoordinationUnitMembership::query()->count(),
            'responsibilities' => CoordinationUnitProjectResponsibility::query()->count(),
            'permission_rules' => CoordinationUnitPermissionRule::query()->count(),
            'programs' => Program::query()->count(),
        ];
    }
}
