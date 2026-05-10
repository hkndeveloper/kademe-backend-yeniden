<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Application;
use App\Models\ApplicationForm;
use App\Models\Badge;
use App\Models\Certificate;
use App\Models\CreditLog;
use App\Models\Feedback;
use App\Models\FinancialTransaction;
use App\Models\Participant;
use App\Models\KpdReport;
use App\Models\KpdRoom;
use App\Models\Period;
use App\Models\Program;
use App\Models\Project;
use App\Models\RolePermissionScope;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

    public function test_manageable_projects_accepts_financial_create_permission_for_scoped_coordinators(): void
    {
        $project = $this->project();
        $otherProject = Project::query()->create([
            'name' => 'Other Project',
            'slug' => 'other-project',
            'type' => 'other',
            'status' => 'active',
        ]);
        $coordinator = User::factory()->create([
            'surname' => 'FinanceScope',
            'role' => 'coordinator',
            'email' => 'finance-scope@test.local',
        ]);
        $project->coordinators()->attach($coordinator->id);

        $role = Role::findOrCreate('coordinator', 'web');
        $permission = Permission::findOrCreate('financial.create', 'web');
        $role->givePermissionTo($permission);
        RolePermissionScope::query()->updateOrCreate(
            ['role_name' => 'coordinator', 'permission_name' => 'financial.create'],
            ['scope_type' => 'own_projects', 'scope_payload' => []]
        );
        $coordinator->assignRole($role);
        Sanctum::actingAs($coordinator);

        $this->getJson('/api/panel/projects/manageable?permission=financial.create')
            ->assertOk()
            ->assertJsonCount(1, 'projects')
            ->assertJsonPath('projects.0.id', $project->id);

        $this->assertNotSame($otherProject->id, $project->id);
    }

    public function test_financial_invoice_download_streams_file_by_default_and_supports_explicit_direct_url(): void
    {
        $admin = $this->actingSuperAdmin();
        Storage::fake(config('filesystems.media_disk', 'public'));
        config([
            'filesystems.direct_media_downloads' => true,
            'filesystems.disks.' . config('filesystems.media_disk', 'public') . '.url' => 'https://cdn.example.test/media',
        ]);

        $project = $this->project();
        Storage::disk(config('filesystems.media_disk', 'public'))->put('invoices/test-invoice.pdf', '%PDF-1.4 test');
        $transaction = FinancialTransaction::query()->create([
            'project_id' => $project->id,
            'type' => 'expense',
            'category' => 'food',
            'payee_name' => 'Test Firma',
            'amount' => 1250,
            'status' => 'pending',
            'invoice_path' => 'invoices/test-invoice.pdf',
            'submitted_by' => $admin->id,
            'submitted_at' => now(),
        ]);

        $this->get("/api/panel/financials/{$transaction->id}/invoice")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->getJson("/api/panel/financials/{$transaction->id}/invoice?direct=1")
            ->assertOk()
            ->assertJsonPath('download_url', 'https://cdn.example.test/media/invoices/test-invoice.pdf');
    }

    public function test_financial_index_and_export_apply_full_filter_set_with_status_stats(): void
    {
        $admin = $this->actingSuperAdmin();
        $project = $this->project();
        $otherProject = Project::query()->create([
            'name' => 'Filtered Other Project',
            'slug' => 'filtered-other-project',
            'type' => 'other',
            'status' => 'active',
        ]);

        FinancialTransaction::query()->create([
            'project_id' => $project->id,
            'type' => 'expense',
            'category' => 'food',
            'payee_name' => 'Catering Firma',
            'amount' => 1000,
            'status' => 'approved',
            'submitted_by' => $admin->id,
            'submitted_at' => now(),
        ]);
        FinancialTransaction::query()->create([
            'project_id' => $project->id,
            'type' => 'payment',
            'category' => 'food',
            'payee_name' => 'Catering Firma',
            'amount' => 300,
            'status' => 'pending',
            'submitted_by' => $admin->id,
            'submitted_at' => now(),
        ]);
        FinancialTransaction::query()->create([
            'project_id' => $otherProject->id,
            'type' => 'expense',
            'category' => 'transport',
            'payee_name' => 'Ulasim Firma',
            'amount' => 700,
            'status' => 'approved',
            'submitted_by' => $admin->id,
            'submitted_at' => now(),
        ]);

        $query = http_build_query([
            'project_id' => $project->id,
            'status' => 'approved',
            'category' => 'food',
            'type' => 'expense',
            'payee' => 'Catering',
        ]);

        $this->getJson('/api/panel/financials?' . $query)
            ->assertOk()
            ->assertJsonCount(1, 'transactions.data')
            ->assertJsonPath('total_amount', 1000)
            ->assertJsonPath('category_stats.0.category', 'food')
            ->assertJsonPath('status_stats.0.status', 'approved')
            ->assertJsonPath('status_stats.0.count', 1);

        $this->get('/api/panel/financials/export?' . $query)
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_participant_graduation_status_updates_user_and_creates_certificate(): void
    {
        $this->actingSuperAdmin();
        $project = $this->project();
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Mezuniyet',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->toDateString(),
            'status' => 'completed',
        ]);
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'surname' => 'GraduateFlow',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');
        $participant = Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
            'credit' => 92,
        ]);

        $this->patchJson("/api/panel/participants/{$participant->id}/graduation", [
            'graduation_status' => 'graduated',
            'graduation_note' => 'Donemi basariyla tamamladi.',
        ])
            ->assertOk()
            ->assertJsonPath('participant.graduation_status', 'graduated')
            ->assertJsonPath('certificate.type', 'graduation');

        $this->assertDatabaseHas('participants', [
            'id' => $participant->id,
            'status' => 'graduated',
            'graduation_status' => 'graduated',
            'graduation_note' => 'Donemi basariyla tamamladi.',
        ]);
        $this->assertDatabaseHas('certificates', [
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'type' => 'graduation',
        ]);
        $this->assertSame('alumni', $student->fresh()->role);
        $this->assertSame('alumni', $student->fresh()->status);
        $this->assertTrue($student->fresh()->hasRole('alumni'));
    }

    public function test_participant_not_completed_requires_reason_and_does_not_create_certificate(): void
    {
        $this->actingSuperAdmin();
        $project = $this->project();
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Tamamlayamadi',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->toDateString(),
            'status' => 'completed',
        ]);
        $student = User::factory()->create(['role' => 'student', 'surname' => 'FailedFlow']);
        $participant = Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
            'credit' => 60,
        ]);

        $this->patchJson("/api/panel/participants/{$participant->id}/graduation", [
            'graduation_status' => 'not_completed',
        ])->assertStatus(422);

        $this->patchJson("/api/panel/participants/{$participant->id}/graduation", [
            'graduation_status' => 'not_completed',
            'graduation_note' => 'Devamsizlik sinirini asti.',
        ])
            ->assertOk()
            ->assertJsonPath('participant.status', 'failed');

        $this->assertDatabaseHas('participants', [
            'id' => $participant->id,
            'status' => 'failed',
            'graduation_status' => 'not_completed',
            'graduation_note' => 'Devamsizlik sinirini asti.',
        ]);
        $this->assertDatabaseMissing('certificates', [
            'user_id' => $student->id,
            'project_id' => $project->id,
        ]);
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

    public function test_manual_attendance_reconciles_completed_program_credit_without_duplicates(): void
    {
        $admin = $this->actingSuperAdmin();
        $project = $this->project();
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Manuel',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $program = Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Manual Credit Program',
            'start_at' => now()->subHours(2),
            'end_at' => now()->subHour(),
            'status' => 'active',
            'credit_deduction' => 10,
        ]);
        $student = User::factory()->create([
            'surname' => 'ManualCredit',
            'role' => 'student',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');
        $participant = Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
            'credit' => 100,
        ]);

        $this->postJson("/api/panel/programs/{$program->id}/complete")
            ->assertOk()
            ->assertJsonPath('deducted_participant_count', 1);
        $this->assertSame(90, $participant->fresh()->credit);

        $this->putJson("/api/panel/programs/{$program->id}/attendances/{$participant->id}", [
            'is_valid' => true,
            'manual_note' => 'Mazeret kabul edildi.',
        ])->assertOk();
        $this->assertSame(100, $participant->fresh()->credit);
        $this->assertSame(0, (int) CreditLog::query()
            ->where('participant_id', $participant->id)
            ->where('program_id', $program->id)
            ->sum('amount'));
        $this->assertSame(1, CreditLog::query()
            ->where('participant_id', $participant->id)
            ->where('program_id', $program->id)
            ->where('type', 'restore')
            ->where('amount', 10)
            ->count());

        $this->putJson("/api/panel/programs/{$program->id}/attendances/{$participant->id}", [
            'is_valid' => true,
        ])->assertOk();
        $this->assertSame(100, $participant->fresh()->credit);
        $this->assertSame(2, CreditLog::query()
            ->where('participant_id', $participant->id)
            ->where('program_id', $program->id)
            ->count());

        $this->putJson("/api/panel/programs/{$program->id}/attendances/{$participant->id}", [
            'is_valid' => false,
            'manual_note' => 'Gelmedi olarak duzeltildi.',
        ])->assertOk();
        $this->assertSame(90, $participant->fresh()->credit);
        $this->assertSame(-10, (int) CreditLog::query()
            ->where('participant_id', $participant->id)
            ->where('program_id', $program->id)
            ->sum('amount'));
        $this->assertDatabaseHas('credit_logs', [
            'participant_id' => $participant->id,
            'program_id' => $program->id,
            'type' => 'manual_adjust',
            'amount' => -10,
            'created_by' => $admin->id,
        ]);

        Sanctum::actingAs($student);
        $this->getJson('/api/programs')
            ->assertOk()
            ->assertJsonPath('programs.0.attendance_status', 'invalid')
            ->assertJsonPath('programs.0.credit.restored', false)
            ->assertJsonPath('programs.0.credit.net_amount', -10);
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

    public function test_qr_attendance_requires_previous_completed_program_feedback(): void
    {
        $project = $this->project();
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Feedback Guard',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $student = User::factory()->create([
            'role' => 'student',
            'surname' => 'FeedbackGuard',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');
        Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
            'credit' => 90,
        ]);
        $previousProgram = Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Onceki Oturum',
            'start_at' => now()->subDay(),
            'end_at' => now()->subDay()->addHour(),
            'status' => 'completed',
            'credit_deduction' => 10,
        ]);
        $currentProgram = Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Yeni Oturum',
            'start_at' => now()->subMinutes(10),
            'end_at' => now()->addHour(),
            'status' => 'active',
            'credit_deduction' => 10,
            'qr_token' => 'guard-token',
            'qr_expires_at' => now()->addMinutes(5),
        ]);
        Attendance::query()->create([
            'program_id' => $previousProgram->id,
            'user_id' => $student->id,
            'method' => 'qr',
            'is_valid' => true,
        ]);

        Sanctum::actingAs($student);

        $this->postJson('/api/attendances/qr', [
            'qr_token' => 'guard-token',
        ])
            ->assertStatus(423)
            ->assertJsonPath('requires_feedback', true)
            ->assertJsonPath('program_id', $previousProgram->id)
            ->assertJsonPath('redirect_to', '/student/feedback');

        $this->postJson('/api/feedbacks', [
            'program_id' => $previousProgram->id,
            'responses' => [
                'content_quality' => 5,
                'speaker_quality' => 5,
                'organization_quality' => 5,
                'comment' => 'Tamamlandi.',
            ],
        ])->assertCreated();

        $this->postJson('/api/attendances/qr', [
            'qr_token' => 'guard-token',
        ])->assertOk();

        $this->assertDatabaseHas('attendances', [
            'program_id' => $currentProgram->id,
            'user_id' => $student->id,
            'is_valid' => true,
        ]);
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

    public function test_panel_application_payload_exposes_allowed_workflow_statuses(): void
    {
        $this->actingSuperAdmin();

        $student = User::factory()->create([
            'role' => 'student',
            'surname' => 'Applicant',
            'kvkk_consent_at' => now(),
        ]);
        $project = $this->project();
        $project->update(['has_interview' => true]);
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Basvuru',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        Application::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'pending',
            'form_data' => [],
        ]);

        $this->getJson('/api/panel/applications?project_id=' . $project->id)
            ->assertOk()
            ->assertJsonPath('applications.data.0.available_statuses', ['rejected', 'waitlisted', 'interview_planned'])
            ->assertJsonPath('applications.data.0.workflow.next_step', 'plan_interview');
    }

    public function test_project_content_counts_accepted_applications_as_approved_summary(): void
    {
        $this->actingSuperAdmin();

        $student = User::factory()->create(['role' => 'student', 'surname' => 'Accepted']);
        $project = $this->project();
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Ozet',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        Application::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'accepted',
            'form_data' => [],
        ]);

        $this->getJson('/api/panel/projects/' . $project->id . '/modules')
            ->assertOk()
            ->assertJsonPath('summary.applications.approved', 1);
    }

    public function test_application_form_endpoint_respects_selected_period(): void
    {
        $this->actingSuperAdmin();

        $project = $this->project();
        $fall = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Guz',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $spring = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Bahar',
            'start_date' => now()->addMonths(2)->toDateString(),
            'end_date' => now()->addMonths(3)->toDateString(),
            'status' => 'active',
        ]);

        ApplicationForm::query()->create([
            'project_id' => $project->id,
            'period_id' => $fall->id,
            'fields' => [
                ['id' => 'fall_question', 'type' => 'text', 'label' => 'Guz sorusu', 'required' => true],
            ],
            'is_active' => true,
        ]);
        ApplicationForm::query()->create([
            'project_id' => $project->id,
            'period_id' => $spring->id,
            'fields' => [
                ['id' => 'spring_question', 'type' => 'text', 'label' => 'Bahar sorusu', 'required' => true],
            ],
            'is_active' => true,
        ]);

        $this->getJson('/api/panel/projects/' . $project->id . '/application-form?period_id=' . $fall->id)
            ->assertOk()
            ->assertJsonPath('application_form.period_id', $fall->id)
            ->assertJsonPath('application_form.fields.0.id', 'fall_question');
    }

    public function test_public_project_detail_uses_active_period_application_form(): void
    {
        $project = $this->project();
        $activePeriod = Period::query()->create([
            'project_id' => $project->id,
            'name' => 'Aktif Donem',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $passivePeriod = Period::query()->create([
            'project_id' => $project->id,
            'name' => 'Pasif Donem',
            'start_date' => now()->subMonths(2)->toDateString(),
            'end_date' => now()->subMonth()->toDateString(),
            'status' => 'passive',
        ]);

        $project->update(['application_open' => true]);
        ApplicationForm::query()->create([
            'project_id' => $project->id,
            'period_id' => $passivePeriod->id,
            'fields' => [
                ['id' => 'passive_question', 'type' => 'text', 'label' => 'Pasif soru', 'required' => true],
            ],
            'is_active' => true,
        ]);
        ApplicationForm::query()->create([
            'project_id' => $project->id,
            'period_id' => $activePeriod->id,
            'fields' => [
                ['id' => 'active_question', 'type' => 'text', 'label' => 'Aktif soru', 'required' => true],
            ],
            'is_active' => true,
        ]);

        $this->getJson('/api/projects/' . $project->slug)
            ->assertOk()
            ->assertJsonPath('application_form.period_id', $activePeriod->id)
            ->assertJsonPath('application_form.fields.0.id', 'active_question');
    }

    public function test_public_application_requires_configured_consent_before_submission(): void
    {
        $project = $this->project();
        $project->update(['application_open' => true]);
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Basvuru',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        ApplicationForm::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'fields' => [
                ['id' => 'motivation', 'type' => 'text', 'label' => 'Motivasyon', 'required' => false],
            ],
            'require_consent' => true,
            'consent_text' => 'Basvuru kosullarini okudum ve kabul ediyorum.',
            'is_active' => true,
        ]);

        $payload = [
            'project_id' => $project->id,
            'form_data' => [
                'motivation' => 'Katılmak istiyorum.',
            ],
            'applicant' => [
                'name' => 'Public',
                'surname' => 'Applicant',
                'email' => 'public-consent@test.local',
                'phone' => '05550000000',
            ],
        ];

        $this->postJson('/api/applications/public', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('consent_accepted');

        $this->assertDatabaseCount('applications', 0);

        $this->postJson('/api/applications/public', $payload + ['consent_accepted' => true])
            ->assertCreated()
            ->assertJsonPath('application.status', 'pending');

        $this->assertDatabaseHas('applications', [
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'pending',
        ]);
    }

    public function test_student_applications_include_interview_reason_and_submitted_answers(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'surname' => 'Applicant',
            'kvkk_consent_at' => now(),
        ]);
        Sanctum::actingAs($student);

        $project = $this->project();
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Basvuru',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $form = ApplicationForm::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'fields' => [
                ['id' => 'motivation', 'type' => 'longtext', 'label' => 'Motivasyon', 'required' => true],
            ],
            'is_active' => true,
        ]);

        Application::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'application_form_id' => $form->id,
            'status' => 'interview_planned',
            'interview_at' => now()->addDays(3),
            'form_data' => [
                'motivation' => 'Projeye katilmak istiyorum.',
            ],
        ]);

        $this->getJson('/api/applications')
            ->assertOk()
            ->assertJsonPath('applications.0.status', 'interview_planned')
            ->assertJsonPath('applications.0.form_entries.0.label', 'Motivasyon')
            ->assertJsonPath('applications.0.form_entries.0.value', 'Projeye katilmak istiyorum.');
    }

    public function test_accepting_application_creates_active_participant_and_converts_visitor_to_student(): void
    {
        $this->actingSuperAdmin();

        $visitor = User::factory()->create([
            'role' => 'visitor',
            'surname' => 'Visitor',
            'status' => 'passive',
        ]);
        $project = $this->project();
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Kabul',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
            'credit_start_amount' => 120,
        ]);
        $application = Application::query()->create([
            'user_id' => $visitor->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'pending',
            'form_data' => [],
        ]);

        $this->putJson('/api/panel/applications/' . $application->id . '/status', [
            'status' => 'accepted',
        ])->assertOk();

        $this->assertDatabaseHas('participants', [
            'user_id' => $visitor->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
            'credit' => 120,
        ]);
        $this->assertSame('student', $visitor->fresh()->role);
        $this->assertSame('active', $visitor->fresh()->status);
        $this->assertTrue($visitor->fresh()->hasRole('student'));
    }

    public function test_alumni_can_view_graduated_program_history(): void
    {
        $alumni = User::factory()->create([
            'role' => 'alumni',
            'surname' => 'Graduate',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('alumni', 'web');
        $alumni->assignRole('alumni');
        Sanctum::actingAs($alumni);

        $project = $this->project();
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Mezun',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->toDateString(),
            'status' => 'completed',
        ]);
        Participant::query()->create([
            'user_id' => $alumni->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'graduated',
            'graduation_status' => 'graduated',
            'graduated_at' => now()->subDay(),
            'credit' => 90,
        ]);
        $program = Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Mezun Program Gecmisi',
            'start_at' => now()->subDays(10),
            'end_at' => now()->subDays(10)->addHour(),
            'status' => 'completed',
        ]);

        $this->getJson('/api/programs')
            ->assertOk()
            ->assertJsonPath('programs.0.id', $program->id)
            ->assertJsonPath('programs.0.attendance_status', 'absent');
    }

    public function test_student_certificate_list_and_public_verify_are_connected(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'surname' => 'Certified',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');
        Sanctum::actingAs($student);

        $project = $this->project();
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Sertifika',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $certificate = Certificate::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'type' => 'participation',
            'verification_code' => 'CERT2026X',
            'issued_at' => now(),
            'certificate_path' => 'certificates/sample.pdf',
        ]);

        $this->getJson('/api/certificates')
            ->assertOk()
            ->assertJsonPath('certificates.0.id', $certificate->id)
            ->assertJsonPath('certificates.0.download_url', url('/api/certificates/CERT2026X/download'));

        $this->getJson('/api/certificates/verify/CERT2026X')
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('certificate.id', $certificate->id)
            ->assertJsonPath('recipient.surname', 'Certified');
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

    public function test_kpd_panel_reports_respect_project_scope_for_coordinators(): void
    {
        $kpdProject = Project::query()->create([
            'name' => 'Kariyer Psikolojik Danismanlik',
            'slug' => 'kpd-regression',
            'type' => 'kpd',
            'status' => 'active',
        ]);
        $otherProject = $this->project();
        $kpdPeriod = Period::query()->create([
            'project_id' => $kpdProject->id,
            'name' => 'KPD 2026',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $otherPeriod = Period::query()->create([
            'project_id' => $otherProject->id,
            'name' => 'Other 2026',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        $coordinator = User::factory()->create([
            'surname' => 'Coordinator',
            'role' => 'coordinator',
            'email' => 'kpd-coordinator@test.local',
        ]);
        $studentInScope = User::factory()->create(['surname' => 'InScope', 'role' => 'student', 'email' => 'kpd-student@test.local']);
        $studentOutOfScope = User::factory()->create(['surname' => 'OutScope', 'role' => 'student', 'email' => 'other-student@test.local']);

        $kpdProject->coordinators()->attach($coordinator->id);
        Participant::query()->create([
            'user_id' => $studentInScope->id,
            'project_id' => $kpdProject->id,
            'period_id' => $kpdPeriod->id,
            'status' => 'active',
            'credit' => 100,
        ]);
        Participant::query()->create([
            'user_id' => $studentOutOfScope->id,
            'project_id' => $otherProject->id,
            'period_id' => $otherPeriod->id,
            'status' => 'active',
            'credit' => 100,
        ]);

        KpdReport::query()->create([
            'user_id' => $studentInScope->id,
            'counselor_id' => $coordinator->id,
            'title' => 'Scope icindeki rapor',
            'file_path' => 'kpd-reports/in-scope.pdf',
        ]);
        KpdReport::query()->create([
            'user_id' => $studentOutOfScope->id,
            'counselor_id' => $coordinator->id,
            'title' => 'Scope disindaki rapor',
            'file_path' => 'kpd-reports/out-of-scope.pdf',
        ]);

        $role = Role::findOrCreate('coordinator', 'web');
        $permission = Permission::findOrCreate('kpd.reports.view', 'web');
        $role->givePermissionTo($permission);
        RolePermissionScope::query()->updateOrCreate(
            ['role_name' => 'coordinator', 'permission_name' => 'kpd.reports.view'],
            ['scope_type' => 'own_projects', 'scope_payload' => []]
        );
        $coordinator->assignRole($role);
        Sanctum::actingAs($coordinator);

        $this->getJson('/api/panel/kpd/reports')
            ->assertOk()
            ->assertJsonCount(1, 'reports.data')
            ->assertJsonPath('reports.data.0.title', 'Scope icindeki rapor');

        $this->getJson('/api/panel/kpd/options?permission=kpd.reports.create')
            ->assertOk()
            ->assertJsonCount(1, 'counselees')
            ->assertJsonPath('counselees.0.id', $studentInScope->id);
    }

    public function test_kpd_panel_rejects_report_upload_for_users_outside_project_scope(): void
    {
        Storage::fake('public');

        $kpdProject = Project::query()->create([
            'name' => 'Kariyer Psikolojik Danismanlik',
            'slug' => 'kpd-upload-regression',
            'type' => 'kpd',
            'status' => 'active',
        ]);
        $otherProject = $this->project();
        $otherPeriod = Period::query()->create([
            'project_id' => $otherProject->id,
            'name' => 'Other 2026',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        $coordinator = User::factory()->create([
            'surname' => 'Coordinator',
            'role' => 'coordinator',
            'email' => 'kpd-upload-coordinator@test.local',
        ]);
        $outsideStudent = User::factory()->create(['surname' => 'Outside', 'role' => 'student', 'email' => 'kpd-outside-upload@test.local']);

        $kpdProject->coordinators()->attach($coordinator->id);
        Participant::query()->create([
            'user_id' => $outsideStudent->id,
            'project_id' => $otherProject->id,
            'period_id' => $otherPeriod->id,
            'status' => 'active',
            'credit' => 100,
        ]);

        $role = Role::findOrCreate('coordinator', 'web');
        $permission = Permission::findOrCreate('kpd.reports.create', 'web');
        $role->givePermissionTo($permission);
        RolePermissionScope::query()->updateOrCreate(
            ['role_name' => 'coordinator', 'permission_name' => 'kpd.reports.create'],
            ['scope_type' => 'own_projects', 'scope_payload' => []]
        );
        $coordinator->assignRole($role);
        Sanctum::actingAs($coordinator);

        $this->getJson('/api/panel/kpd/options?permission=kpd.reports.create')
            ->assertOk()
            ->assertJsonCount(0, 'counselees');

        $this->postJson('/api/panel/kpd/reports', [
            'user_id' => $outsideStudent->id,
            'title' => 'Yetkisiz rapor',
            'file' => UploadedFile::fake()->create('report.pdf', 20, 'application/pdf'),
        ])->assertStatus(422);

        $this->assertDatabaseMissing('kpd_reports', [
            'user_id' => $outsideStudent->id,
            'title' => 'Yetkisiz rapor',
        ]);
    }

    public function test_kpd_panel_returns_scoped_counselees_and_creates_appointment(): void
    {
        $kpdProject = Project::query()->create([
            'name' => 'Kariyer Psikolojik Danismanlik',
            'slug' => 'kpd-appointment-regression',
            'type' => 'kpd',
            'status' => 'active',
        ]);
        $period = Period::query()->create([
            'project_id' => $kpdProject->id,
            'name' => 'KPD Randevu 2026',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $room = KpdRoom::query()->create([
            'name' => 'room_1',
            'description' => 'Test odasi',
        ]);
        $coordinator = User::factory()->create([
            'surname' => 'Coordinator',
            'role' => 'coordinator',
            'status' => 'active',
            'email' => 'kpd-appointment-coordinator@test.local',
        ]);
        $student = User::factory()->create([
            'surname' => 'Counselee',
            'role' => 'student',
            'email' => 'kpd-counselee@test.local',
        ]);

        $kpdProject->coordinators()->attach($coordinator->id);
        Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $kpdProject->id,
            'period_id' => $period->id,
            'status' => 'active',
            'credit' => 100,
        ]);

        $role = Role::findOrCreate('coordinator', 'web');
        foreach (['kpd.appointments.view', 'kpd.appointments.manage'] as $permissionName) {
            $permission = Permission::findOrCreate($permissionName, 'web');
            $role->givePermissionTo($permission);
            RolePermissionScope::query()->updateOrCreate(
                ['role_name' => 'coordinator', 'permission_name' => $permissionName],
                ['scope_type' => 'own_projects', 'scope_payload' => []]
            );
        }
        $coordinator->assignRole($role);
        Sanctum::actingAs($coordinator);

        $this->getJson('/api/panel/kpd/appointments')
            ->assertOk()
            ->assertJsonPath('counselees.0.id', $student->id)
            ->assertJsonPath('rooms.0.id', $room->id);

        $this->postJson('/api/panel/kpd/appointments', [
            'counselor_id' => $coordinator->id,
            'counselee_id' => $student->id,
            'room_id' => $room->id,
            'start_at' => now()->addDay()->setTime(10, 0)->toIso8601String(),
            'end_at' => now()->addDay()->setTime(11, 0)->toIso8601String(),
            'notes' => 'Ilk gorusme',
        ])
            ->assertCreated()
            ->assertJsonPath('appointment.counselee.id', $student->id)
            ->assertJsonPath('message', 'Randevu basariyla olusturuldu.');

        $this->assertDatabaseHas('kpd_appointments', [
            'counselor_id' => $coordinator->id,
            'counselee_id' => $student->id,
            'room_id' => $room->id,
            'status' => 'scheduled',
        ]);
    }

    public function test_kpd_panel_updates_appointment_status_with_project_scope(): void
    {
        $kpdProject = Project::query()->create([
            'name' => 'Kariyer Psikolojik Danismanlik',
            'slug' => 'kpd-status-regression',
            'type' => 'kpd',
            'status' => 'active',
        ]);
        $period = Period::query()->create([
            'project_id' => $kpdProject->id,
            'name' => 'KPD Status 2026',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $room = KpdRoom::query()->create([
            'name' => 'room_1',
            'description' => 'Status odasi',
        ]);
        $coordinator = User::factory()->create([
            'surname' => 'Coordinator',
            'role' => 'coordinator',
            'status' => 'active',
            'email' => 'kpd-status-coordinator@test.local',
        ]);
        $student = User::factory()->create([
            'surname' => 'Counselee',
            'role' => 'student',
            'email' => 'kpd-status-counselee@test.local',
        ]);

        $kpdProject->coordinators()->attach($coordinator->id);
        Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $kpdProject->id,
            'period_id' => $period->id,
            'status' => 'active',
            'credit' => 100,
        ]);

        $appointment = \App\Models\KpdAppointment::query()->create([
            'counselor_id' => $coordinator->id,
            'counselee_id' => $student->id,
            'room_id' => $room->id,
            'start_at' => now()->addDay()->setTime(10, 0),
            'end_at' => now()->addDay()->setTime(11, 0),
            'status' => 'scheduled',
        ]);

        $role = Role::findOrCreate('coordinator', 'web');
        $permission = Permission::findOrCreate('kpd.appointments.manage', 'web');
        $role->givePermissionTo($permission);
        RolePermissionScope::query()->updateOrCreate(
            ['role_name' => 'coordinator', 'permission_name' => 'kpd.appointments.manage'],
            ['scope_type' => 'own_projects', 'scope_payload' => []]
        );
        $coordinator->assignRole($role);
        Sanctum::actingAs($coordinator);

        $this->putJson("/api/panel/kpd/appointments/{$appointment->id}/status", [
            'status' => 'completed',
        ])
            ->assertOk()
            ->assertJsonPath('appointment.status', 'completed');

        $this->assertDatabaseHas('kpd_appointments', [
            'id' => $appointment->id,
            'status' => 'completed',
        ]);
    }
}
