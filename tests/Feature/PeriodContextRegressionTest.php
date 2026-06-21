<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Announcement;
use App\Models\Attendance;
use App\Models\Certificate;
use App\Models\DigitalBohca;
use App\Models\Feedback;
use App\Models\FinancialTransaction;
use App\Models\KpdAppointment;
use App\Models\KpdRoom;
use App\Models\Participant;
use App\Models\Period;
use App\Models\PeriodArchive;
use App\Models\Program;
use App\Models\Project;
use App\Models\RolePermissionScope;
use App\Models\Request as WorkflowRequest;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\VolunteerOpportunity;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PeriodContextRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_panel_assignments_can_filter_completed_period_with_context_guard(): void
    {
        $actor = $this->actorWithAllScope('assignments.view', 'assignment_period_viewer');
        $project = $this->project('assignment-project');
        $otherProject = $this->project('other-assignment-project');
        [$activePeriod, $completedPeriod] = $this->periodsFor($project);
        $otherPeriod = Period::query()->create([
            'project_id' => $otherProject->id,
            'name' => 'Other Active',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        Assignment::query()->create([
            'project_id' => $project->id,
            'period_id' => $activePeriod->id,
            'title' => 'Aktif Donem Odevi',
            'created_by' => $actor->id,
        ]);
        Assignment::query()->create([
            'project_id' => $project->id,
            'period_id' => $completedPeriod->id,
            'title' => 'Arsiv Donem Odevi',
            'created_by' => $actor->id,
        ]);

        $this->getJson("/api/panel/assignments?project_id={$project->id}&period_id={$completedPeriod->id}")
            ->assertOk()
            ->assertJsonPath('assignments.data.0.title', 'Arsiv Donem Odevi');

        $this->getJson("/api/panel/assignments?project_id={$project->id}&period_id={$otherPeriod->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period_id');
    }

    public function test_panel_applications_can_filter_completed_period_with_context_guard(): void
    {
        $this->actorWithAllScope('applications.view', 'application_period_viewer');
        $project = $this->project('application-project');
        $otherProject = $this->project('other-application-project');
        [$activePeriod, $completedPeriod] = $this->periodsFor($project);
        $otherPeriod = Period::query()->create([
            'project_id' => $otherProject->id,
            'name' => 'Other Active',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $activeUser = User::factory()->create(['name' => 'Aktif', 'surname' => 'Aday']);
        $archiveUser = User::factory()->create(['name' => 'Arsiv', 'surname' => 'Aday']);

        Application::query()->create([
            'user_id' => $activeUser->id,
            'project_id' => $project->id,
            'period_id' => $activePeriod->id,
            'status' => 'pending',
            'form_data' => [],
        ]);
        Application::query()->create([
            'user_id' => $archiveUser->id,
            'project_id' => $project->id,
            'period_id' => $completedPeriod->id,
            'status' => 'pending',
            'form_data' => [],
        ]);

        $this->getJson("/api/panel/applications?project_id={$project->id}&period_id={$completedPeriod->id}")
            ->assertOk()
            ->assertJsonPath('applications.data.0.user.name', 'Arsiv');

        $this->getJson("/api/panel/applications?project_id={$project->id}&period_id={$otherPeriod->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period_id');
    }

    public function test_panel_digital_bohca_includes_global_materials_for_completed_period(): void
    {
        $actor = $this->actorWithAllScope('digital_bohca.view', 'bohca_period_viewer');
        $project = $this->project('bohca-project');
        $otherProject = $this->project('other-bohca-project');
        [$activePeriod, $completedPeriod] = $this->periodsFor($project);
        $otherPeriod = Period::query()->create([
            'project_id' => $otherProject->id,
            'name' => 'Other Active',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        DigitalBohca::query()->create([
            'project_id' => $project->id,
            'period_id' => null,
            'title' => 'Genel Proje Materyali',
            'file_path' => 'digital-bohca/general.pdf',
            'file_type' => 'pdf',
            'category' => 'general',
            'visible_to_student' => true,
            'uploaded_by' => $actor->id,
        ]);
        DigitalBohca::query()->create([
            'project_id' => $project->id,
            'period_id' => $activePeriod->id,
            'title' => 'Aktif Donem Materyali',
            'file_path' => 'digital-bohca/active.pdf',
            'file_type' => 'pdf',
            'category' => 'general',
            'visible_to_student' => true,
            'uploaded_by' => $actor->id,
        ]);
        DigitalBohca::query()->create([
            'project_id' => $project->id,
            'period_id' => $completedPeriod->id,
            'title' => 'Arsiv Donem Materyali',
            'file_path' => 'digital-bohca/archive.pdf',
            'file_type' => 'pdf',
            'category' => 'general',
            'visible_to_student' => true,
            'uploaded_by' => $actor->id,
        ]);

        $response = $this->getJson("/api/panel/digital-bohca?project_id={$project->id}&period_id={$completedPeriod->id}")
            ->assertOk();

        $titles = collect($response->json('materials.data'))->pluck('title')->all();
        $this->assertContains('Genel Proje Materyali', $titles);
        $this->assertContains('Arsiv Donem Materyali', $titles);
        $this->assertNotContains('Aktif Donem Materyali', $titles);

        $this->getJson("/api/panel/digital-bohca?project_id={$project->id}&period_id={$otherPeriod->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period_id');
    }

    public function test_panel_certificates_can_filter_completed_period_with_context_guard(): void
    {
        $actor = $this->actorWithAllScope('certificates.view', 'certificate_period_viewer');
        $project = $this->project('certificate-project');
        $otherProject = $this->project('other-certificate-project');
        [$activePeriod, $completedPeriod] = $this->periodsFor($project);
        $otherPeriod = Period::query()->create([
            'project_id' => $otherProject->id,
            'name' => 'Other Active',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $activeUser = User::factory()->create(['name' => 'Aktif', 'surname' => 'Sertifika']);
        $archiveUser = User::factory()->create(['name' => 'Arsiv', 'surname' => 'Sertifika']);

        Certificate::query()->create([
            'user_id' => $activeUser->id,
            'project_id' => $project->id,
            'period_id' => $activePeriod->id,
            'type' => 'participation',
            'verification_code' => 'ACTIVECERT1',
            'issued_at' => now(),
            'created_by' => $actor->id,
        ]);
        Certificate::query()->create([
            'user_id' => $archiveUser->id,
            'project_id' => $project->id,
            'period_id' => $completedPeriod->id,
            'type' => 'graduation',
            'verification_code' => 'ARCHIVECERT1',
            'issued_at' => now()->subMonth(),
            'created_by' => $actor->id,
        ]);

        $this->getJson("/api/panel/certificates?project_id={$project->id}&period_id={$completedPeriod->id}")
            ->assertOk()
            ->assertJsonPath('certificates.data.0.verification_code', 'ARCHIVECERT1');

        $this->getJson("/api/panel/certificates?project_id={$project->id}&period_id={$otherPeriod->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period_id');
    }

    public function test_panel_announcements_can_filter_completed_period_with_context_guard(): void
    {
        $actor = $this->actorWithAllScope('announcements.view', 'announcement_period_viewer');
        $project = $this->project('announcement-project');
        $otherProject = $this->project('other-announcement-project');
        [$activePeriod, $completedPeriod] = $this->periodsFor($project);
        $otherPeriod = Period::query()->create([
            'project_id' => $otherProject->id,
            'name' => 'Other Active',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        Announcement::query()->create([
            'project_id' => $project->id,
            'period_id' => $activePeriod->id,
            'title' => 'Aktif Donem Duyurusu',
            'content' => 'Aktif donem duyuru icerigi.',
            'category' => 'Genel',
            'target_roles' => ['student'],
            'created_by' => $actor->id,
            'published_at' => now(),
        ]);
        Announcement::query()->create([
            'project_id' => $project->id,
            'period_id' => $completedPeriod->id,
            'title' => 'Arsiv Donem Duyurusu',
            'content' => 'Arsiv donem duyuru icerigi.',
            'category' => 'Genel',
            'target_roles' => ['student'],
            'created_by' => $actor->id,
            'published_at' => now()->subMonth(),
        ]);

        $this->getJson("/api/panel/announcements?project_id={$project->id}&period_id={$completedPeriod->id}")
            ->assertOk()
            ->assertJsonPath('announcements.data.0.title', 'Arsiv Donem Duyurusu')
            ->assertJsonPath('announcements.data.0.period.name', 'Completed Period');

        $this->getJson("/api/panel/announcements?project_id={$project->id}&period_id={$otherPeriod->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period_id');
    }

    public function test_completed_period_blocks_announcement_create_without_archive_permission(): void
    {
        $this->actorWithAllScope('announcements.create', 'announcement_creator_without_archive');
        $project = $this->project('archive-announcement-project');
        [, $completedPeriod] = $this->periodsFor($project);

        $this->postJson('/api/panel/announcements', [
            'project_id' => $project->id,
            'period_id' => $completedPeriod->id,
            'title' => 'Arsiv Duyurusu',
            'content' => 'Tamamlanmis donemde normal yetkiyle yazilamamali.',
            'category' => 'Genel',
            'target_roles' => ['student'],
            'send_sms' => false,
            'send_email' => false,
        ])
            ->assertStatus(423)
            ->assertJsonPath('message', 'Tamamlanmis donem arsiv modundadir. Degisiklik icin arsiv duzeltme yetkisi gerekir.');
    }

    public function test_panel_financials_can_filter_completed_period_with_context_guard(): void
    {
        $actor = $this->actorWithAllScope('financial.view', 'financial_period_viewer');
        $project = $this->project('financial-project');
        $otherProject = $this->project('other-financial-project');
        [$activePeriod, $completedPeriod] = $this->periodsFor($project);
        $otherPeriod = Period::query()->create([
            'project_id' => $otherProject->id,
            'name' => 'Other Active',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        FinancialTransaction::query()->create([
            'project_id' => $project->id,
            'period_id' => $activePeriod->id,
            'type' => 'expense',
            'category' => 'education',
            'payee_name' => 'Aktif Tedarikci',
            'amount' => 1250,
            'status' => 'pending',
            'submitted_by' => $actor->id,
            'submitted_at' => now(),
        ]);
        FinancialTransaction::query()->create([
            'project_id' => $project->id,
            'period_id' => $completedPeriod->id,
            'type' => 'expense',
            'category' => 'other',
            'payee_name' => 'Arsiv Tedarikci',
            'amount' => 750,
            'status' => 'approved',
            'submitted_by' => $actor->id,
            'submitted_at' => now()->subMonth(),
        ]);

        $this->getJson("/api/panel/financials?project_id={$project->id}&period_id={$completedPeriod->id}")
            ->assertOk()
            ->assertJsonPath('transactions.data.0.payee_name', 'Arsiv Tedarikci')
            ->assertJsonPath('total_amount', 750);

        $this->getJson("/api/panel/financials?project_id={$project->id}&period_id={$otherPeriod->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period_id');
    }

    public function test_panel_volunteer_includes_general_opportunities_for_completed_period(): void
    {
        $actor = $this->actorWithAllScope('volunteer.view', 'volunteer_period_viewer');
        $project = $this->project('volunteer-project');
        $otherProject = $this->project('other-volunteer-project');
        [$activePeriod, $completedPeriod] = $this->periodsFor($project);
        $otherPeriod = Period::query()->create([
            'project_id' => $otherProject->id,
            'name' => 'Other Active',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        VolunteerOpportunity::query()->create([
            'project_id' => $project->id,
            'period_id' => null,
            'title' => 'Genel Gonulluluk',
            'description' => 'Genel proje gonulluluk ilani.',
            'status' => 'open',
            'created_by' => $actor->id,
        ]);
        VolunteerOpportunity::query()->create([
            'project_id' => $project->id,
            'period_id' => $activePeriod->id,
            'title' => 'Aktif Donem Gonulluluk',
            'description' => 'Aktif donem gonulluluk ilani.',
            'status' => 'open',
            'created_by' => $actor->id,
        ]);
        VolunteerOpportunity::query()->create([
            'project_id' => $project->id,
            'period_id' => $completedPeriod->id,
            'title' => 'Arsiv Donem Gonulluluk',
            'description' => 'Arsiv donem gonulluluk ilani.',
            'status' => 'open',
            'created_by' => $actor->id,
        ]);

        $response = $this->getJson("/api/panel/volunteer/opportunities?project_id={$project->id}&period_id={$completedPeriod->id}")
            ->assertOk();

        $titles = collect($response->json('opportunities.data'))->pluck('title')->all();
        $this->assertContains('Genel Gonulluluk', $titles);
        $this->assertContains('Arsiv Donem Gonulluluk', $titles);
        $this->assertNotContains('Aktif Donem Gonulluluk', $titles);

        $this->getJson("/api/panel/volunteer/opportunities?project_id={$project->id}&period_id={$otherPeriod->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period_id');
    }

    public function test_panel_support_tickets_can_filter_completed_period_with_context_guard(): void
    {
        $requester = User::factory()->create(['name' => 'Destek', 'surname' => 'Sahibi']);
        $this->actorWithAllScope('support.view', 'support_period_viewer');
        $project = $this->project('support-project');
        $otherProject = $this->project('other-support-project');
        [$activePeriod, $completedPeriod] = $this->periodsFor($project);
        $otherPeriod = Period::query()->create([
            'project_id' => $otherProject->id,
            'name' => 'Other Active',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        SupportTicket::query()->create([
            'user_id' => $requester->id,
            'name' => 'Destek Sahibi',
            'email' => 'destek@example.test',
            'subject' => 'Aktif Donem Destegi',
            'message' => 'Aktif donem destek icerigi.',
            'category' => 'general',
            'project_id' => $project->id,
            'period_id' => $activePeriod->id,
            'status' => 'open',
        ]);
        SupportTicket::query()->create([
            'user_id' => $requester->id,
            'name' => 'Destek Sahibi',
            'email' => 'destek@example.test',
            'subject' => 'Arsiv Donem Destegi',
            'message' => 'Arsiv donem destek icerigi.',
            'category' => 'general',
            'project_id' => $project->id,
            'period_id' => $completedPeriod->id,
            'status' => 'open',
        ]);

        $this->getJson("/api/panel/support/tickets?project_id={$project->id}&period_id={$completedPeriod->id}")
            ->assertOk()
            ->assertJsonPath('tickets.data.0.subject', 'Arsiv Donem Destegi')
            ->assertJsonPath('tickets.data.0.period.name', 'Completed Period');

        $this->getJson("/api/panel/support/tickets?project_id={$project->id}&period_id={$otherPeriod->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period_id');
    }

    public function test_panel_requests_can_filter_completed_period_with_context_guard(): void
    {
        $requester = User::factory()->create(['name' => 'Talep', 'surname' => 'Sahibi']);
        $this->actorWithAllScope('requests.view', 'request_period_viewer');
        $project = $this->project('request-project');
        $otherProject = $this->project('other-request-project');
        [$activePeriod, $completedPeriod] = $this->periodsFor($project);
        $otherPeriod = Period::query()->create([
            'project_id' => $otherProject->id,
            'name' => 'Other Active',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        WorkflowRequest::query()->create([
            'requester_id' => $requester->id,
            'type' => 'food',
            'target_unit' => 'operations',
            'description' => 'Aktif donem talep icerigi.',
            'status' => 'pending',
            'project_id' => $project->id,
            'period_id' => $activePeriod->id,
        ]);
        WorkflowRequest::query()->create([
            'requester_id' => $requester->id,
            'type' => 'ticket',
            'target_unit' => 'operations',
            'description' => 'Arsiv donem talep icerigi.',
            'status' => 'pending',
            'project_id' => $project->id,
            'period_id' => $completedPeriod->id,
        ]);

        $this->getJson("/api/panel/requests?project_id={$project->id}&period_id={$completedPeriod->id}")
            ->assertOk()
            ->assertJsonPath('requests.0.description', 'Arsiv donem talep icerigi.')
            ->assertJsonPath('requests.0.period.name', 'Completed Period');

        $this->getJson("/api/panel/requests?project_id={$project->id}&period_id={$otherPeriod->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period_id');
    }

    public function test_panel_dashboard_returns_selected_completed_period_analytics(): void
    {
        $actor = $this->actorWithAllScopes([
            'dashboard.coordinator.view',
            'projects.view',
            'programs.view',
            'applications.view',
            'financial.view',
            'certificates.view',
            'assignments.view',
        ], 'dashboard_period_viewer');
        $project = $this->project('dashboard-project');
        $project->update(['quota' => 4]);
        $otherProject = $this->project('other-dashboard-project');
        [$activePeriod, $completedPeriod] = $this->periodsFor($project);
        $otherPeriod = Period::query()->create([
            'project_id' => $otherProject->id,
            'name' => 'Other Active',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        Participant::query()->create([
            'user_id' => User::factory()->create(['surname' => 'AktifDonem'])->id,
            'project_id' => $project->id,
            'period_id' => $activePeriod->id,
            'status' => 'active',
            'credit' => 95,
        ]);
        Participant::query()->create([
            'user_id' => User::factory()->create(['surname' => 'Aktif'])->id,
            'project_id' => $project->id,
            'period_id' => $completedPeriod->id,
            'status' => 'active',
            'credit' => 85,
        ]);
        Participant::query()->create([
            'user_id' => User::factory()->create(['surname' => 'Yedek'])->id,
            'project_id' => $project->id,
            'period_id' => $completedPeriod->id,
            'status' => 'waitlist',
            'credit' => 100,
        ]);
        Participant::query()->create([
            'user_id' => User::factory()->create(['surname' => 'Mezun'])->id,
            'project_id' => $project->id,
            'period_id' => $completedPeriod->id,
            'status' => 'graduated',
            'graduation_status' => 'graduated',
            'credit' => 85,
        ]);
        Participant::query()->create([
            'user_id' => User::factory()->create(['surname' => 'Tamamlayamadi'])->id,
            'project_id' => $project->id,
            'period_id' => $completedPeriod->id,
            'status' => 'failed',
            'graduation_status' => 'not_completed',
            'credit' => 50,
        ]);
        Program::query()->create($this->programPayload($project, $completedPeriod) + [
            'status' => 'completed',
            'created_by' => $actor->id,
        ]);
        Application::query()->create([
            'user_id' => User::factory()->create(['surname' => 'Aday'])->id,
            'project_id' => $project->id,
            'period_id' => $completedPeriod->id,
            'status' => 'accepted',
            'form_data' => [],
        ]);
        FinancialTransaction::query()->create([
            'project_id' => $project->id,
            'period_id' => $completedPeriod->id,
            'type' => 'expense',
            'category' => 'education',
            'payee_name' => 'Dashboard Tedarikci',
            'amount' => 250,
            'status' => 'approved',
            'submitted_by' => $actor->id,
            'submitted_at' => now(),
        ]);
        Certificate::query()->create([
            'user_id' => User::factory()->create(['surname' => 'Sertifika'])->id,
            'project_id' => $project->id,
            'period_id' => $completedPeriod->id,
            'type' => 'participation',
            'verification_code' => 'DASHCERT1',
            'issued_at' => now(),
            'created_by' => $actor->id,
        ]);

        $this->getJson("/api/panel/dashboard/stats?project_id={$project->id}&period_id={$completedPeriod->id}")
            ->assertOk()
            ->assertJsonPath('dashboard_context.period_id', $completedPeriod->id)
            ->assertJsonPath('dashboard_context.archive_mode', true)
            ->assertJsonPath('period_analytics.period.name', 'Completed Period')
            ->assertJsonPath('period_analytics.participants_total', 4)
            ->assertJsonPath('period_analytics.participants_active', 1)
            ->assertJsonPath('period_analytics.programs_total', 1)
            ->assertJsonPath('period_analytics.applications_total', 1)
            ->assertJsonPath('period_analytics.certificates_total', 1)
            ->assertJsonPath('project_occupancy.0.active', 1)
            ->assertJsonPath('project_occupancy.0.total', 4)
            ->assertJsonPath('project_occupancy.0.waitlist', 1)
            ->assertJsonPath('project_occupancy.0.graduates', 1)
            ->assertJsonPath('project_occupancy.0.not_completed', 1)
            ->assertJsonPath('project_occupancy.0.max', 4)
            ->assertJsonPath('project_occupancy.0.rate', 25);

        $this->getJson("/api/panel/dashboard/stats?project_id={$project->id}&period_id={$otherPeriod->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period_id');
    }

    public function test_program_attendance_details_include_completed_period_participants(): void
    {
        $actor = $this->actorWithAllScope('programs.attendance.view', 'attendance_period_viewer');
        $project = $this->project('attendance-history-project');
        [, $completedPeriod] = $this->periodsFor($project);

        $student = User::factory()->create(['name' => 'Arsiv', 'surname' => 'Ogrenci', 'role' => 'student']);
        $participant = Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $completedPeriod->id,
            'status' => 'active',
            'credit' => 90,
        ]);
        $program = Program::query()->create($this->programPayload($project, $completedPeriod) + [
            'title' => 'Arsiv Yoklama Programi',
            'status' => 'completed',
            'created_by' => $actor->id,
        ]);
        Attendance::query()->create([
            'program_id' => $program->id,
            'user_id' => $student->id,
            'method' => 'qr',
            'is_valid' => true,
            'latitude' => 41.015,
            'longitude' => 28.979,
        ]);

        $this->getJson("/api/panel/programs/{$program->id}/attendances")
            ->assertOk()
            ->assertJsonPath('program.period', 'Completed Period')
            ->assertJsonPath('summary.participant_count', 1)
            ->assertJsonPath('summary.attendance_count', 1)
            ->assertJsonPath('records.0.participant_id', $participant->id)
            ->assertJsonPath('records.0.attendance_status', 'present');
    }

    public function test_feedback_summary_validates_period_project_access_without_project_parameter(): void
    {
        $role = Role::findOrCreate('feedback_selected_project_viewer', 'web');
        Permission::findOrCreate('programs.view', 'web');
        $role->givePermissionTo('programs.view');

        $project = $this->project('feedback-history-project');
        $otherProject = $this->project('feedback-outside-project');
        [, $completedPeriod] = $this->periodsFor($project);
        [, $outsidePeriod] = $this->periodsFor($otherProject);

        RolePermissionScope::query()->create([
            'role_name' => 'feedback_selected_project_viewer',
            'permission_name' => 'programs.view',
            'scope_type' => 'selected_projects',
            'scope_payload' => ['project_ids' => [$project->id]],
        ]);
        $actor = User::factory()->create(['name' => 'Feedback', 'surname' => 'Viewer', 'role' => 'coordinator']);
        $actor->assignRole($role);
        Sanctum::actingAs($actor);

        $program = Program::query()->create($this->programPayload($project, $completedPeriod) + [
            'title' => 'Arsiv Anket Programi',
            'status' => 'completed',
            'created_by' => $actor->id,
        ]);
        Program::query()->create($this->programPayload($otherProject, $outsidePeriod) + [
            'title' => 'Yetkisiz Arsiv Anket Programi',
            'status' => 'completed',
            'created_by' => $actor->id,
        ]);
        Feedback::query()->create([
            'program_id' => $program->id,
            'anonymous_token' => 'feedback-history-1',
            'responses' => [
                'content_quality' => 5,
                'speaker_quality' => 4,
                'organization_quality' => 5,
                'comment' => 'Guclu arsiv akisi',
            ],
            'submitted_at' => now(),
        ]);

        $this->getJson("/api/panel/programs/feedback-summary?period_id={$completedPeriod->id}")
            ->assertOk()
            ->assertJsonPath('summary.program_count', 1)
            ->assertJsonPath('summary.total_feedback', 1)
            ->assertJsonPath('summary.overall_average', 4.67)
            ->assertJsonPath('programs.0.period', 'Completed Period')
            ->assertJsonPath('recent_comments.0.comment', 'Guclu arsiv akisi');

        $this->getJson("/api/panel/programs/feedback-summary?period_id={$outsidePeriod->id}")
            ->assertForbidden();

        $this->get("/api/panel/programs/feedback-summary/export?period_id={$completedPeriod->id}&format=csv")
            ->assertOk();

        $this->getJson("/api/panel/programs/feedback-summary/export?period_id={$outsidePeriod->id}&format=csv")
            ->assertForbidden();
    }

    public function test_kpd_appointments_validate_period_against_accessible_kpd_scope(): void
    {
        $kpdProject = Project::query()->create([
            'name' => 'KPD Projesi',
            'slug' => 'kpd-projesi',
            'type' => 'kpd',
            'status' => 'active',
        ]);
        $otherProject = $this->project('outside-kpd-project');
        [$activePeriod, $completedPeriod] = $this->periodsFor($kpdProject);
        $outsidePeriod = Period::query()->create([
            'project_id' => $otherProject->id,
            'name' => 'Outside Active',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        Permission::findOrCreate('kpd.appointments.view', 'web');
        $role = Role::findOrCreate('kpd_period_viewer', 'web');
        $role->givePermissionTo('kpd.appointments.view');
        RolePermissionScope::query()->create([
            'role_name' => 'kpd_period_viewer',
            'permission_name' => 'kpd.appointments.view',
            'scope_type' => 'selected_projects',
            'scope_payload' => ['project_ids' => [$kpdProject->id]],
        ]);
        $actor = User::factory()->create(['name' => 'KPD', 'surname' => 'Viewer', 'role' => 'coordinator']);
        $actor->assignRole($role);
        Sanctum::actingAs($actor);

        $counselor = User::factory()->create(['name' => 'KPD', 'surname' => 'Counselor', 'role' => 'staff', 'status' => 'active']);
        $counselee = User::factory()->create(['name' => 'KPD', 'surname' => 'Counselee', 'role' => 'student', 'status' => 'active']);
        Participant::query()->create([
            'user_id' => $counselee->id,
            'project_id' => $kpdProject->id,
            'period_id' => $completedPeriod->id,
            'status' => 'active',
            'credit' => 100,
        ]);
        $room = KpdRoom::query()->create([
            'name' => 'room_1',
            'description' => 'Test odasi',
        ]);
        KpdAppointment::query()->create([
            'counselor_id' => $counselor->id,
            'counselee_id' => $counselee->id,
            'period_id' => $completedPeriod->id,
            'room_id' => $room->id,
            'start_at' => now()->subDays(10),
            'end_at' => now()->subDays(10)->addHour(),
            'status' => 'completed',
        ]);

        $this->getJson("/api/panel/kpd/appointments?period_id={$completedPeriod->id}")
            ->assertOk()
            ->assertJsonPath('appointments.data.0.period_id', $completedPeriod->id);

        $this->getJson("/api/panel/kpd/appointments?period_id={$outsidePeriod->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period_id');

        $this->assertNotNull($activePeriod);
    }

    public function test_project_special_modules_validate_period_with_any_project_permission(): void
    {
        $this->actorWithAllScope('projects.internships.view', 'special_module_period_viewer');
        $project = $this->project('special-module-project');
        $otherProject = $this->project('other-special-module-project');
        [, $completedPeriod] = $this->periodsFor($project);
        $otherPeriod = Period::query()->create([
            'project_id' => $otherProject->id,
            'name' => 'Other Active',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        $response = $this->getJson("/api/panel/projects/{$project->id}/special-modules?period_id={$completedPeriod->id}")
            ->assertOk();

        $this->assertTrue($response->json('access')['projects.internships.view'] ?? false);

        $this->getJson("/api/panel/projects/{$project->id}/special-modules?period_id={$otherPeriod->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period_id');
    }

    public function test_completed_period_blocks_program_create_without_archive_permission(): void
    {
        $this->actorWithAllScope('programs.create', 'program_creator_without_archive');
        $project = $this->project('archive-program-project');
        [, $completedPeriod] = $this->periodsFor($project);

        $this->postJson('/api/panel/programs', $this->programPayload($project, $completedPeriod))
            ->assertStatus(423)
            ->assertJsonPath('message', 'Tamamlanmis donem arsiv modundadir. Degisiklik icin arsiv duzeltme yetkisi gerekir.');
    }

    public function test_archive_update_permission_allows_completed_period_program_create(): void
    {
        $this->actorWithAllScopes(['programs.create', 'periods.archive.update'], 'program_creator_with_archive');
        $project = $this->project('archive-override-program-project');
        [, $completedPeriod] = $this->periodsFor($project);

        $this->postJson('/api/panel/programs', $this->programPayload($project, $completedPeriod))
            ->assertCreated()
            ->assertJsonPath('program.period.id', $completedPeriod->id);
    }

    public function test_completed_period_blocks_financial_approval_without_archive_permission(): void
    {
        $actor = $this->actorWithAllScope('financial.approve', 'financial_approver_without_archive');
        $project = $this->project('archive-financial-project');
        [, $completedPeriod] = $this->periodsFor($project);

        $transaction = FinancialTransaction::query()->create([
            'project_id' => $project->id,
            'period_id' => $completedPeriod->id,
            'type' => 'expense',
            'category' => 'education',
            'payee_name' => 'Arsiv Tedarikci',
            'amount' => 100,
            'status' => 'pending',
            'submitted_by' => $actor->id,
            'submitted_at' => now(),
        ]);

        $this->putJson("/api/panel/financials/{$transaction->id}/approve")
            ->assertStatus(423)
            ->assertJsonPath('message', 'Tamamlanmis donem arsiv modundadir. Degisiklik icin arsiv duzeltme yetkisi gerekir.');
    }

    public function test_completed_period_blocks_calendar_assignment_update_without_archive_permission(): void
    {
        $actor = $this->actorWithAllScope('calendar.assignments.manage', 'calendar_assignment_without_archive');
        $project = $this->project('archive-calendar-project');
        [, $completedPeriod] = $this->periodsFor($project);

        $program = Program::query()->create($this->programPayload($project, $completedPeriod) + [
            'status' => 'scheduled',
            'created_by' => $actor->id,
        ]);

        $this->putJson("/api/panel/calendar/programs/{$program->id}/assignments", [
            'assigned_user_ids' => [],
        ])
            ->assertStatus(423)
            ->assertJsonPath('message', 'Tamamlanmis donem arsiv modundadir. Degisiklik icin arsiv duzeltme yetkisi gerekir.');
    }

    public function test_period_completion_creates_persistent_archive_snapshot(): void
    {
        $actor = $this->actorWithAllScopes(['periods.update', 'periods.view'], 'period_snapshot_closer');
        $project = $this->project('period-snapshot-project');
        [$activePeriod] = $this->periodsFor($project);
        $riskStudent = User::factory()->create(['name' => 'Risk', 'surname' => 'Snapshot', 'email' => 'risk-snapshot@test.local', 'role' => 'student']);
        $safeStudent = User::factory()->create(['name' => 'Safe', 'surname' => 'Snapshot', 'email' => 'safe-snapshot@test.local', 'role' => 'student']);
        Participant::query()->create([
            'user_id' => $riskStudent->id,
            'project_id' => $project->id,
            'period_id' => $activePeriod->id,
            'status' => 'active',
            'credit' => 70,
        ]);
        Participant::query()->create([
            'user_id' => $safeStudent->id,
            'project_id' => $project->id,
            'period_id' => $activePeriod->id,
            'status' => 'active',
            'credit' => 95,
        ]);

        Program::query()->create($this->programPayload($project, $activePeriod) + [
            'status' => 'scheduled',
            'created_by' => $actor->id,
        ]);

        $this->postJson("/api/panel/periods/{$activePeriod->id}/complete", [
            'notes' => 'Donem kapanis testi',
        ])
            ->assertOk()
            ->assertJsonPath('period.status', 'completed')
            ->assertJsonPath('archive.archive_version', 1)
            ->assertJsonPath('archive.summary.programs.total', 1)
            ->assertJsonPath('archive.summary.credit_snapshot.participant_count', 2)
            ->assertJsonPath('archive.summary.credit_snapshot.total_credit', 165)
            ->assertJsonPath('archive.summary.credit_snapshot.below_threshold_count', 1)
            ->assertJsonPath('archive.summary.credit_snapshot.participants.0.student', 'Risk Snapshot')
            ->assertJsonPath('archive.summary.credit_snapshot.participants.0.credit', 70)
            ->assertJsonPath('archive.warnings.open_programs', 1);

        $archive = PeriodArchive::query()->where('period_id', $activePeriod->id)->first();
        $this->assertNotNull($archive);
        $this->assertSame($project->id, $archive->project_id);
        $this->assertSame($actor->id, $archive->closed_by);
        $this->assertSame('Donem kapanis testi', $archive->notes);
        $this->assertSame(1, $archive->summary_json['credit_snapshot']['below_threshold_count']);
        $this->assertSame('Risk Snapshot', $archive->summary_json['credit_snapshot']['participants'][0]['student']);
        $this->assertSame(64, strlen($archive->integrity_hash));

        $this->getJson("/api/panel/periods/{$activePeriod->id}/closure-summary")
            ->assertOk()
            ->assertJsonPath('latest_archive.id', $archive->id)
            ->assertJsonPath('latest_archive.counts.programs_total', 1)
            ->assertJsonPath('latest_archive.counts.credit_below_threshold_total', 1);
    }

    private function actorWithAllScope(string $permissionName, string $roleName): User
    {
        return $this->actorWithAllScopes([$permissionName], $roleName);
    }

    /** @param list<string> $permissionNames */
    private function actorWithAllScopes(array $permissionNames, string $roleName): User
    {
        $role = Role::findOrCreate($roleName, 'web');
        foreach ($permissionNames as $permissionName) {
            Permission::findOrCreate($permissionName, 'web');
            $role->givePermissionTo($permissionName);

            RolePermissionScope::query()->create([
                'role_name' => $roleName,
                'permission_name' => $permissionName,
                'scope_type' => 'all',
                'scope_payload' => [],
            ]);
        }

        $actor = User::factory()->create([
            'name' => 'Period',
            'surname' => 'Viewer',
            'role' => 'coordinator',
        ]);
        $actor->assignRole($role);
        Sanctum::actingAs($actor);

        return $actor;
    }

    private function programPayload(Project $project, Period $period): array
    {
        return [
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Arsiv Donem Programi',
            'description' => 'Test programi',
            'location' => 'KADEME',
            'radius_meters' => 100,
            'start_at' => now()->addDays(3)->toIso8601String(),
            'end_at' => now()->addDays(3)->addHour()->toIso8601String(),
            'credit_deduction' => 0,
            'target_audience' => ['student'],
        ];
    }

    private function project(string $slug): Project
    {
        return Project::query()->create([
            'name' => str_replace('-', ' ', $slug),
            'slug' => $slug,
            'type' => 'other',
            'status' => 'active',
        ]);
    }

    /** @return array{0: Period, 1: Period} */
    private function periodsFor(Project $project): array
    {
        return [
            Period::query()->create([
                'project_id' => $project->id,
                'name' => 'Active Period',
                'start_date' => now()->toDateString(),
                'end_date' => now()->addMonth()->toDateString(),
                'status' => 'active',
            ]),
            Period::query()->create([
                'project_id' => $project->id,
                'name' => 'Completed Period',
                'start_date' => now()->subMonths(5)->toDateString(),
                'end_date' => now()->subMonths(4)->toDateString(),
                'status' => 'completed',
            ]),
        ];
    }
}
