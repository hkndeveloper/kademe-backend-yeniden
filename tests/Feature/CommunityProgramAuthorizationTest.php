<?php

namespace Tests\Feature;

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitMembership;
use App\Models\CoordinationUnitProjectResponsibility;
use App\Models\Participant;
use App\Models\Period;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use App\Services\CoordinationUnitBackfillService;
use App\Services\CoordinationUnitPermissionRuleSyncService;
use App\Services\PermissionResolver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommunityProgramAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Period $period;

    private CoordinationUnit $communityUnit;

    private CoordinationUnit $projectUnit;

    private User $communityCoordinator;

    private User $communityStaff;

    private User $projectStaff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->project = Project::query()->create([
            'name' => 'Community Scope Project',
            'slug' => 'community-scope-project',
            'type' => 'other',
            'status' => 'active',
            'application_open' => false,
            'next_application_date' => now()->addMonth()->toDateString(),
            'has_interview' => false,
        ]);
        $this->period = Period::query()->create([
            'project_id' => $this->project->id,
            'name' => '2026 Community',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        app(CoordinationUnitBackfillService::class)->execute(true);
        $this->communityUnit = CoordinationUnit::query()->where('code', 'service_community_culture')->firstOrFail();
        $this->projectUnit = CoordinationUnit::query()->where('project_id', $this->project->id)->firstOrFail();
        CoordinationUnitProjectResponsibility::query()->firstOrCreate([
            'unit_id' => $this->communityUnit->id,
            'project_id' => $this->project->id,
            'service_domain' => 'community_culture',
        ], [
            'is_primary' => true,
            'status' => CoordinationUnitProjectResponsibility::STATUS_ACTIVE,
        ]);
        app(CoordinationUnitPermissionRuleSyncService::class)->execute(true);

        $this->communityCoordinator = $this->authority('coordinator', 'Community Coordinator');
        $this->communityStaff = $this->authority('staff', 'Community Staff');
        $this->projectStaff = $this->authority('staff', 'Project Staff');
        $this->membership($this->communityUnit, $this->communityCoordinator, CoordinationUnitMembership::POSITION_COORDINATOR);
        $this->membership($this->communityUnit, $this->communityStaff, CoordinationUnitMembership::POSITION_STAFF);
        $this->membership($this->projectUnit, $this->projectStaff, CoordinationUnitMembership::POSITION_STAFF);

        config()->set('coordination_authorization.mode', 'enforce');
    }

    public function test_community_coordinator_manages_only_unit_owned_shared_events_with_minimized_fields(): void
    {
        $core = $this->program('Core Project Program', now()->addDay(), Program::KIND_CORE_PROGRAM);
        Sanctum::actingAs($this->communityCoordinator);

        $created = $this->postJson('/api/panel/programs/community-events', [
            'project_id' => $this->project->id,
            'period_id' => $this->period->id,
            'title' => 'Community Culture Day',
            'description' => 'Shared event',
            'location' => 'Main Hall',
            'start_at' => now()->addDays(2)->toIso8601String(),
            'end_at' => now()->addDays(2)->addHour()->toIso8601String(),
            'target_audience' => ['student', 'alumni'],
            'credit_deduction' => 99,
            'application_quota' => 3,
            'is_public' => true,
        ])->assertCreated()
            ->assertJsonPath('program.program_kind', Program::KIND_COMMUNITY_EVENT)
            ->assertJsonPath('program.managing_unit.id', $this->communityUnit->id)
            ->assertJsonMissingPath('program.credit_deduction')
            ->assertJsonMissingPath('program.application_quota')
            ->assertJsonPath('program.capabilities.update_community_event', true)
            ->json('program');

        $this->assertDatabaseHas('programs', [
            'id' => $created['id'],
            'program_kind' => Program::KIND_COMMUNITY_EVENT,
            'managing_unit_id' => $this->communityUnit->id,
            'credit_deduction' => 0,
            'application_quota' => null,
            'is_public' => false,
        ]);

        $this->getJson('/api/panel/programs?project_id='.$this->project->id)
            ->assertOk()
            ->assertJsonCount(1, 'programs')
            ->assertJsonPath('programs.0.id', $created['id']);
        $this->getJson('/api/panel/programs/'.$core->id)->assertForbidden();

        $this->putJson('/api/panel/programs/'.$created['id'].'/community-event', [
            'title' => 'Updated Community Culture Day',
            'credit_deduction' => 50,
        ])->assertOk();
        $this->assertDatabaseHas('programs', ['id' => $created['id'], 'title' => 'Updated Community Culture Day', 'credit_deduction' => 0]);
    }

    public function test_community_staff_takes_minimized_attendance_but_cannot_change_core_fields(): void
    {
        $event = $this->program('Community Attendance', now()->subMinutes(15), Program::KIND_COMMUNITY_EVENT, $this->communityUnit->id);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active', 'surname' => 'Attendance Student']);
        $participant = Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $this->project->id,
            'period_id' => $this->period->id,
            'status' => 'active',
            'credit' => 100,
        ]);
        Sanctum::actingAs($this->communityStaff);

        $resolver = app(PermissionResolver::class);
        $this->assertTrue($resolver->hasPermission($this->communityStaff, 'programs.community_event.view'));
        $this->assertTrue($resolver->hasPermission($this->communityStaff, 'programs.community_event.attendance.manage'));
        $this->assertTrue($resolver->hasPermission($this->communityStaff, 'programs.logistics.update'));
        $this->assertFalse($resolver->hasPermission($this->communityStaff, 'programs.community_event.create'));
        $this->assertFalse($resolver->hasPermission($this->communityStaff, 'programs.community_event.update'));

        $this->getJson('/api/panel/programs/'.$event->id)
            ->assertOk()
            ->assertJsonPath('program.capabilities.update_community_event', false)
            ->assertJsonPath('program.capabilities.update_logistics', true)
            ->assertJsonPath('program.capabilities.view_attendance', true)
            ->assertJsonPath('program.capabilities.manage_attendance', true)
            ->assertJsonPath('program.capabilities.view_media', true);
        $this->getJson('/api/panel/programs/'.$event->id.'/photos')->assertOk();

        $this->postJson('/api/panel/programs/community-events', [
            'project_id' => $this->project->id,
            'period_id' => $this->period->id,
            'title' => 'Staff Must Not Create',
            'start_at' => now()->addDays(2)->toIso8601String(),
            'end_at' => now()->addDays(2)->addHour()->toIso8601String(),
        ])->assertForbidden();
        $this->putJson('/api/panel/programs/'.$event->id.'/community-event', [
            'title' => 'Staff Must Not Update',
        ])->assertForbidden();

        $this->getJson('/api/panel/programs/'.$event->id.'/attendances')
            ->assertOk()
            ->assertJsonPath('records.0.participant_id', $participant->id)
            ->assertJsonMissingPath('records.0.email')
            ->assertJsonMissingPath('records.0.latitude')
            ->assertJsonMissingPath('records.0.credit_deducted')
            ->assertJsonMissingPath('summary.deduction_count');

        $this->putJson('/api/panel/programs/'.$event->id.'/attendances/'.$participant->id, [
            'is_valid' => true,
            'manual_note' => 'Imza listesi',
        ])->assertOk()->assertJsonPath('attendance.is_valid', true)->assertJsonMissingPath('attendance.recorded_by');

        $this->putJson('/api/panel/programs/'.$event->id, ['title' => 'Legacy core update'])->assertForbidden();
        $this->patchJson('/api/panel/programs/'.$event->id.'/logistics', [
            'location' => 'Culture Hall',
            'title' => 'Must be ignored',
        ])->assertOk();
        $this->assertDatabaseHas('programs', ['id' => $event->id, 'title' => 'Community Attendance', 'location' => 'Culture Hall']);
        $this->assertDatabaseMissing('programs', ['title' => 'Staff Must Not Create']);
    }

    public function test_project_staff_keeps_core_attendance_but_cannot_edit_community_event_as_core_program(): void
    {
        $core = $this->program('Core Attendance', now()->subMinutes(10), Program::KIND_CORE_PROGRAM);
        $event = $this->program('Unit Owned Event', now()->addDays(3), Program::KIND_COMMUNITY_EVENT, $this->communityUnit->id);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active', 'surname' => 'Project Student']);
        $participant = Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $this->project->id,
            'period_id' => $this->period->id,
            'status' => 'active',
            'credit' => 100,
        ]);
        Sanctum::actingAs($this->projectStaff);

        $this->getJson('/api/panel/programs/'.$core->id.'/attendances')
            ->assertOk()
            ->assertJsonPath('records.0.participant_id', $participant->id)
            ->assertJsonPath('records.0.email', $student->email);
        $this->putJson('/api/panel/programs/'.$core->id.'/attendances/'.$participant->id, ['is_valid' => true])->assertOk();

        $this->putJson('/api/panel/programs/'.$event->id, ['title' => 'Project unit overwrite'])->assertForbidden();
        $this->assertSame('Unit Owned Event', $event->fresh()->title);
    }

    public function test_calendar_returns_programs_and_minimal_period_context_without_period_permission(): void
    {
        $core = $this->program('Calendar Core Program', now()->addDay(), Program::KIND_CORE_PROGRAM);
        Sanctum::actingAs($this->communityStaff);

        $this->assertFalse(app(PermissionResolver::class)->hasPermission($this->communityStaff, 'periods.view'));
        $this->getJson('/api/panel/calendar/overview?project_id='.$this->project->id)
            ->assertOk()
            ->assertJsonPath('projects.0.periods.0.id', $this->period->id)
            ->assertJsonPath('projects.0.periods.0.project_id', $this->project->id)
            ->assertJsonPath('programs.0.id', $core->id)
            ->assertJsonPath('programs.0.event_type', 'program')
            ->assertJsonPath('programs.0.responsible_unit.id', $this->projectUnit->id)
            ->assertJsonMissingPath('programs.0.credit_deduction')
            ->assertJsonMissingPath('programs.0.application_quota');
    }

    private function authority(string $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'surname' => 'User', 'role' => $role, 'status' => 'active']);
        $user->assignRole($role);

        return $user;
    }

    private function membership(CoordinationUnit $unit, User $user, string $position): void
    {
        CoordinationUnitMembership::query()->create([
            'unit_id' => $unit->id,
            'user_id' => $user->id,
            'position' => $position,
            'is_primary' => true,
            'status' => CoordinationUnitMembership::STATUS_ACTIVE,
        ]);
    }

    private function program(string $title, \DateTimeInterface $start, string $kind, ?int $unitId = null): Program
    {
        return Program::query()->create([
            'project_id' => $this->project->id,
            'period_id' => $this->period->id,
            'program_kind' => $kind,
            'managing_unit_id' => $unitId,
            'title' => $title,
            'start_at' => $start,
            'end_at' => (clone $start)->modify('+1 hour'),
            'status' => 'active',
        ]);
    }
}
