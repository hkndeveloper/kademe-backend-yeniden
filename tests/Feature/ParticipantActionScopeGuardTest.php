<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Participant;
use App\Models\Period;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ParticipantActionScopeGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_participant_override_does_not_open_endpoint_outside_student_alumni_domain(): void
    {
        Permission::findOrCreate('participant.programs.view', 'web');
        $user = User::factory()->create([
            'role' => 'visitor',
            'surname' => 'Override',
            'email' => 'participant-allow@test.local',
            'kvkk_consent_at' => now(),
        ]);

        UserPermissionOverride::query()->create([
            'user_id' => $user->id,
            'permission_name' => 'participant.programs.view',
            'effect' => 'allow',
            'scope_type' => 'self',
            'scope_payload' => ['user_id' => $user->id],
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/programs')->assertForbidden();
    }

    public function test_user_deny_override_blocks_default_student_participant_endpoint(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'surname' => 'Denied',
            'email' => 'participant-deny@test.local',
            'kvkk_consent_at' => now(),
        ]);
        $student->assignRole('student');

        UserPermissionOverride::query()->create([
            'user_id' => $student->id,
            'permission_name' => 'participant.programs.view',
            'effect' => 'deny',
            'scope_type' => null,
            'scope_payload' => [],
        ]);

        Sanctum::actingAs($student);

        $this->getJson('/api/programs')->assertForbidden();
    }

    public function test_role_action_without_usable_scope_cannot_open_participant_endpoint(): void
    {
        Permission::findOrCreate('participant.programs.view', 'web');
        $role = Role::findOrCreate('participant_programs_no_scope', 'web');
        $role->givePermissionTo('participant.programs.view');

        $user = User::factory()->create([
            'role' => 'visitor',
            'surname' => 'NoScope',
            'email' => 'participant-no-scope@test.local',
            'kvkk_consent_at' => now(),
        ]);
        $user->assignRole($role);

        Sanctum::actingAs($user);

        $this->getJson('/api/programs')->assertForbidden();
    }

    public function test_student_user_allow_override_can_restore_participant_endpoint(): void
    {
        Permission::findOrCreate('participant.programs.view', 'web');
        $user = User::factory()->create([
            'role' => 'student',
            'surname' => 'Scoped',
            'email' => 'participant-scoped@test.local',
            'kvkk_consent_at' => now(),
        ]);
        $user->assignRole('student');
        UserPermissionOverride::query()->create([
            'user_id' => $user->id,
            'permission_name' => 'participant.programs.view',
            'effect' => 'allow',
            'scope_type' => 'self',
            'scope_payload' => ['user_id' => $user->id],
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/programs')
            ->assertOk()
            ->assertJsonStructure(['programs']);
    }

    public function test_student_program_detail_uses_participant_scope_and_returns_derived_state(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'surname' => 'ProgramDetail',
            'email' => 'program-detail@test.local',
            'kvkk_consent_at' => now(),
        ]);
        $student->assignRole('student');
        $project = Project::query()->create([
            'name' => 'Detay Projesi',
            'slug' => 'detay-projesi',
            'type' => 'other',
            'status' => 'active',
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Detay',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
            'credit' => 90,
        ]);
        $program = Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Detayli Program',
            'description' => 'Program detay aciklamasi',
            'location' => 'Istanbul',
            'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addHour(),
            'status' => 'scheduled',
            'target_audience' => ['student'],
        ]);
        Attendance::query()->create([
            'program_id' => $program->id,
            'user_id' => $student->id,
            'method' => 'manual',
            'is_valid' => true,
        ]);

        Sanctum::actingAs($student);

        $this->getJson("/api/programs/{$program->id}")
            ->assertOk()
            ->assertJsonPath('program.id', $program->id)
            ->assertJsonPath('program.project.id', $project->id)
            ->assertJsonPath('program.period.id', $period->id)
            ->assertJsonPath('program.participation.credit', 90)
            ->assertJsonPath('program.attendance_status', 'present')
            ->assertJsonPath('program.attendance.method', 'manual')
            ->assertJsonPath('program.credit.net_amount', 0)
            ->assertJsonPath('program.feedback_submitted', false);

        $unavailableProgram = Program::query()->create([
            'project_id' => $project->id,
            'period_id' => null,
            'title' => 'Kapsam Disi Program',
            'start_at' => now()->addDays(2),
            'end_at' => now()->addDays(2)->addHour(),
            'status' => 'scheduled',
            'target_audience' => ['student'],
        ]);

        $this->getJson("/api/programs/{$unavailableProgram->id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'Bu etkinligi goruntuleme yetkiniz bulunmuyor.');
    }
}
