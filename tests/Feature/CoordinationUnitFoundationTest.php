<?php

namespace Tests\Feature;

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitMembership;
use App\Models\CoordinationUnitPermissionRule;
use App\Models\CoordinationUnitProjectResponsibility;
use App\Models\LeaveRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CoordinationUnitFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function project(string $name = 'Unit Project'): Project
    {
        return Project::query()->create([
            'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'type' => 'other',
            'status' => 'active',
        ]);
    }

    private function unit(string $code, string $kind = CoordinationUnit::KIND_SERVICE, ?Project $project = null): CoordinationUnit
    {
        return CoordinationUnit::query()->create([
            'code' => $code,
            'name' => str($code)->replace('_', ' ')->title(),
            'kind' => $kind,
            'project_id' => $project?->id,
            'status' => CoordinationUnit::STATUS_ACTIVE,
        ]);
    }

    public function test_coordination_foundation_schema_is_additive(): void
    {
        $this->assertTrue(Schema::hasColumns('coordination_units', [
            'code', 'name', 'kind', 'project_id', 'status', 'deleted_at',
        ]));
        $this->assertTrue(Schema::hasColumns('coordination_unit_memberships', [
            'unit_id', 'user_id', 'position', 'is_primary', 'status', 'starts_at', 'ends_at', 'assigned_by',
        ]));
        $this->assertTrue(Schema::hasColumns('coordination_unit_project_responsibilities', [
            'unit_id', 'project_id', 'service_domain', 'is_primary', 'status',
        ]));
        $this->assertTrue(Schema::hasColumns('coordination_unit_permission_rules', [
            'unit_id', 'position', 'permission_name', 'effect', 'scope_source', 'service_domain', 'scope_payload', 'status',
        ]));

        $this->assertTrue(Schema::hasColumn('requests', 'target_unit_id'));
        $this->assertTrue(Schema::hasColumn('support_tickets', 'assigned_unit_id'));
        $this->assertTrue(Schema::hasColumn('leave_requests', 'unit_id'));
        $this->assertTrue(Schema::hasColumn('financial_transactions', 'processing_unit_id'));
    }

    public function test_user_can_have_active_memberships_in_multiple_units_but_only_one_primary_membership(): void
    {
        $user = User::factory()->create([
            'surname' => 'MultiUnit',
            'role' => 'staff',
        ]);
        $projectUnit = $this->unit('project_pergel', CoordinationUnit::KIND_PROJECT, $this->project('Pergel'));
        $mediaUnit = $this->unit('service_media');
        $communityUnit = $this->unit('service_community_culture');

        CoordinationUnitMembership::query()->create([
            'unit_id' => $projectUnit->id,
            'user_id' => $user->id,
            'position' => CoordinationUnitMembership::POSITION_COORDINATOR,
            'is_primary' => true,
        ]);
        CoordinationUnitMembership::query()->create([
            'unit_id' => $mediaUnit->id,
            'user_id' => $user->id,
            'position' => CoordinationUnitMembership::POSITION_STAFF,
            'is_primary' => false,
        ]);

        $this->assertCount(2, $user->coordinationUnitMemberships()->active()->get());

        $this->expectException(QueryException::class);
        CoordinationUnitMembership::query()->create([
            'unit_id' => $communityUnit->id,
            'user_id' => $user->id,
            'position' => CoordinationUnitMembership::POSITION_STAFF,
            'is_primary' => true,
        ]);
    }

    public function test_closed_membership_preserves_history_and_allows_a_new_active_membership(): void
    {
        $user = User::factory()->create([
            'surname' => 'History',
            'role' => 'staff',
        ]);
        $unit = $this->unit('service_finance_organization');

        $oldMembership = CoordinationUnitMembership::query()->create([
            'unit_id' => $unit->id,
            'user_id' => $user->id,
            'position' => CoordinationUnitMembership::POSITION_STAFF,
            'is_primary' => true,
        ]);
        $oldMembership->delete();

        $newMembership = CoordinationUnitMembership::query()->create([
            'unit_id' => $unit->id,
            'user_id' => $user->id,
            'position' => CoordinationUnitMembership::POSITION_COORDINATOR,
            'is_primary' => true,
        ]);

        $this->assertNotSame($oldMembership->id, $newMembership->id);
        $this->assertCount(1, $user->coordinationUnitMemberships()->active()->get());
        $this->assertCount(2, $user->coordinationUnitMemberships()->withTrashed()->get());
    }

    public function test_project_responsibility_permission_rule_and_workflow_snapshot_relations_are_available(): void
    {
        $project = $this->project('Finance Responsibility Project');
        $serviceUnit = $this->unit('service_purchase_organization');
        $user = User::factory()->create([
            'surname' => 'Workflow',
            'role' => 'staff',
        ]);

        CoordinationUnitProjectResponsibility::query()->create([
            'unit_id' => $serviceUnit->id,
            'project_id' => $project->id,
            'service_domain' => 'finance_procurement',
            'is_primary' => true,
        ]);
        $rule = CoordinationUnitPermissionRule::query()->create([
            'unit_id' => $serviceUnit->id,
            'position' => CoordinationUnitMembership::POSITION_COORDINATOR,
            'permission_name' => 'financial.view',
            'effect' => CoordinationUnitPermissionRule::EFFECT_ALLOW,
            'scope_source' => CoordinationUnitPermissionRule::SCOPE_RESPONSIBILITY_PROJECTS,
            'service_domain' => 'finance_procurement',
            'scope_payload' => ['source' => 'phase_2_test'],
        ]);
        $leaveRequest = LeaveRequest::query()->create([
            'user_id' => $user->id,
            'unit_id' => $serviceUnit->id,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'reason' => 'Test',
            'status' => 'pending',
        ]);

        $this->assertTrue($serviceUnit->projectResponsibilities()->active()->first()->project->is($project));
        $this->assertSame(['source' => 'phase_2_test'], $rule->scope_payload);
        $this->assertTrue($leaveRequest->unit->is($serviceUnit));
        $this->assertTrue($serviceUnit->leaveRequests->first()->is($leaveRequest));
    }

    public function test_project_and_service_domain_can_have_only_one_active_primary_service_unit(): void
    {
        $project = $this->project('Single Primary Service Project');
        $firstServiceUnit = $this->unit('service_media_primary');
        $secondServiceUnit = $this->unit('service_media_secondary');

        CoordinationUnitProjectResponsibility::query()->create([
            'unit_id' => $firstServiceUnit->id,
            'project_id' => $project->id,
            'service_domain' => 'media',
            'is_primary' => true,
        ]);

        $this->expectException(QueryException::class);
        CoordinationUnitProjectResponsibility::query()->create([
            'unit_id' => $secondServiceUnit->id,
            'project_id' => $project->id,
            'service_domain' => 'media',
            'is_primary' => true,
        ]);
    }
}
