<?php

namespace Tests\Feature;

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitPermissionRule;
use App\Models\CoordinationUnitProjectResponsibility;
use App\Models\Project;
use App\Services\CoordinationAuthorizationInvariantService;
use App\Services\CoordinationCutoverReadinessService;
use App\Services\CoordinationUnitBackfillService;
use App\Services\CoordinationUnitPermissionRuleSyncService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Yf8MigrationInvariantTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->project = Project::query()->create([
            'name' => 'YF8 Invariant Project',
            'slug' => 'yf8-invariant-project',
            'type' => 'other',
            'status' => 'active',
        ]);
        app(CoordinationUnitBackfillService::class)->execute(true);
        app(CoordinationUnitPermissionRuleSyncService::class)->execute(true);
    }

    public function test_readiness_uses_security_invariants_and_preserves_safe_admin_customization(): void
    {
        $media = CoordinationUnit::query()->where('code', 'service_media')->firstOrFail();
        CoordinationUnitPermissionRule::query()
            ->where('unit_id', $media->id)
            ->where('position', 'coordinator')
            ->where('permission_name', 'projects.public_content.view')
            ->update(['scope_source' => CoordinationUnitPermissionRule::SCOPE_ALL]);

        $report = app(CoordinationCutoverReadinessService::class)->inspect('shadow');

        $this->assertTrue($report['summary']['technical_ready']);
        $this->assertTrue($report['authorization_invariants']['summary']['ready']);
        $this->assertSame(
            'security-invariants-not-template-equality',
            $report['meta']['technical_readiness_policy']
        );
        $this->assertSame(1, $report['permission_sync']['preserved_customization_count']);
        $this->assertSame(
            'legacy_only_in_enforce',
            $report['authorization_invariants']['legacy_global_role_permissions']['coordinator']['classification']
        );
        $this->assertGreaterThan(
            0,
            $report['authorization_invariants']['legacy_global_role_permissions']['coordinator']['count']
        );
    }

    public function test_wrong_exclusive_rule_is_reported_for_passivation_with_rollback_id_and_blocks_readiness(): void
    {
        $projectUnit = CoordinationUnit::query()->where('project_id', $this->project->id)->firstOrFail();
        $unsafe = CoordinationUnitPermissionRule::query()->create([
            'unit_id' => $projectUnit->id,
            'position' => 'coordinator',
            'permission_name' => 'financial.view',
            'effect' => CoordinationUnitPermissionRule::EFFECT_ALLOW,
            'scope_source' => CoordinationUnitPermissionRule::SCOPE_LINKED_PROJECT,
            'status' => CoordinationUnitPermissionRule::STATUS_ACTIVE,
        ]);

        $sync = app(CoordinationUnitPermissionRuleSyncService::class)->execute(false);
        $readiness = app(CoordinationCutoverReadinessService::class)->inspect('shadow');

        $this->assertSame(1, $sync['summary']['deactivate_count']);
        $this->assertSame([$unsafe->id], $sync['rollback']['deactivated_rule_ids']);
        $this->assertSame('exclusive_service_domain_not_owned', $sync['changes']['deactivate'][0]['reason']);
        $this->assertFalse($readiness['summary']['technical_ready']);
        $this->assertSame(
            'coordination_authorization_invariant_violations',
            $readiness['findings']['technical_blockers'][0]['code']
        );

        $apply = app(CoordinationUnitPermissionRuleSyncService::class)->execute(true);
        $second = app(CoordinationUnitPermissionRuleSyncService::class)->execute(true);

        $this->assertTrue($apply['verification']['idempotent']);
        $this->assertSame([$unsafe->id], $apply['rollback']['deactivated_rule_ids']);
        $this->assertDatabaseHas('coordination_unit_permission_rules', [
            'id' => $unsafe->id,
            'status' => CoordinationUnitPermissionRule::STATUS_PASSIVE,
        ]);
        $this->assertSame(1, CoordinationUnitPermissionRule::withTrashed()->whereKey($unsafe->id)->count());
        $this->assertSame(0, $second['summary']['applied_change_count']);
        $this->assertTrue($second['verification']['idempotent']);
    }

    public function test_extra_non_primary_service_responsibility_is_an_invariant_violation(): void
    {
        $media = CoordinationUnit::query()->where('code', 'service_media')->firstOrFail();
        CoordinationUnitProjectResponsibility::query()->create([
            'unit_id' => $media->id,
            'project_id' => $this->project->id,
            'service_domain' => 'finance_procurement',
            'is_primary' => false,
            'status' => CoordinationUnitProjectResponsibility::STATUS_ACTIVE,
        ]);

        $invariants = app(CoordinationAuthorizationInvariantService::class)->inspect();

        $this->assertFalse($invariants['summary']['ready']);
        $this->assertSame(4, $invariants['summary']['expected_service_responsibility_count']);
        $this->assertSame(5, $invariants['summary']['active_service_responsibility_count']);
        $this->assertNotNull(collect($invariants['blockers'])->firstWhere(
            'code',
            'service_responsibility_not_unique_expected_owner'
        ));
    }
}
