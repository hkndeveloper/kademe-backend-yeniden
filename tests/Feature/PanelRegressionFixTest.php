<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Application;
use App\Models\ApplicationForm;
use App\Models\Badge;
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

        $rewardTierId = $this->postJson("/api/panel/projects/{$project->id}/special-modules/reward-tiers", [
            'name' => 'Kademe Plus 1',
            'min_badges' => 2,
            'min_credits' => 50,
            'reward_description' => 'Hediye Seti',
        ])->assertCreated()->json('reward_tier.id');

        $this->postJson("/api/panel/projects/{$project->id}/special-modules/reward-awards", [
            'participant_id' => $participant->id,
            'reward_tier_id' => $rewardTierId,
            'reward_name' => 'Hediye Seti',
            'status' => 'given',
        ])->assertCreated();

        $this->getJson("/api/panel/projects/{$project->id}/special-modules")
            ->assertOk()
            ->assertJsonCount(1, 'internships')
            ->assertJsonCount(1, 'mentors')
            ->assertJsonCount(1, 'eurodesk_projects')
            ->assertJsonCount(1, 'reward_tiers')
            ->assertJsonCount(1, 'reward_awards');
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

    public function test_student_dashboard_summary_filters_badges_by_kademe_plus_and_returns_monthly_titles(): void
    {
        $projectKademePlus = Project::query()->create([
            'name' => 'Kademe Plus',
            'slug' => 'kademe-plus',
            'type' => 'kademe_plus',
            'status' => 'active',
        ]);
        $projectOther = Project::query()->create([
            'name' => 'Diplomasi360',
            'slug' => 'diplomasi-360',
            'type' => 'diplomasi360',
            'status' => 'active',
        ]);

        $period = Period::query()->create([
            'project_id' => $projectKademePlus->id,
            'name' => '2026 Plus',
            'start_date' => now()->subWeek()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        $student = User::factory()->create([
            'name' => 'Summary',
            'surname' => 'Student',
            'role' => 'student',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');

        Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $projectKademePlus->id,
            'period_id' => $period->id,
            'status' => 'active',
            'credit' => 120,
        ]);

        $plusBadge = Badge::query()->create([
            'name' => 'Plus Rozeti',
            'project_id' => $projectKademePlus->id,
            'tier' => 'gold',
        ]);
        $otherBadge = Badge::query()->create([
            'name' => 'Diger Rozet',
            'project_id' => $projectOther->id,
            'tier' => 'silver',
        ]);
        $titleBadge = Badge::query()->create([
            'name' => 'Ayin Rozeti',
            'project_id' => $projectKademePlus->id,
            'tier' => 'platinum',
            'title_label' => 'Ayin Pergellisi',
        ]);

        $student->badges()->attach($plusBadge->id, ['project_id' => $projectKademePlus->id, 'awarded_at' => now()->subDay()]);
        $student->badges()->attach($otherBadge->id, ['project_id' => $projectOther->id, 'awarded_at' => now()->subDays(2)]);
        $student->badges()->attach($titleBadge->id, ['project_id' => $projectKademePlus->id, 'awarded_at' => now()]);

        Sanctum::actingAs($student);
        $response = $this->getJson('/api/dashboard/summary')
            ->assertOk()
            ->json();

        $badgeNames = collect($response['earned_badges'] ?? [])->pluck('name')->all();
        $this->assertContains('Plus Rozeti', $badgeNames);
        $this->assertContains('Ayin Rozeti', $badgeNames);
        $this->assertNotContains('Diger Rozet', $badgeNames);
        $this->assertContains('Ayin Pergellisi', $response['monthly_titles'] ?? []);
    }

    public function test_student_digital_cv_returns_approved_projects_badges_certificates_and_credits(): void
    {
        $project = Project::query()->create([
            'name' => 'Diplomasi360',
            'slug' => 'diplomasi-360-cv',
            'type' => 'diplomasi360',
            'short_description' => 'Diplomasi ve liderlik programi.',
            'status' => 'active',
        ]);

        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Bahar',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->toDateString(),
            'status' => 'completed',
        ]);

        $student = User::factory()->create([
            'name' => 'Digital',
            'surname' => 'Cv',
            'role' => 'student',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');

        $participant = Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'graduated',
            'graduation_status' => 'completed',
            'credit' => 95,
            'graduated_at' => now(),
        ]);

        $badge = Badge::query()->create([
            'name' => 'Liderlik Rozeti',
            'project_id' => $project->id,
            'tier' => 'gold',
        ]);
        $student->badges()->attach($badge->id, ['project_id' => $project->id, 'awarded_at' => now()]);

        Certificate::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'type' => 'participation',
            'verification_code' => 'CV-VERIFY-1',
            'issued_at' => now(),
        ]);

        CreditLog::query()->create([
            'participant_id' => $participant->id,
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'amount' => -5,
            'type' => 'deduction',
            'reason' => 'Yoklama puan kesintisi',
        ]);

        Sanctum::actingAs($student);

        $this->getJson('/api/dashboard/digital-cv')
            ->assertOk()
            ->assertJsonPath('approved.total_credit', 95)
            ->assertJsonPath('approved.completed_project_count', 1)
            ->assertJsonPath('projects.0.name', 'Diplomasi360')
            ->assertJsonPath('badges.0.name', 'Liderlik Rozeti')
            ->assertJsonPath('certificates.0.verification_code', 'CV-VERIFY-1')
            ->assertJsonPath('credit_history.0.reason', 'Yoklama puan kesintisi');
    }

    public function test_panel_user_status_update_accepts_legacy_inactive_alias_without_500(): void
    {
        $admin = $this->actingSuperAdmin();
        $student = User::factory()->create([
            'name' => 'Status',
            'surname' => 'User',
            'role' => 'student',
            'status' => 'active',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');

        Sanctum::actingAs($admin);

        $this->putJson("/api/panel/users/{$student->id}", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('user.status', 'passive');

        $this->assertDatabaseHas('users', [
            'id' => $student->id,
            'status' => 'passive',
        ]);
    }

    public function test_user_profile_update_accepts_social_handles_and_empty_optional_fields(): void
    {
        $student = User::factory()->create([
            'name' => 'Profile',
            'surname' => 'User',
            'role' => 'student',
            'status' => 'active',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');

        Sanctum::actingAs($student);

        $this->putJson('/api/user/profile', [
            'phone' => '',
            'hometown' => '',
            'university' => 'KADEME Universitesi',
            'department' => 'Liderlik',
            'class_year' => '',
            'motivation_message' => 'Kisa ozet',
            'linkedin_url' => 'linkedin.com/in/kademe',
            'github_url' => 'kademe-user',
            'instagram_url' => '',
        ])
            ->assertOk()
            ->assertJsonPath('user.profile.linkedin_url', 'linkedin.com/in/kademe')
            ->assertJsonPath('user.profile.github_url', 'kademe-user');
    }

    public function test_student_project_specials_returns_project_specific_modules(): void
    {
        $diplomasi = Project::query()->create([
            'name' => 'Diplomasi360',
            'slug' => 'diplomasi360',
            'type' => 'diplomasi360',
            'status' => 'active',
        ]);
        $kademePlus = Project::query()->create([
            'name' => 'Kademe Plus',
            'slug' => 'kademe-plus-specials',
            'type' => 'kademe_plus',
            'status' => 'active',
        ]);
        $periodOne = Period::query()->create([
            'project_id' => $diplomasi->id,
            'name' => '2026 Diplomasi',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $periodTwo = Period::query()->create([
            'project_id' => $kademePlus->id,
            'name' => '2026 Plus',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        $student = User::factory()->create([
            'name' => 'Special',
            'surname' => 'Student',
            'role' => 'student',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');

        $diplomasiParticipant = Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $diplomasi->id,
            'period_id' => $periodOne->id,
            'status' => 'active',
            'credit' => 80,
        ]);
        Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $kademePlus->id,
            'period_id' => $periodTwo->id,
            'status' => 'active',
            'credit' => 120,
        ]);

        \App\Models\Internship::query()->create([
            'participant_id' => $diplomasiParticipant->id,
            'company_name' => 'KADEME',
            'position' => 'Stajyer',
            'start_date' => now()->toDateString(),
        ]);

        $badge = Badge::query()->create([
            'name' => 'Plus Rozeti',
            'project_id' => $kademePlus->id,
            'tier' => 'gold',
        ]);
        $student->badges()->attach($badge->id, ['project_id' => $kademePlus->id, 'awarded_at' => now()]);

        \App\Models\RewardTier::query()->create([
            'project_id' => $kademePlus->id,
            'name' => 'Plus Hediye',
            'min_badges' => 1,
            'min_credits' => 100,
            'reward_description' => 'Hediye Seti',
        ]);

        Sanctum::actingAs($student);

        $response = $this->getJson('/api/dashboard/project-specials')
            ->assertOk()
            ->json();

        $projects = collect($response['projects'] ?? []);
        $diplomasiPayload = $projects->firstWhere('project.id', $diplomasi->id);
        $plusPayload = $projects->firstWhere('project.id', $kademePlus->id);

        $this->assertContains('internships', $diplomasiPayload['modules'] ?? []);
        $this->assertSame('KADEME', $diplomasiPayload['internships'][0]['company_name'] ?? null);
        $this->assertContains('reward_tiers', $plusPayload['modules'] ?? []);
        $this->assertTrue($plusPayload['reward_tiers'][0]['eligible'] ?? false);
    }

    public function test_student_can_apply_to_volunteer_opportunity_without_notification_failure_causing_500(): void
    {
        $project = $this->project();
        $student = User::factory()->create([
            'name' => 'Volunteer',
            'surname' => 'Student',
            'role' => 'student',
            'status' => 'active',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');

        $opportunity = \App\Models\VolunteerOpportunity::query()->create([
            'project_id' => $project->id,
            'title' => 'Gonullu Etkinlik',
            'description' => 'Gonullu destek gerektiren buyuk etkinlik.',
            'status' => 'open',
        ]);

        Sanctum::actingAs($student);

        $this->postJson("/api/volunteer/opportunities/{$opportunity->id}/apply", [
            'motivation_text' => 'Bu etkinlikte gonullu olarak aktif sorumluluk almak istiyorum.',
            'notes' => null,
        ])
            ->assertCreated()
            ->assertJsonPath('application.status', 'pending');

        $this->assertDatabaseHas('volunteer_applications', [
            'volunteer_opportunity_id' => $opportunity->id,
            'user_id' => $student->id,
            'status' => 'pending',
        ]);
    }
}
