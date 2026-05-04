<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Application;
use App\Models\ApplicationForm;
use App\Models\Certificate;
use App\Models\CreditLog;
use App\Models\Feedback;
use App\Models\Participant;
use App\Models\Period;
use App\Models\Program;
use App\Models\Project;
use App\Models\RolePermissionScope;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Storage;
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

    public function test_completing_program_deducts_credit_from_all_active_project_participants_once(): void
    {
        $this->actingSuperAdmin();
        $project = $this->project();
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Bahar',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $program = Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Credit Program',
            'start_at' => now()->subHours(2),
            'end_at' => now()->subHour(),
            'status' => 'active',
            'credit_deduction' => 10,
        ]);
        $attendingStudent = User::factory()->create(['surname' => 'Attending', 'role' => 'student', 'kvkk_consent_at' => now()]);
        $absentStudent = User::factory()->create(['surname' => 'Absent', 'role' => 'student', 'kvkk_consent_at' => now()]);
        $attendingParticipant = Participant::query()->create([
            'user_id' => $attendingStudent->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
            'credit' => 100,
        ]);
        $absentParticipant = Participant::query()->create([
            'user_id' => $absentStudent->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
            'credit' => 100,
        ]);
        Attendance::query()->create([
            'program_id' => $program->id,
            'user_id' => $attendingStudent->id,
            'method' => 'qr',
            'is_valid' => true,
        ]);

        $this->postJson("/api/panel/programs/{$program->id}/complete")
            ->assertOk()
            ->assertJsonPath('deducted_participant_count', 2);

        $this->assertSame(90, $attendingParticipant->fresh()->credit);
        $this->assertSame(90, $absentParticipant->fresh()->credit);
        $this->assertSame(2, CreditLog::query()->where('program_id', $program->id)->where('type', 'deduction')->count());

        $this->postJson("/api/panel/programs/{$program->id}/complete")
            ->assertOk()
            ->assertJsonPath('deducted_participant_count', 0);

        $this->assertSame(90, $attendingParticipant->fresh()->credit);
        $this->assertSame(90, $absentParticipant->fresh()->credit);
        $this->assertSame(2, CreditLog::query()->where('program_id', $program->id)->where('type', 'deduction')->count());
    }

    public function test_feedback_restores_only_attending_students_before_next_program(): void
    {
        $this->actingSuperAdmin();
        $project = $this->project();
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Yaz',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $program = Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Feedback Program',
            'start_at' => now()->subHours(2),
            'end_at' => now()->subHour(),
            'status' => 'active',
            'credit_deduction' => 10,
        ]);
        Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Next Program',
            'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addHour(),
            'status' => 'scheduled',
            'credit_deduction' => 10,
        ]);
        $student = User::factory()->create(['surname' => 'Feedback', 'role' => 'student', 'kvkk_consent_at' => now()]);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');
        $participant = Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
            'credit' => 100,
        ]);
        Attendance::query()->create([
            'program_id' => $program->id,
            'user_id' => $student->id,
            'method' => 'qr',
            'is_valid' => true,
        ]);

        $this->postJson("/api/panel/programs/{$program->id}/complete")->assertOk();
        $this->assertSame(90, $participant->fresh()->credit);

        Sanctum::actingAs($student);
        $this->postJson('/api/feedbacks', [
            'program_id' => $program->id,
            'responses' => [
                'content_quality' => 5,
                'speaker_quality' => 5,
                'organization_quality' => 5,
                'comment' => 'Faydaliydi.',
            ],
        ])->assertCreated();

        $this->assertSame(100, $participant->fresh()->credit);
        $this->assertSame(1, Feedback::query()->where('program_id', $program->id)->count());
        $this->assertSame(1, CreditLog::query()->where('program_id', $program->id)->where('type', 'restore')->count());
    }

    public function test_panel_applications_expose_dynamic_form_files_with_protected_download_url(): void
    {
        $this->actingSuperAdmin();
        Storage::fake(config('filesystems.media_disk', 'public'));

        $student = User::factory()->create([
            'surname' => 'Applicant',
            'role' => 'student',
        ]);
        $project = $this->project();
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Guz',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $form = ApplicationForm::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'fields' => [
                ['id' => 'motivation', 'type' => 'longtext', 'label' => 'Motivasyon', 'required' => true],
                ['id' => 'cv_file', 'type' => 'file', 'label' => 'CV Dosyasi', 'required' => true],
            ],
            'is_active' => true,
        ]);
        Storage::disk(config('filesystems.media_disk', 'public'))->put('application-files/sample.pdf', 'cv-content');

        $application = Application::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'application_form_id' => $form->id,
            'status' => 'pending',
            'form_data' => [
                'motivation' => 'Katılmak istiyorum.',
                'cv_file' => [
                    'path' => 'application-files/sample.pdf',
                    'original_name' => 'cv.pdf',
                    'mime_type' => 'application/pdf',
                    'size' => 10,
                ],
            ],
        ]);

        $this->getJson('/api/panel/applications?project_id=' . $project->id)
            ->assertOk()
            ->assertJsonPath('applications.data.0.form_entries.0.label', 'Motivasyon')
            ->assertJsonPath('applications.data.0.form_entries.1.file.original_name', 'cv.pdf')
            ->assertJsonPath('applications.data.0.form_entries.1.file.download_url', "/panel/applications/{$application->id}/form-files/cv_file");

        $this->get("/api/panel/applications/{$application->id}/form-files/cv_file")
            ->assertOk();
    }

    public function test_panel_participants_accepts_alumni_view_without_participant_view(): void
    {
        $project = $this->project();
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Alumni',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $activeStudent = User::factory()->create(['surname' => 'Active', 'role' => 'student']);
        $graduateStudent = User::factory()->create(['surname' => 'Graduate', 'role' => 'alumni']);
        Participant::query()->create([
            'user_id' => $activeStudent->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
            'credit' => 100,
        ]);
        Participant::query()->create([
            'user_id' => $graduateStudent->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'passive',
            'graduation_status' => 'graduated',
            'graduated_at' => now(),
            'credit' => 100,
        ]);

        $role = Role::findOrCreate('mezun_liste_sorumlusu', 'web');
        Permission::findOrCreate('projects.alumni.view', 'web');
        $role->givePermissionTo('projects.alumni.view');
        RolePermissionScope::query()->create([
            'role_name' => 'mezun_liste_sorumlusu',
            'permission_name' => 'projects.alumni.view',
            'scope_type' => 'selected_projects',
            'scope_payload' => ['project_ids' => [$project->id]],
        ]);

        $actor = User::factory()->create([
            'surname' => 'AlumniViewer',
            'role' => 'staff',
        ]);
        $actor->assignRole($role);
        Sanctum::actingAs($actor);

        $this->getJson('/api/panel/participants?project_id=' . $project->id)
            ->assertOk()
            ->assertJsonCount(1, 'participants')
            ->assertJsonPath('participants.0.graduation_status', 'graduated')
            ->assertJsonPath('summary.graduates', 1);
    }

    public function test_panel_project_special_modules_can_store_and_list_records_with_scope(): void
    {
        $this->actingSuperAdmin();
        $project = $this->project();
        $student = User::factory()->create(['surname' => 'Intern', 'role' => 'student']);
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Special',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $participant = Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
            'credit' => 100,
        ]);

        $this->postJson("/api/panel/projects/{$project->id}/special-modules/internships", [
            'participant_id' => $participant->id,
            'company_name' => 'KADEME',
            'position' => 'Stajyer',
            'start_date' => now()->toDateString(),
        ])->assertCreated();

        $this->postJson("/api/panel/projects/{$project->id}/special-modules/mentors", [
            'name' => 'Mentor Kisi',
            'expertise' => 'Diplomasi',
        ])->assertCreated();

        $this->postJson("/api/panel/projects/{$project->id}/special-modules/eurodesk-projects", [
            'title' => 'Genclik Hibesi',
            'partner_organizations' => ['Ortak Kurum'],
            'grant_amount' => 1000,
            'grant_status' => 'approved',
        ])->assertCreated();

        $this->postJson("/api/panel/projects/{$project->id}/special-modules/reward-tiers", [
            'name' => 'Kademe Plus 1',
            'min_badges' => 2,
            'min_credits' => 50,
            'reward_description' => 'Hediye Seti',
        ])->assertCreated();

        $this->getJson("/api/panel/projects/{$project->id}/special-modules")
            ->assertOk()
            ->assertJsonCount(1, 'internships')
            ->assertJsonCount(1, 'mentors')
            ->assertJsonCount(1, 'eurodesk_projects')
            ->assertJsonCount(1, 'reward_tiers');
    }

    public function test_panel_project_special_modules_accept_manage_scope_without_view_scope(): void
    {
        $project = $this->project();
        $role = Role::findOrCreate('mentor_manager_only', 'web');
        Permission::findOrCreate('projects.mentors.manage', 'web');
        $role->givePermissionTo('projects.mentors.manage');
        RolePermissionScope::query()->create([
            'role_name' => 'mentor_manager_only',
            'permission_name' => 'projects.mentors.manage',
            'scope_type' => 'selected_projects',
            'scope_payload' => ['project_ids' => [$project->id]],
        ]);

        $actor = User::factory()->create([
            'name' => 'Mentor',
            'surname' => 'Manager',
            'role' => 'staff',
        ]);
        $actor->assignRole($role);
        Sanctum::actingAs($actor);

        $modulesResponse = $this->getJson("/api/panel/projects/{$project->id}/modules")
            ->assertOk()
            ->json();

        $this->assertTrue($modulesResponse['access']['projects.mentors.manage'] ?? false);

        $specialModulesResponse = $this->getJson("/api/panel/projects/{$project->id}/special-modules")
            ->assertOk()
            ->json();

        $this->assertTrue($specialModulesResponse['access']['projects.mentors.manage'] ?? false);
        $this->assertFalse($specialModulesResponse['access']['projects.mentors.view'] ?? true);
    }
}
