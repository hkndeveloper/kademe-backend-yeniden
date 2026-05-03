<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Certificate;
use App\Models\Period;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PanelRegressionFixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function actingSuperAdmin(): User
    {
        $user = User::factory()->create([
            'name' => 'Panel',
            'surname' => 'Admin',
            'email' => 'panel-regression-admin@test.local',
            'role' => 'super_admin',
        ]);

        Role::findOrCreate('super_admin', 'web');
        $user->assignRole('super_admin');
        Sanctum::actingAs($user);

        return $user;
    }

    private function project(): Project
    {
        return Project::query()->create([
            'name' => 'Regression Project',
            'slug' => 'regression-project',
            'type' => 'other',
            'status' => 'active',
        ]);
    }

    public function test_panel_user_detail_does_not_query_missing_attendance_status_column(): void
    {
        $this->actingSuperAdmin();
        $student = User::factory()->create([
            'name' => 'Student',
            'surname' => 'Detail',
            'email' => 'student-detail@test.local',
            'role' => 'student',
        ]);
        $project = $this->project();
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $program = Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Attendance Program',
            'start_at' => now(),
            'end_at' => now()->addHour(),
            'status' => 'active',
        ]);
        Attendance::query()->create([
            'program_id' => $program->id,
            'user_id' => $student->id,
            'method' => 'qr',
            'is_valid' => false,
        ]);

        $this->getJson("/api/panel/users/{$student->id}")
            ->assertOk()
            ->assertJsonPath('absent_count', 1);
    }

    public function test_panel_certificate_create_accepts_canonical_type_and_persists_certificate_path(): void
    {
        $this->actingSuperAdmin();
        $student = User::factory()->create([
            'name' => 'Cert',
            'surname' => 'Owner',
            'email' => 'cert-owner@test.local',
            'role' => 'student',
        ]);
        $project = $this->project();

        $this->postJson('/api/panel/certificates', [
            'user_id' => $student->id,
            'project_id' => $project->id,
            'type' => 'participation',
            'certificate_path' => 'certificates/sample.pdf',
        ])->assertCreated();

        $this->assertDatabaseHas('certificates', [
            'user_id' => $student->id,
            'project_id' => $project->id,
            'type' => 'participation',
            'certificate_path' => 'certificates/sample.pdf',
        ]);
    }

    public function test_custom_role_creation_normalizes_turkish_display_name_and_can_be_assigned_as_primary_role(): void
    {
        $this->actingSuperAdmin();
        $staff = User::factory()->create([
            'name' => 'Social',
            'surname' => 'Media',
            'email' => 'social-media@test.local',
            'role' => 'staff',
        ]);

        $this->postJson('/api/panel/permissions-matrix/roles', [
            'name' => 'Sosyal Medya Koordinatörü',
            'permissions' => ['announcements.view'],
        ])
            ->assertCreated()
            ->assertJsonPath('role.name', 'sosyal_medya_koordinatoru');

        $this->putJson("/api/panel/permissions-matrix/users/{$staff->id}/roles", [
            'roles' => ['sosyal_medya_koordinatoru'],
            'primary_role' => 'sosyal_medya_koordinatoru',
        ])->assertOk();

        $this->assertTrue($staff->fresh()->hasRole('sosyal_medya_koordinatoru'));
    }
}
