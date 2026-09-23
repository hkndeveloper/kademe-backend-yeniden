<?php

namespace Tests\Feature;

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitMembership;
use App\Models\LeaveRequest;
use App\Models\Request as WorkflowRequest;
use App\Models\SupportTicket;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CoordinationWorkflowRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Mail::fake();
    }

    private function authority(string $role, string $suffix): User
    {
        $user = User::factory()->create([
            'name' => ucfirst($role),
            'surname' => $suffix,
            'email' => strtolower($role).'-'.strtolower($suffix).'@test.local',
            'role' => $role,
            'status' => 'active',
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function unit(string $code): CoordinationUnit
    {
        return CoordinationUnit::query()->create([
            'code' => $code,
            'name' => str($code)->replace('_', ' ')->title().' Koordinatörlüğü',
            'kind' => CoordinationUnit::KIND_SERVICE,
            'status' => CoordinationUnit::STATUS_ACTIVE,
        ]);
    }

    private function membership(CoordinationUnit $unit, User $user, string $position, bool $primary = true): CoordinationUnitMembership
    {
        return CoordinationUnitMembership::query()->create([
            'unit_id' => $unit->id,
            'user_id' => $user->id,
            'position' => $position,
            'is_primary' => $primary,
            'status' => CoordinationUnitMembership::STATUS_ACTIVE,
        ]);
    }

    public function test_request_target_is_selected_from_active_unit_members_and_only_target_can_change_status(): void
    {
        $unit = $this->unit('service_media_test');
        $creator = $this->authority('coordinator', 'Creator');
        $target = $this->authority('staff', 'Target');
        $outsider = $this->authority('staff', 'Outsider');
        $targetMembership = $this->membership($unit, $target, CoordinationUnitMembership::POSITION_STAFF);

        Sanctum::actingAs($creator);
        $this->getJson('/api/panel/requests')
            ->assertOk()
            ->assertJsonPath('coordination_units.0.id', $unit->id)
            ->assertJsonPath('coordination_units.0.members.0.user_id', $target->id);

        $this->postJson('/api/panel/requests', [
            'type' => 'other',
            'target_unit_id' => $unit->id,
            'target_user_id' => $outsider->id,
            'description' => 'Bu talep birim disi kisiye gitmemelidir.',
        ])->assertUnprocessable();

        $requestId = $this->postJson('/api/panel/requests', [
            'type' => 'other',
            'target_unit_id' => $unit->id,
            'target_user_id' => $target->id,
            'description' => 'Bu talep secilen aktif birim personeline gider.',
        ])->assertCreated()
            ->assertJsonPath('request_item.target_unit_id', $unit->id)
            ->assertJsonPath('request_item.target_membership_id', $targetMembership->id)
            ->json('request_item.id');

        $this->putJson("/api/panel/requests/{$requestId}/status", ['status' => 'in_progress'])
            ->assertForbidden();

        Sanctum::actingAs($outsider);
        $this->putJson("/api/panel/requests/{$requestId}/status", ['status' => 'in_progress'])
            ->assertForbidden();

        Sanctum::actingAs($target);
        $this->putJson("/api/panel/requests/{$requestId}/status", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('request_item.can_update_status', true);

        $this->assertDatabaseHas('workflow_status_histories', [
            'subject_type' => WorkflowRequest::class,
            'subject_id' => $requestId,
            'from_status' => 'pending',
            'to_status' => 'in_progress',
            'changed_by' => $target->id,
            'unit_id' => $unit->id,
        ]);
    }

    public function test_leave_snapshot_routes_staff_to_original_unit_coordinator_after_transfer(): void
    {
        $oldUnit = $this->unit('project_old_test');
        $newUnit = $this->unit('project_new_test');
        $staff = $this->authority('staff', 'Leave');
        $oldCoordinator = $this->authority('coordinator', 'Old');
        $newCoordinator = $this->authority('coordinator', 'New');
        $staffMembership = $this->membership($oldUnit, $staff, CoordinationUnitMembership::POSITION_STAFF);
        $this->membership($oldUnit, $oldCoordinator, CoordinationUnitMembership::POSITION_COORDINATOR);
        $this->membership($newUnit, $newCoordinator, CoordinationUnitMembership::POSITION_COORDINATOR);

        Sanctum::actingAs($staff);
        $leaveId = $this->postJson('/api/leave-requests', [
            'unit_id' => $oldUnit->id,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'reason' => 'Üyelik snapshot yönlendirme testi',
        ])->assertCreated()
            ->assertJsonPath('leave_request.unit_id', $oldUnit->id)
            ->assertJsonPath('leave_request.membership_id', $staffMembership->id)
            ->assertJsonPath('leave_request.reviewer_scope', 'unit_coordinator')
            ->json('leave_request.id');

        $staffMembership->update(['status' => CoordinationUnitMembership::STATUS_PASSIVE, 'ends_at' => now()]);
        $this->membership($newUnit, $staff, CoordinationUnitMembership::POSITION_STAFF);

        Sanctum::actingAs($newCoordinator);
        $this->putJson("/api/panel/leave-requests/{$leaveId}/approve")->assertForbidden();

        Sanctum::actingAs($oldCoordinator);
        $this->putJson("/api/panel/leave-requests/{$leaveId}/approve")
            ->assertOk()
            ->assertJsonPath('leave_request.status', 'approved');

        $this->assertDatabaseHas('workflow_status_histories', [
            'subject_type' => LeaveRequest::class,
            'subject_id' => $leaveId,
            'to_status' => 'approved',
            'changed_by' => $oldCoordinator->id,
            'unit_id' => $oldUnit->id,
        ]);
    }

    public function test_coordinator_leave_is_routed_only_to_super_admin(): void
    {
        $unit = $this->unit('service_coordinator_leave');
        $coordinator = $this->authority('coordinator', 'LeaveOwner');
        $otherCoordinator = $this->authority('coordinator', 'Other');
        $superAdmin = $this->authority('super_admin', 'Reviewer');
        $this->membership($unit, $coordinator, CoordinationUnitMembership::POSITION_COORDINATOR);
        $this->membership($unit, $otherCoordinator, CoordinationUnitMembership::POSITION_COORDINATOR, false);

        Sanctum::actingAs($coordinator);
        $leaveId = $this->postJson('/api/leave-requests', [
            'unit_id' => $unit->id,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('leave_request.reviewer_scope', 'super_admin')
            ->json('leave_request.id');

        Sanctum::actingAs($otherCoordinator);
        $this->putJson("/api/panel/leave-requests/{$leaveId}/approve")->assertForbidden();

        Sanctum::actingAs($superAdmin);
        $this->putJson("/api/panel/leave-requests/{$leaveId}/approve")->assertOk();
    }

    public function test_only_assigned_unit_coordinator_can_edit_and_reopen_support_ticket(): void
    {
        $unit = $this->unit('service_support_test');
        $otherUnit = $this->unit('service_support_other');
        $coordinator = $this->authority('coordinator', 'Support');
        $outsider = $this->authority('coordinator', 'SupportOutsider');
        $assignee = $this->authority('staff', 'SupportAssignee');
        $this->membership($unit, $coordinator, CoordinationUnitMembership::POSITION_COORDINATOR);
        $this->membership($unit, $assignee, CoordinationUnitMembership::POSITION_STAFF);
        $this->membership($otherUnit, $outsider, CoordinationUnitMembership::POSITION_COORDINATOR);

        $ticket = SupportTicket::query()->create([
            'user_id' => $assignee->id,
            'name' => 'Ticket Owner',
            'email' => 'ticket-owner@test.local',
            'subject' => 'Eski konu',
            'message' => 'Eski destek mesaji',
            'category' => 'general',
            'assigned_to' => $assignee->id,
            'assigned_unit_id' => $unit->id,
            'status' => 'closed',
        ]);

        Sanctum::actingAs($outsider);
        $this->patchJson("/api/panel/support/tickets/{$ticket->id}", ['subject' => 'Yetkisiz'])
            ->assertForbidden();
        $this->putJson("/api/panel/support/tickets/{$ticket->id}/reopen")
            ->assertForbidden();

        Sanctum::actingAs($coordinator);
        $this->patchJson("/api/panel/support/tickets/{$ticket->id}", [
            'subject' => 'Guncel konu',
            'category' => 'technical',
            'message' => 'Koordinatör tarafindan guncellendi',
        ])->assertOk();
        $this->putJson("/api/panel/support/tickets/{$ticket->id}/reopen")
            ->assertOk()
            ->assertJsonPath('ticket.status', 'in_progress');

        $this->assertDatabaseHas('support_tickets', [
            'id' => $ticket->id,
            'subject' => 'Guncel konu',
            'status' => 'in_progress',
        ]);
        $this->assertDatabaseHas('workflow_status_histories', [
            'subject_type' => SupportTicket::class,
            'subject_id' => $ticket->id,
            'from_status' => 'closed',
            'to_status' => 'in_progress',
            'changed_by' => $coordinator->id,
            'unit_id' => $unit->id,
        ]);
    }
}
