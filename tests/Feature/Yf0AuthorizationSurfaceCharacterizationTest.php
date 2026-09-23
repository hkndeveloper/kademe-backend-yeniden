<?php

namespace Tests\Feature;

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitMembership;
use App\Models\CoordinationUnitPermissionRule;
use App\Models\Project;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\PermissionResolver;
use App\Support\PanelModuleCatalog;
use App\Support\ProjectUnitPermissionTemplateCatalog;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Yf0AuthorizationSurfaceCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private array $baseline;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        config()->set('coordination_authorization.mode', 'enforce');
        $this->baseline = require base_path('tests/Fixtures/yf0_authorization_baseline.php');
    }

    public function test_all_nine_units_and_both_positions_match_the_current_module_baseline(): void
    {
        $projects = Project::query()->where('status', 'active')->orderBy('id')->get();
        $this->assertCount(6, $projects);

        foreach ($projects as $project) {
            $suffix = str_pad((string) $project->id, 2, '0', STR_PAD_LEFT);
            $extraModules = $this->baseline['project_extra_modules_by_type'][$project->type] ?? [];
            $coordinatorExpected = [...$this->baseline['project_coordinator_modules'], ...$extraModules];
            $staffExpected = [...$this->baseline['project_staff_modules'], ...$extraModules];

            $this->assertSameSorted(
                $coordinatorExpected,
                $this->moduleIdsForEmail("demo.coordinator.p{$suffix}@kademe.org"),
                "Unexpected coordinator module surface for {$project->name}."
            );
            $this->assertSameSorted(
                $staffExpected,
                $this->moduleIdsForEmail("demo.staff.p{$suffix}@kademe.org"),
                "Unexpected staff module surface for {$project->name}."
            );
        }

        $serviceEmailSlugs = [
            'service_media' => 'media',
            'service_purchase_organization' => 'purchase.organization',
            'service_community_culture' => 'community.culture',
        ];

        foreach ($serviceEmailSlugs as $unitCode => $emailSlug) {
            foreach (['coordinator', 'staff'] as $position) {
                $this->assertSameSorted(
                    $this->baseline['service_modules'][$unitCode][$position],
                    $this->moduleIdsForEmail("demo.{$position}.{$emailSlug}@kademe.org"),
                    "Unexpected {$position} module surface for {$unitCode}."
                );
            }
        }
    }

    public function test_machine_readable_route_module_and_action_inventory_counts_are_frozen(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes());
        $routeCounts = [
            'total' => $routes->count(),
            'api_total' => $routes->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))->count(),
        ];

        foreach (['api/panel/', 'api/admin/', 'api/coordinator/', 'api/staff/'] as $prefix) {
            $routeCounts[$prefix] = $routes
                ->filter(fn ($route) => str_starts_with($route->uri(), $prefix))
                ->count();
        }

        $authorityModules = collect(config('panel_modules.modules'))
            ->where('panel_type', 'authority');
        $actual = [
            'routes' => $routeCounts,
            'authority_modules' => $authorityModules->count(),
            'authority_actions' => $authorityModules
                ->flatMap(fn (array $module) => $module['actions'] ?? [])
                ->unique()
                ->count(),
            'authority_entry_permissions' => $authorityModules
                ->flatMap(fn (array $module) => $module['entry_permissions'] ?? $module['view_permissions'] ?? [])
                ->unique()
                ->count(),
            'granular_permissions' => collect(config('permission_catalog.granular_permissions'))
                ->flatten()
                ->unique()
                ->count(),
        ];

        $this->assertSame($this->baseline['inventory_counts'], $actual);
        $this->assertJson(json_encode($this->baseline, JSON_THROW_ON_ERROR));
    }

    public function test_current_permission_rule_counts_and_exclusive_service_boundaries_are_frozen(): void
    {
        $projectUnits = CoordinationUnit::query()
            ->where('kind', CoordinationUnit::KIND_PROJECT)
            ->orderBy('id')
            ->get();
        $this->assertCount(6, $projectUnits);

        foreach ($projectUnits as $unit) {
            $project = $unit->project()->firstOrFail();
            foreach ($this->baseline['project_rule_counts_by_type'][$project->type] as $position => $expectedCount) {
                $this->assertSame(
                    $expectedCount,
                    CoordinationUnitPermissionRule::query()
                        ->active()
                        ->where('unit_id', $unit->id)
                        ->where('position', $position)
                        ->count(),
                    "Unexpected {$position} rule count for {$unit->code}."
                );
            }

            foreach ($this->baseline['forbidden_project_service_permissions'] as $permission) {
                $this->assertDatabaseMissing('coordination_unit_permission_rules', [
                    'unit_id' => $unit->id,
                    'position' => CoordinationUnitMembership::POSITION_COORDINATOR,
                    'permission_name' => $permission,
                    'status' => CoordinationUnitPermissionRule::STATUS_ACTIVE,
                ]);
            }

            $expectedFamilyPermissions = ProjectUnitPermissionTemplateCatalog::permissionsFor(
                $project,
                CoordinationUnitMembership::POSITION_COORDINATOR
            );
            foreach ($this->baseline['all_project_family_permissions'] as $permission) {
                $query = CoordinationUnitPermissionRule::query()
                    ->active()
                    ->where('unit_id', $unit->id)
                    ->where('position', CoordinationUnitMembership::POSITION_COORDINATOR)
                    ->where('permission_name', $permission);

                $this->assertSame(
                    in_array($permission, $expectedFamilyPermissions, true),
                    $query->exists(),
                    "Unexpected family permission {$permission} for {$project->type}."
                );
            }
        }

        foreach ($this->baseline['service_rule_counts'] as $unitCode => $counts) {
            $unit = CoordinationUnit::query()->where('code', $unitCode)->firstOrFail();

            foreach ($counts as $position => $expectedCount) {
                $this->assertSame(
                    $expectedCount,
                    CoordinationUnitPermissionRule::query()
                        ->active()
                        ->where('unit_id', $unit->id)
                        ->where('position', $position)
                        ->count(),
                    "Unexpected {$position} rule count for {$unitCode}."
                );
            }
        }
    }

    public function test_announcement_view_opens_only_announcement_management(): void
    {
        $modules = collect(config('panel_modules.modules'));
        $actual = $modules
            ->filter(fn (array $module) => in_array('announcements.view', $module['entry_permissions'] ?? $module['view_permissions'] ?? [], true))
            ->pluck('id')
            ->values()
            ->all();

        $this->assertSameSorted($this->baseline['announcement_view_modules'], $actual);
    }

    public function test_purchase_has_narrow_logistics_program_entry_and_profile_is_backend_visible(): void
    {
        $purchaseCoordinator = $this->user('demo.coordinator.purchase.organization@kademe.org');
        $resolver = app(PermissionResolver::class);
        $moduleIds = $this->moduleIds($purchaseCoordinator);

        $this->assertTrue($resolver->hasPermission($purchaseCoordinator, 'programs.logistics.update'));
        $this->assertTrue($resolver->hasPermission($purchaseCoordinator, 'programs.logistics.view'));
        $this->assertFalse($resolver->hasPermission($purchaseCoordinator, 'programs.view'));
        $this->assertFalse($resolver->hasPermission($purchaseCoordinator, 'programs.community_event.view'));
        $this->assertContains('programs', $moduleIds);
        $this->assertContains('profile', $moduleIds);
    }

    public function test_enforce_uses_unit_rules_while_the_global_coordinator_role_still_contains_all_finance_actions(): void
    {
        $mediaCoordinator = $this->user('demo.coordinator.media@kademe.org');
        $coordinatorRole = Role::findByName('coordinator', 'web');
        $financePermissions = [
            'financial.view',
            'financial.create',
            'financial.update',
            'financial.delete',
            'financial.approve',
            'financial.reject',
            'financial.mark_paid',
            'financial.export',
            'financial.invoice.download',
        ];

        foreach ($financePermissions as $permission) {
            $this->assertTrue($coordinatorRole->hasPermissionTo($permission));
        }

        $resolver = app(PermissionResolver::class);
        $this->assertFalse($resolver->hasPermission($mediaCoordinator, 'financial.view'));
        $this->assertSame(
            'coordination_units',
            $resolver->resolve($mediaCoordinator)['authorization_meta']['authoritative_source']
        );
    }

    public function test_multiple_memberships_use_the_primary_unit_until_an_explicit_header_selects_another_unit(): void
    {
        $mediaCoordinator = $this->user('demo.coordinator.media@kademe.org');
        $purchaseUnit = CoordinationUnit::query()
            ->where('code', 'service_purchase_organization')
            ->firstOrFail();

        CoordinationUnitMembership::query()->firstOrCreate([
            'unit_id' => $purchaseUnit->id,
            'user_id' => $mediaCoordinator->id,
        ], [
            'position' => CoordinationUnitMembership::POSITION_STAFF,
            'is_primary' => false,
            'status' => CoordinationUnitMembership::STATUS_ACTIVE,
        ]);

        $resolver = app(PermissionResolver::class);
        $resolved = $resolver->resolve($mediaCoordinator->fresh());

        $this->assertContains('projects.public_content.update', $resolved['effective_permissions']->all());
        $this->assertNotContains('financial.view', $resolved['effective_permissions']->all());
        $this->assertCount(2, $resolved['contexts']['unit_memberships']);
        $this->assertContains('content', $this->moduleIds($mediaCoordinator->fresh()));
        $this->assertNotContains('financials', $this->moduleIds($mediaCoordinator->fresh()));
    }

    public function test_legacy_global_business_allow_override_no_longer_opens_a_module_outside_the_active_membership(): void
    {
        $mediaStaff = $this->user('demo.staff.media@kademe.org');
        $projectId = (int) Project::query()->orderBy('id')->value('id');

        $this->assertNotContains('financials', $this->moduleIds($mediaStaff));

        UserPermissionOverride::query()->create([
            'user_id' => $mediaStaff->id,
            'permission_name' => 'financial.view',
            'effect' => 'allow',
            'scope_type' => 'selected_projects',
            'scope_payload' => ['project_ids' => [$projectId]],
        ]);

        $resolved = app(PermissionResolver::class)->resolve($mediaStaff->fresh());
        $membership = collect($resolved['contexts']['unit_memberships'])->first();

        $this->assertNotContains('financial.view', $resolved['effective_permissions']->all());
        $this->assertNotContains('financial.view', $membership['permissions']);
        $this->assertNotContains('financials', $this->moduleIds($mediaStaff->fresh()));
    }

    private function moduleIdsForEmail(string $email): array
    {
        return $this->moduleIds($this->user($email));
    }

    private function moduleIds(User $user): array
    {
        return collect(app(PanelModuleCatalog::class)->visibleFor($user)['modules'])
            ->where('panel_type', 'authority')
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    private function assertSameSorted(array $expected, array $actual, string $message = ''): void
    {
        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual, $message);
    }
}
