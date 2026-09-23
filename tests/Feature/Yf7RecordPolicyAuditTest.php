<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Certificate;
use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitMembership;
use App\Models\LeaveRequest;
use App\Models\Participant;
use App\Models\Period;
use App\Models\Project;
use App\Models\Request as WorkflowRequest;
use App\Models\SupportTicket;
use App\Models\User;
use App\Policies\ApplicationPolicy;
use App\Policies\CertificatePolicy;
use App\Policies\ParticipantPolicy;
use App\Policies\ProjectPolicy;
use App\Services\CoordinationUnitBackfillService;
use App\Services\CoordinationUnitPermissionRuleSyncService;
use App\Services\PermissionResolver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class Yf7RecordPolicyAuditTest extends TestCase
{
    use RefreshDatabase;

    private Project $firstProject;

    private Project $secondProject;

    private CoordinationUnit $mediaUnit;

    private CoordinationUnit $purchaseUnit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->firstProject = $this->project('YF7 First', 'yf7-first', 'diplomasi360');
        $this->secondProject = $this->project('YF7 Second', 'yf7-second', 'pergel_fellowship');
        app(CoordinationUnitBackfillService::class)->execute(true);
        app(CoordinationUnitPermissionRuleSyncService::class)->execute(true);

        $this->mediaUnit = CoordinationUnit::query()->where('code', 'service_media')->firstOrFail();
        $this->purchaseUnit = CoordinationUnit::query()->where('code', 'service_purchase_organization')->firstOrFail();

        config()->set('coordination_authorization.mode', 'enforce');
        config()->set('coordination_authorization.active_unit_fallback', 'primary');
    }

    public function test_normalized_request_uses_only_the_selected_membership_across_aliases_and_records_forbidden_telemetry(): void
    {
        $actor = $this->authority('staff', 'RequestActor');
        $mediaMembership = $this->membership($this->mediaUnit, $actor, 'staff', true);
        $purchaseMembership = $this->membership($this->purchaseUnit, $actor, 'staff', false);
        $owner = $this->authority('coordinator', 'RequestOwner');

        $workflowRequest = WorkflowRequest::query()->create([
            'requester_id' => $owner->id,
            'type' => 'other',
            'target_unit' => $this->purchaseUnit->code,
            'target_unit_id' => $this->purchaseUnit->id,
            'target_user_id' => $actor->id,
            'target_membership_id' => $purchaseMembership->id,
            'description' => 'YF7 aktif birim kayit policy testi.',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($actor);
        $mediaHeaders = $this->unitHeaders($this->mediaUnit);
        foreach (['/api/admin/requests', '/api/panel/requests'] as $uri) {
            $ids = collect($this->withHeaders($mediaHeaders)->getJson($uri)->assertOk()->json('requests'))->pluck('id');
            $this->assertNotContains($workflowRequest->id, $ids);
        }

        foreach (['/api/admin/requests/', '/api/panel/requests/'] as $prefix) {
            $this->withHeaders($mediaHeaders)
                ->putJson($prefix.$workflowRequest->id.'/status', ['status' => 'in_progress'])
                ->assertForbidden();
        }

        $forbidden = Activity::query()
            ->where('causer_id', $actor->id)
            ->where('event', 'forbidden')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('checked_and_denied', $forbidden->properties->get('authorization_signal'));
        $this->assertSame($this->mediaUnit->id, (int) $forbidden->properties->get('acting_unit_id'));
        $this->assertSame($mediaMembership->id, (int) $forbidden->properties->get('acting_membership_id'));

        $purchaseHeaders = $this->unitHeaders($this->purchaseUnit);
        foreach (['/api/admin/requests', '/api/panel/requests'] as $uri) {
            $ids = collect($this->withHeaders($purchaseHeaders)->getJson($uri)->assertOk()->json('requests'))->pluck('id');
            $this->assertContains($workflowRequest->id, $ids);
        }

        $this->withHeaders($purchaseHeaders)
            ->putJson('/api/panel/requests/'.$workflowRequest->id.'/status', ['status' => 'in_progress'])
            ->assertOk();
    }

    public function test_normalized_support_list_mutation_and_reopen_cannot_use_another_membership(): void
    {
        $actor = $this->authority('coordinator', 'SupportActor');
        $this->membership($this->mediaUnit, $actor, 'coordinator', true);
        $this->membership($this->purchaseUnit, $actor, 'coordinator', false);
        $owner = $this->authority('staff', 'SupportOwner');

        $ticket = SupportTicket::query()->create([
            'user_id' => $owner->id,
            'name' => 'YF7 Owner',
            'email' => 'yf7-owner@test.local',
            'subject' => 'YF7 support',
            'message' => 'YF7 support body',
            'category' => 'general',
            'assigned_to' => $actor->id,
            'assigned_unit_id' => $this->purchaseUnit->id,
            'status' => 'closed',
        ]);

        Sanctum::actingAs($actor);
        $mediaHeaders = $this->unitHeaders($this->mediaUnit);
        foreach (['/api/admin/support/tickets', '/api/panel/support/tickets'] as $uri) {
            $ids = collect($this->withHeaders($mediaHeaders)->getJson($uri)->assertOk()->json('tickets.data'))->pluck('id');
            $this->assertNotContains($ticket->id, $ids);
        }
        $this->withHeaders($mediaHeaders)
            ->patchJson('/api/panel/support/tickets/'.$ticket->id, ['subject' => 'Denied'])
            ->assertForbidden();
        $this->withHeaders($mediaHeaders)
            ->putJson('/api/admin/support/tickets/'.$ticket->id.'/reopen')
            ->assertForbidden();

        $purchaseHeaders = $this->unitHeaders($this->purchaseUnit);
        foreach (['/api/admin/support/tickets', '/api/panel/support/tickets'] as $uri) {
            $ids = collect($this->withHeaders($purchaseHeaders)->getJson($uri)->assertOk()->json('tickets.data'))->pluck('id');
            $this->assertContains($ticket->id, $ids);
        }
        $this->withHeaders($purchaseHeaders)
            ->patchJson('/api/panel/support/tickets/'.$ticket->id, ['subject' => 'Allowed'])
            ->assertOk();
        $this->withHeaders($purchaseHeaders)
            ->putJson('/api/panel/support/tickets/'.$ticket->id.'/reopen')
            ->assertOk();
    }

    public function test_staff_and_leave_scope_uses_membership_and_ignores_conflicting_legacy_profile(): void
    {
        $actor = $this->authority('coordinator', 'PeopleActor');
        $this->membership($this->mediaUnit, $actor, 'coordinator', true);
        $this->membership($this->purchaseUnit, $actor, 'coordinator', false);
        $target = $this->authority('staff', 'PeopleTarget');
        $targetMembership = $this->membership($this->purchaseUnit, $target, 'staff', true);
        $target->staffProfile()->create([
            'title' => 'specialist',
            'unit' => $this->mediaUnit->name,
            'contract_type' => 'full_time',
            'start_date' => now()->toDateString(),
        ]);
        $leave = LeaveRequest::query()->create([
            'user_id' => $target->id,
            'unit_id' => $this->purchaseUnit->id,
            'membership_id' => $targetMembership->id,
            'position_snapshot' => 'staff',
            'reviewer_scope' => 'unit_coordinator',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'reason' => 'YF7 unit snapshot',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($actor);
        $mediaHeaders = $this->unitHeaders($this->mediaUnit);
        $mediaStaffIds = collect($this->withHeaders($mediaHeaders)
            ->getJson('/api/panel/staff')->assertOk()->json('staff.data'))->pluck('id');
        $this->assertNotContains($target->id, $mediaStaffIds);
        $this->withHeaders($mediaHeaders)->getJson('/api/panel/staff/'.$target->id)->assertForbidden();
        $this->withHeaders($mediaHeaders)
            ->putJson('/api/panel/leave-requests/'.$leave->id.'/approve')
            ->assertForbidden();

        $purchaseHeaders = $this->unitHeaders($this->purchaseUnit);
        $purchaseStaffIds = collect($this->withHeaders($purchaseHeaders)
            ->getJson('/api/panel/staff')->assertOk()->json('staff.data'))->pluck('id');
        $this->assertContains($target->id, $purchaseStaffIds);
        $this->withHeaders($purchaseHeaders)->getJson('/api/panel/staff/'.$target->id)->assertOk();
        $this->withHeaders($purchaseHeaders)
            ->putJson('/api/panel/leave-requests/'.$leave->id.'/approve')
            ->assertOk();
    }

    public function test_legacy_project_pivot_cannot_bypass_resolver_based_policies(): void
    {
        $actor = $this->authority('coordinator', 'PolicyActor');
        $firstUnit = CoordinationUnit::query()->where('project_id', $this->firstProject->id)->firstOrFail();
        $secondUnit = CoordinationUnit::query()->where('project_id', $this->secondProject->id)->firstOrFail();
        $this->membership($firstUnit, $actor, 'coordinator', true);
        $this->membership($secondUnit, $actor, 'coordinator', false);
        $this->secondProject->coordinators()->attach($actor->id);

        $participantUser = $this->authority('student', 'PolicyTarget');
        $firstPeriod = $this->period($this->firstProject, 'YF7 First Period');
        $secondPeriod = $this->period($this->secondProject, 'YF7 Second Period');
        $firstParticipant = Participant::query()->create([
            'user_id' => $participantUser->id,
            'project_id' => $this->firstProject->id,
            'period_id' => $firstPeriod->id,
            'status' => 'active',
            'credit' => 100,
        ]);
        $secondParticipant = Participant::query()->create([
            'user_id' => $participantUser->id,
            'project_id' => $this->secondProject->id,
            'period_id' => $secondPeriod->id,
            'status' => 'active',
            'credit' => 100,
        ]);
        $firstApplication = Application::query()->create([
            'user_id' => $participantUser->id,
            'project_id' => $this->firstProject->id,
            'period_id' => $firstPeriod->id,
            'form_data' => [],
            'status' => 'pending',
        ]);
        $secondApplication = Application::query()->create([
            'user_id' => $participantUser->id,
            'project_id' => $this->secondProject->id,
            'period_id' => $secondPeriod->id,
            'form_data' => [],
            'status' => 'pending',
        ]);
        $firstCertificate = $this->certificate($participantUser, $this->firstProject, $firstPeriod, 'FIRST-YF7');
        $secondCertificate = $this->certificate($participantUser, $this->secondProject, $secondPeriod, 'SECOND-YF7');

        $this->assertTrue(app(ProjectPolicy::class)->update($actor, $this->firstProject));
        $this->assertFalse(app(ProjectPolicy::class)->update($actor, $this->secondProject));
        $this->assertTrue(app(ParticipantPolicy::class)->view($actor, $firstParticipant));
        $this->assertFalse(app(ParticipantPolicy::class)->view($actor, $secondParticipant));
        $this->assertTrue(app(ApplicationPolicy::class)->view($actor, $firstApplication));
        $this->assertFalse(app(ApplicationPolicy::class)->view($actor, $secondApplication));
        $this->assertTrue(app(CertificatePolicy::class)->view($actor, $firstCertificate));
        $this->assertFalse(app(CertificatePolicy::class)->view($actor, $secondCertificate));
    }

    public function test_announcement_targeting_prefers_active_memberships_and_uses_profile_only_for_legacy_users(): void
    {
        $normalized = $this->authority('staff', 'NormalizedAnnouncementTarget');
        $normalized->staffProfile()->create([
            'title' => 'specialist',
            'unit' => $this->mediaUnit->name,
            'contract_type' => 'full_time',
            'start_date' => now()->toDateString(),
        ]);
        $this->membership($this->purchaseUnit, $normalized, 'staff', true);

        $legacy = $this->authority('staff', 'LegacyAnnouncementTarget');
        $legacy->staffProfile()->create([
            'title' => 'specialist',
            'unit' => $this->mediaUnit->name,
            'contract_type' => 'full_time',
            'start_date' => now()->toDateString(),
        ]);

        $resolver = app(PermissionResolver::class);

        $this->assertFalse($resolver->userHasActiveMembershipForTargetUnit($normalized, 'media'));
        $this->assertTrue($resolver->userHasActiveMembershipForTargetUnit($normalized, 'finance'));
        $this->assertTrue($resolver->userHasActiveMembershipForTargetUnit($legacy, 'media'));
        $this->assertFalse($resolver->userHasActiveMembershipForTargetUnit($legacy, 'finance'));
    }

    private function project(string $name, string $slug, string $type): Project
    {
        return Project::query()->create([
            'name' => $name,
            'slug' => $slug,
            'type' => $type,
            'status' => 'active',
        ]);
    }

    private function authority(string $role, string $suffix): User
    {
        $user = User::factory()->create([
            'name' => 'YF7',
            'surname' => $suffix,
            'email' => 'yf7-'.strtolower($suffix).'@test.local',
            'role' => $role,
            'status' => 'active',
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function membership(CoordinationUnit $unit, User $user, string $position, bool $primary): CoordinationUnitMembership
    {
        return CoordinationUnitMembership::query()->create([
            'unit_id' => $unit->id,
            'user_id' => $user->id,
            'position' => $position,
            'is_primary' => $primary,
            'status' => 'active',
        ]);
    }

    private function unitHeaders(CoordinationUnit $unit): array
    {
        return ['X-Coordination-Unit-Id' => (string) $unit->id];
    }

    private function period(Project $project, string $name): Period
    {
        return Period::query()->create([
            'project_id' => $project->id,
            'name' => $name,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
    }

    private function certificate(User $user, Project $project, Period $period, string $code): Certificate
    {
        return Certificate::query()->create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'type' => 'participation',
            'title' => 'YF7 Certificate',
            'issuer' => 'KADEME',
            'verification_code' => $code,
            'issued_at' => now(),
        ]);
    }
}
