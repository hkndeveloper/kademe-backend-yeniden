<?php

namespace Tests\Feature;

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitPermissionRule;
use App\Models\Project;
use App\Services\CoordinationUnitBackfillService;
use App\Services\CoordinationUnitPermissionRuleSyncService;
use App\Support\CoordinationUnitPermissionTemplateCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoordinationUnitPermissionRuleSyncTest extends TestCase
{
    use RefreshDatabase;

    private function createProject(): Project
    {
        return Project::query()->create([
            'name' => 'Template Project',
            'slug' => 'template-project-'.uniqid(),
            'type' => 'other',
            'status' => 'active',
        ]);
    }

    public function test_all_template_permissions_exist_in_the_granular_permission_catalog(): void
    {
        $this->createProject();
        app(CoordinationUnitBackfillService::class)->execute(true);

        $catalogPermissions = collect(config('permission_catalog.granular_permissions'))
            ->flatten()
            ->map(fn ($permission) => (string) $permission)
            ->all();

        foreach (CoordinationUnit::query()->get() as $unit) {
            foreach (CoordinationUnitPermissionTemplateCatalog::rulesFor($unit) as $rule) {
                $this->assertContains($rule['permission_name'], $catalogPermissions, $rule['permission_name']);
            }
        }
    }

    public function test_permission_sync_is_dry_run_by_default_and_apply_is_idempotent(): void
    {
        $this->createProject();
        app(CoordinationUnitBackfillService::class)->execute(true);
        $service = app(CoordinationUnitPermissionRuleSyncService::class);

        $dryRun = $service->execute(false);
        $this->assertGreaterThan(0, $dryRun['summary']['proposed_change_count']);
        $this->assertSame(0, CoordinationUnitPermissionRule::query()->count());

        $firstApply = $service->execute(true);
        $secondApply = $service->execute(true);

        $this->assertGreaterThan(0, $firstApply['summary']['applied_change_count']);
        $this->assertTrue($firstApply['verification']['healthy']);
        $this->assertTrue($firstApply['verification']['idempotent']);
        $this->assertSame(0, $secondApply['summary']['applied_change_count']);
        $this->assertTrue($secondApply['verification']['idempotent']);
    }

    public function test_customized_active_rule_is_preserved_without_blocking_sync(): void
    {
        $this->createProject();
        app(CoordinationUnitBackfillService::class)->execute(true);
        $service = app(CoordinationUnitPermissionRuleSyncService::class);
        $service->execute(true);
        $media = CoordinationUnit::query()->where('code', 'service_media')->firstOrFail();

        CoordinationUnitPermissionRule::query()
            ->where('unit_id', $media->id)
            ->where('position', 'coordinator')
            ->where('permission_name', 'projects.public_content.view')
            ->update(['scope_source' => CoordinationUnitPermissionRule::SCOPE_ALL]);

        $report = $service->execute(true);

        $this->assertSame(0, $report['summary']['blocker_count']);
        $this->assertGreaterThan(0, $report['summary']['preserved_customization_count']);
        $this->assertSame(0, $report['summary']['applied_change_count']);
        $this->assertSame(
            CoordinationUnitPermissionRule::SCOPE_ALL,
            CoordinationUnitPermissionRule::query()->where('permission_name', 'projects.public_content.view')->value('scope_source')
        );
    }

    public function test_disabled_default_is_not_reactivated_until_explicit_reset(): void
    {
        $this->createProject();
        app(CoordinationUnitBackfillService::class)->execute(true);
        $service = app(CoordinationUnitPermissionRuleSyncService::class);
        $service->execute(true);

        $media = CoordinationUnit::query()->where('code', 'service_media')->firstOrFail();
        $rule = CoordinationUnitPermissionRule::query()
            ->where('unit_id', $media->id)
            ->where('position', 'coordinator')
            ->where('permission_name', 'projects.public_content.view')
            ->firstOrFail();
        $rule->update([
            'status' => CoordinationUnitPermissionRule::STATUS_PASSIVE,
            'ends_at' => now(),
        ]);

        $defaultSync = $service->execute(true);
        $this->assertSame(0, $defaultSync['summary']['applied_change_count']);
        $this->assertGreaterThan(0, $defaultSync['summary']['preserved_customization_count']);
        $this->assertFalse(CoordinationUnitPermissionRule::query()
            ->active()
            ->where('unit_id', $media->id)
            ->where('position', 'coordinator')
            ->where('permission_name', 'projects.public_content.view')
            ->exists());

        $reset = $service->execute(true, true);
        $this->assertGreaterThan(0, $reset['summary']['applied_change_count']);
        $this->assertTrue($reset['verification']['idempotent']);
        $this->assertTrue(CoordinationUnitPermissionRule::query()
            ->active()
            ->where('unit_id', $media->id)
            ->where('position', 'coordinator')
            ->where('permission_name', 'projects.public_content.view')
            ->exists());
    }

    public function test_new_family_component_is_added_to_initialized_unit_without_filling_missing_core_defaults(): void
    {
        $project = Project::query()->create([
            'name' => 'Diplomasi360',
            'slug' => 'legacy-family-component',
            'type' => 'diplomasi360',
            'status' => 'active',
        ]);
        app(CoordinationUnitBackfillService::class)->execute(true);
        $unit = CoordinationUnit::query()->where('project_id', $project->id)->firstOrFail();
        CoordinationUnitPermissionRule::query()->create([
            'unit_id' => $unit->id,
            'position' => 'staff',
            'permission_name' => 'projects.view',
            'effect' => CoordinationUnitPermissionRule::EFFECT_ALLOW,
            'scope_source' => CoordinationUnitPermissionRule::SCOPE_LINKED_PROJECT,
            'status' => CoordinationUnitPermissionRule::STATUS_ACTIVE,
        ]);

        $report = app(CoordinationUnitPermissionRuleSyncService::class)->execute(true);

        $this->assertGreaterThan(0, $report['summary']['applied_change_count']);
        $this->assertTrue(CoordinationUnitPermissionRule::query()
            ->active()
            ->where('unit_id', $unit->id)
            ->where('position', 'staff')
            ->where('permission_name', 'projects.internships.view')
            ->exists());
        $this->assertFalse(CoordinationUnitPermissionRule::query()
            ->active()
            ->where('unit_id', $unit->id)
            ->where('position', 'coordinator')
            ->where('permission_name', 'projects.export')
            ->exists());
    }

    public function test_inapplicable_legacy_project_family_rule_is_passivated_not_deleted(): void
    {
        $project = Project::query()->create([
            'name' => 'Diplomasi360',
            'slug' => 'diplomasi-family-transition',
            'type' => 'diplomasi360',
            'status' => 'active',
        ]);
        app(CoordinationUnitBackfillService::class)->execute(true);
        $service = app(CoordinationUnitPermissionRuleSyncService::class);
        $service->execute(true);
        $unit = CoordinationUnit::query()->where('project_id', $project->id)->firstOrFail();

        $legacy = CoordinationUnitPermissionRule::query()->create([
            'unit_id' => $unit->id,
            'position' => 'coordinator',
            'permission_name' => 'assignments.view',
            'effect' => CoordinationUnitPermissionRule::EFFECT_ALLOW,
            'scope_source' => CoordinationUnitPermissionRule::SCOPE_LINKED_PROJECT,
            'status' => CoordinationUnitPermissionRule::STATUS_ACTIVE,
        ]);

        $report = $service->execute(true);

        $this->assertSame(1, $report['summary']['deactivate_count']);
        $this->assertSame(1, $report['summary']['applied_change_count']);
        $this->assertDatabaseHas('coordination_unit_permission_rules', [
            'id' => $legacy->id,
            'status' => CoordinationUnitPermissionRule::STATUS_PASSIVE,
        ]);
        $this->assertSame(1, CoordinationUnitPermissionRule::withTrashed()->whereKey($legacy->id)->count());
    }

    public function test_wrong_exclusive_service_rules_are_passivated_and_new_inbox_default_is_added_without_restoring_history(): void
    {
        $project = $this->createProject();
        app(CoordinationUnitBackfillService::class)->execute(true);
        $unit = CoordinationUnit::query()->where('project_id', $project->id)->firstOrFail();
        $legacyRules = collect(['financial.view', 'announcements.view', 'content.view', 'volunteer.view'])
            ->map(fn (string $permission) => CoordinationUnitPermissionRule::query()->create([
                'unit_id' => $unit->id,
                'position' => 'coordinator',
                'permission_name' => $permission,
                'effect' => CoordinationUnitPermissionRule::EFFECT_ALLOW,
                'scope_source' => CoordinationUnitPermissionRule::SCOPE_LINKED_PROJECT,
                'status' => CoordinationUnitPermissionRule::STATUS_ACTIVE,
            ]));

        $report = app(CoordinationUnitPermissionRuleSyncService::class)->execute(true);

        $this->assertSame(4, $report['summary']['deactivate_count']);
        foreach ($legacyRules as $rule) {
            $this->assertDatabaseHas('coordination_unit_permission_rules', [
                'id' => $rule->id,
                'status' => CoordinationUnitPermissionRule::STATUS_PASSIVE,
            ]);
            $this->assertSame(1, CoordinationUnitPermissionRule::withTrashed()->whereKey($rule->id)->count());
        }
        $this->assertDatabaseHas('coordination_unit_permission_rules', [
            'unit_id' => $unit->id,
            'position' => 'coordinator',
            'permission_name' => 'inbox.view',
            'status' => CoordinationUnitPermissionRule::STATUS_ACTIVE,
        ]);

        $second = app(CoordinationUnitPermissionRuleSyncService::class)->execute(true);
        $this->assertSame(0, $second['summary']['applied_change_count']);
        $this->assertTrue($second['verification']['idempotent']);
    }

    public function test_legacy_community_motivation_scope_is_migrated_to_global_owner_scope(): void
    {
        $this->createProject();
        app(CoordinationUnitBackfillService::class)->execute(true);
        $service = app(CoordinationUnitPermissionRuleSyncService::class);
        $service->execute(true);
        $community = CoordinationUnit::query()->where('code', 'service_community_culture')->firstOrFail();
        $rule = CoordinationUnitPermissionRule::query()
            ->where('unit_id', $community->id)
            ->where('position', 'coordinator')
            ->where('permission_name', 'motivation.view')
            ->firstOrFail();
        $rule->update([
            'scope_source' => CoordinationUnitPermissionRule::SCOPE_RESPONSIBILITY_PROJECTS,
            'service_domain' => 'community_culture',
        ]);

        $report = $service->execute(true);

        $this->assertSame(1, $report['summary']['update_count']);
        $this->assertSame(CoordinationUnitPermissionRule::SCOPE_ALL, $rule->fresh()->scope_source);
        $this->assertSame('community_culture', $rule->fresh()->service_domain);
        $this->assertTrue($report['verification']['idempotent']);
    }

    public function test_community_staff_event_write_retirement_is_non_destructive_and_idempotent(): void
    {
        $this->createProject();
        app(CoordinationUnitBackfillService::class)->execute(true);
        app(CoordinationUnitPermissionRuleSyncService::class)->execute(true);
        $community = CoordinationUnit::query()->where('code', 'service_community_culture')->firstOrFail();

        foreach (['programs.community_event.create', 'programs.community_event.update'] as $permission) {
            CoordinationUnitPermissionRule::query()->create([
                'unit_id' => $community->id,
                'position' => 'staff',
                'permission_name' => $permission,
                'effect' => CoordinationUnitPermissionRule::EFFECT_ALLOW,
                'scope_source' => CoordinationUnitPermissionRule::SCOPE_RESPONSIBILITY_PROJECTS,
                'service_domain' => 'community_culture',
                'status' => CoordinationUnitPermissionRule::STATUS_ACTIVE,
            ]);
        }

        $migration = require database_path('migrations/2026_09_23_000001_retire_community_staff_event_write_permissions.php');
        $migration->up();
        $migration->up();

        foreach (['programs.community_event.create', 'programs.community_event.update'] as $permission) {
            $this->assertDatabaseHas('coordination_unit_permission_rules', [
                'unit_id' => $community->id,
                'position' => 'staff',
                'permission_name' => $permission,
                'status' => CoordinationUnitPermissionRule::STATUS_PASSIVE,
            ]);
            $this->assertTrue(CoordinationUnitPermissionRule::query()
                ->where('unit_id', $community->id)
                ->where('position', 'staff')
                ->where('permission_name', $permission)
                ->whereNotNull('ends_at')
                ->exists());
            $this->assertTrue(CoordinationUnitPermissionRule::query()
                ->active()
                ->where('unit_id', $community->id)
                ->where('position', 'coordinator')
                ->where('permission_name', $permission)
                ->exists());
        }

        foreach ([
            'programs.community_event.view',
            'programs.community_event.attendance.manage',
            'programs.logistics.update',
        ] as $permission) {
            $this->assertTrue(CoordinationUnitPermissionRule::query()
                ->active()
                ->where('unit_id', $community->id)
                ->where('position', 'staff')
                ->where('permission_name', $permission)
                ->exists());
        }
    }

    public function test_explicit_project_metadata_change_reconciles_only_that_units_family_rules(): void
    {
        $project = Project::query()->create([
            'name' => 'Diplomasi360',
            'slug' => 'metadata-reconcile',
            'type' => 'diplomasi360',
            'status' => 'active',
        ]);
        app(CoordinationUnitBackfillService::class)->execute(true);
        $service = app(CoordinationUnitPermissionRuleSyncService::class);
        $service->execute(true);
        $unit = CoordinationUnit::query()->where('project_id', $project->id)->firstOrFail();

        $project->update(['special_modules' => ['assignments']]);
        $changes = $service->reconcileProjectFamily($unit);

        $this->assertGreaterThan(0, $changes);
        foreach (['coordinator', 'staff'] as $position) {
            $this->assertTrue(CoordinationUnitPermissionRule::query()
                ->active()
                ->where('unit_id', $unit->id)
                ->where('position', $position)
                ->where('permission_name', 'assignments.view')
                ->exists());
            $this->assertFalse(CoordinationUnitPermissionRule::query()
                ->active()
                ->where('unit_id', $unit->id)
                ->where('position', $position)
                ->where('permission_name', 'projects.internships.view')
                ->exists());
        }
        $this->assertTrue(CoordinationUnitPermissionRule::query()
            ->active()
            ->where('unit_id', $unit->id)
            ->where('permission_name', 'projects.view')
            ->exists());
    }
}
