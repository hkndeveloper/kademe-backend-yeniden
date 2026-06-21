<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationForm;
use App\Models\Attendance;
use App\Models\Badge;
use App\Models\BlogPost;
use App\Models\CalendarEvent;
use App\Models\Certificate;
use App\Models\CreditLog;
use App\Models\DigitalBohca;
use App\Models\Feedback;
use App\Models\FeedbackFormQuestion;
use App\Models\FeedbackFormTemplate;
use App\Models\FinancialTransaction;
use App\Models\Internship;
use App\Models\KpdAppointment;
use App\Models\KpdReport;
use App\Models\KpdRoom;
use App\Models\KvkkForgetRequest;
use App\Models\Participant;
use App\Models\Period;
use App\Models\PersonalityTestQuestion;
use App\Models\PersonalityTestResultRange;
use App\Models\PersonalityTestTemplate;
use App\Models\Program;
use App\Models\Project;
use App\Models\RewardTier;
use App\Models\RolePermissionScope;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\VolunteerOpportunity;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
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

    public function test_site_config_normalizes_legacy_malformed_array_settings(): void
    {
        SystemSetting::query()->create([
            'group' => 'navigation',
            'key' => 'header_links',
            'value' => 'legacy-string-value',
        ]);
        SystemSetting::query()->create([
            'group' => 'homepage',
            'key' => 'stats',
            'value' => '{"label":"not-array"}',
        ]);
        SystemSetting::query()->create([
            'group' => 'homepage',
            'key' => 'featured_activity_ids',
            'value' => '["12","bad",14]',
        ]);
        SystemSetting::query()->create([
            'group' => 'homepage',
            'key' => 'block_visibility',
            'value' => '{"hero":false,"stats":"wrong"}',
        ]);

        $response = $this->getJson('/api/site-config')
            ->assertOk()
            ->assertJsonPath('settings.homepage.block_visibility.hero', false)
            ->assertJsonPath('settings.homepage.block_visibility.stats', true);

        $settings = $response->json('settings');

        $this->assertIsArray(data_get($settings, 'navigation.header_links'));
        $this->assertIsArray(data_get($settings, 'homepage.stats'));
        $this->assertSame([12, 14], data_get($settings, 'homepage.featured_activity_ids'));
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
        $admin = $this->actingSuperAdmin();
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

        $certificate = Certificate::query()->where([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'type' => 'participation',
            'certificate_path' => 'certificates/sample.pdf',
        ])->firstOrFail();

        $createLog = Activity::query()
            ->where('description', 'certificate.created')
            ->latest()
            ->first();

        $this->assertNotNull($createLog);
        $this->assertSame('certificate.created', $createLog->event);
        $this->assertSame(Certificate::class, $createLog->subject_type);
        $this->assertSame($certificate->id, (int) $createLog->subject_id);
        $this->assertSame($admin->id, (int) $createLog->causer_id);
        $this->assertSame('certificate_created', data_get($createLog->properties->toArray(), 'domain.operation'));
        $this->assertSame('participation', data_get($createLog->properties->toArray(), 'domain.type'));
        $this->assertSame('certificates/sample.pdf', data_get($createLog->properties->toArray(), 'domain.certificate_path'));

        $this->deleteJson('/api/panel/certificates/'.$certificate->id)->assertOk();

        $deleteLog = Activity::query()
            ->where('description', 'certificate.deleted')
            ->latest()
            ->first();

        $this->assertNotNull($deleteLog);
        $this->assertSame('certificate.deleted', $deleteLog->event);
        $this->assertSame(Certificate::class, $deleteLog->subject_type);
        $this->assertSame($certificate->id, (int) $deleteLog->subject_id);
        $this->assertSame('certificate_deleted', data_get($deleteLog->properties->toArray(), 'domain.operation'));
        $this->assertSame($certificate->verification_code, data_get($deleteLog->properties->toArray(), 'domain.verification_code'));
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
            'name' => 'Sosyal Medya KoordinatÃƒÆ’Ã‚Â¶rÃƒÆ’Ã‚Â¼',
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

    public function test_manageable_projects_summary_counts_active_period_participants_without_public_visibility(): void
    {
        $this->actingSuperAdmin();
        $project = $this->project();

        $activePeriod = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Aktif Donem',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'active',
        ]);

        $completedPeriod = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2025 Mezun Donem',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
            'status' => 'completed',
        ]);

        $hiddenActive = User::factory()->create([
            'surname' => 'HiddenActive',
            'role' => 'student',
            'public_profile_visible' => false,
        ]);
        $publicActive = User::factory()->create([
            'surname' => 'PublicActive',
            'role' => 'student',
            'public_profile_visible' => true,
        ]);
        $oldActive = User::factory()->create([
            'surname' => 'OldActive',
            'role' => 'student',
            'public_profile_visible' => false,
        ]);
        $hiddenAlumni = User::factory()->create([
            'surname' => 'HiddenAlumni',
            'role' => 'alumni',
            'status' => 'alumni',
            'public_alumni_visible' => false,
        ]);

        foreach ([$hiddenActive, $publicActive] as $user) {
            Participant::query()->create([
                'user_id' => $user->id,
                'project_id' => $project->id,
                'period_id' => $activePeriod->id,
                'status' => 'active',
                'credit' => 100,
            ]);
        }

        Participant::query()->create([
            'user_id' => $oldActive->id,
            'project_id' => $project->id,
            'period_id' => $completedPeriod->id,
            'status' => 'active',
            'credit' => 100,
        ]);

        Participant::query()->create([
            'user_id' => $hiddenAlumni->id,
            'project_id' => $project->id,
            'period_id' => $completedPeriod->id,
            'status' => 'graduated',
            'graduation_status' => 'graduated',
            'graduated_at' => now(),
            'credit' => 100,
        ]);

        $this->getJson('/api/panel/projects/manageable')
            ->assertOk()
            ->assertJsonPath('projects.0.participant_summary.total', 4)
            ->assertJsonPath('projects.0.participant_summary.active', 2)
            ->assertJsonPath('projects.0.participant_summary.active_all_periods', 3)
            ->assertJsonPath('projects.0.participant_summary.graduates', 1)
            ->assertJsonCount(1, 'projects.0.active_students')
            ->assertJsonCount(0, 'projects.0.alumni');
    }
    public function test_scoped_content_blog_permissions_are_limited_to_owned_projects(): void
    {
        $project = $this->project();
        $otherProject = Project::query()->create([
            'name' => 'Other Content Project',
            'slug' => 'other-content-project',
            'type' => 'other',
            'status' => 'active',
        ]);
        $coordinator = User::factory()->create([
            'surname' => 'ContentScope',
            'role' => 'coordinator',
            'email' => 'content-scope@test.local',
        ]);
        $project->coordinators()->attach($coordinator->id);

        $role = Role::findOrCreate('coordinator', 'web');
        foreach (['content.view', 'content.blog.create', 'content.blog.update', 'content.blog.delete', 'content.blog.export'] as $permissionName) {
            $permission = Permission::findOrCreate($permissionName, 'web');
            $role->givePermissionTo($permission);
            RolePermissionScope::query()->updateOrCreate(
                ['role_name' => 'coordinator', 'permission_name' => $permissionName],
                ['scope_type' => 'own_projects', 'scope_payload' => []]
            );
        }
        $coordinator->assignRole($role);

        $ownBlog = BlogPost::query()->create([
            'project_id' => $project->id,
            'author_id' => $coordinator->id,
            'title' => 'Own Scoped Blog',
            'slug' => 'own-scoped-blog',
            'content' => 'Own content',
            'status' => 'draft',
        ]);
        $otherBlog = BlogPost::query()->create([
            'project_id' => $otherProject->id,
            'author_id' => $coordinator->id,
            'title' => 'Other Scoped Blog',
            'slug' => 'other-scoped-blog',
            'content' => 'Other content',
            'status' => 'draft',
        ]);
        $globalBlog = BlogPost::query()->create([
            'author_id' => $coordinator->id,
            'title' => 'Global Blog',
            'slug' => 'global-blog',
            'content' => 'Global content',
            'status' => 'draft',
        ]);

        Sanctum::actingAs($coordinator);

        $this->getJson('/api/panel/content')
            ->assertOk()
            ->assertJsonPath('content_scope.global', false)
            ->assertJsonPath('blogs.0.id', $ownBlog->id)
            ->assertJsonMissing(['id' => $otherBlog->id])
            ->assertJsonMissing(['id' => $globalBlog->id]);

        $createResponse = $this->postJson('/api/panel/content/blogs', [
            'project_id' => $project->id,
            'title' => 'New Scoped Blog',
            'slug' => 'new-scoped-blog',
            'content' => 'New content',
            'status' => 'draft',
        ])->assertCreated();
        $this->assertSame($project->id, $createResponse->json('blog.project_id'));

        $this->postJson('/api/panel/content/blogs', [
            'title' => 'Global Not Allowed',
            'slug' => 'global-not-allowed',
            'content' => 'No global content',
            'status' => 'draft',
        ])->assertStatus(422);

        $this->putJson("/api/panel/content/blogs/{$otherBlog->id}", [
            'project_id' => $otherProject->id,
            'title' => 'Other Edit Denied',
            'slug' => 'other-scoped-blog',
            'content' => 'Denied',
            'status' => 'draft',
        ])->assertForbidden();

        $this->deleteJson("/api/panel/content/blogs/{$globalBlog->id}")
            ->assertStatus(422);
    }

    public function test_financial_invoice_download_streams_file_by_default_and_supports_explicit_direct_url(): void
    {
        $admin = $this->actingSuperAdmin();
        Storage::fake(config('filesystems.media_disk', 'public'));
        config([
            'filesystems.direct_media_downloads' => true,
            'filesystems.disks.'.config('filesystems.media_disk', 'public').'.url' => 'https://cdn.example.test/media',
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
            'spending_unit' => 'Program Birimi',
            'type' => 'expense',
            'category' => 'food',
            'payee_name' => 'Catering Firma',
            'amount' => 1000,
            'status' => 'approved',
            'invoice_no' => 'INV-100',
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
            'accounting_code' => '770.01',
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

        $this->getJson('/api/panel/financials?'.$query)
            ->assertOk()
            ->assertJsonCount(1, 'transactions.data')
            ->assertJsonPath('total_amount', 1000)
            ->assertJsonPath('transactions.data.0.spending_unit', 'Program Birimi')
            ->assertJsonPath('transactions.data.0.invoice_no', 'INV-100')
            ->assertJsonPath('transactions.data.0.payment_method', 'bank_transfer')
            ->assertJsonPath('transactions.data.0.accounting_code', '770.01')
            ->assertJsonPath('category_stats.0.category', 'food')
            ->assertJsonPath('status_stats.0.status', 'approved')
            ->assertJsonPath('status_stats.0.count', 1);

        $this->get('/api/panel/financials/export?'.$query)
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_financial_approval_and_payment_write_domain_audit_properties(): void
    {
        $admin = $this->actingSuperAdmin();
        $project = $this->project();
        $transaction = FinancialTransaction::query()->create([
            'project_id' => $project->id,
            'type' => 'expense',
            'category' => 'food',
            'payee_name' => 'Audit Firma',
            'amount' => 450,
            'status' => 'pending',
            'invoice_path' => 'invoices/audit.pdf',
            'submitted_by' => $admin->id,
            'submitted_at' => now(),
        ]);

        $this->putJson("/api/panel/financials/{$transaction->id}/approve")
            ->assertOk()
            ->assertJsonPath('transaction.status', 'approved');

        $approvalLog = Activity::query()
            ->where('description', 'financial.approved')
            ->latest()
            ->first();

        $this->assertNotNull($approvalLog);
        $this->assertSame('financial.approved', $approvalLog->event);
        $this->assertSame(FinancialTransaction::class, $approvalLog->subject_type);
        $this->assertSame($transaction->id, (int) $approvalLog->subject_id);
        $this->assertSame($admin->id, (int) $approvalLog->causer_id);
        $this->assertSame('financial_approved', data_get($approvalLog->properties->toArray(), 'domain.operation'));
        $this->assertSame('pending', data_get($approvalLog->properties->toArray(), 'domain.status_before'));
        $this->assertSame('approved', data_get($approvalLog->properties->toArray(), 'domain.status_after'));
        $this->assertTrue(data_get($approvalLog->properties->toArray(), 'domain.invoice_present'));

        $this->putJson("/api/panel/financials/{$transaction->id}/pay")
            ->assertOk()
            ->assertJsonPath('transaction.status', 'paid');

        $paymentLog = Activity::query()
            ->where('description', 'financial.paid')
            ->latest()
            ->first();

        $this->assertNotNull($paymentLog);
        $this->assertSame('financial.paid', $paymentLog->event);
        $this->assertSame(FinancialTransaction::class, $paymentLog->subject_type);
        $this->assertSame($transaction->id, (int) $paymentLog->subject_id);
        $this->assertSame('financial_paid', data_get($paymentLog->properties->toArray(), 'domain.operation'));
        $this->assertSame('approved', data_get($paymentLog->properties->toArray(), 'domain.status_before'));
        $this->assertSame('paid', data_get($paymentLog->properties->toArray(), 'domain.status_after'));
    }

    public function test_digital_bohca_create_and_delete_write_domain_audit_properties(): void
    {
        $admin = $this->actingSuperAdmin();
        Storage::fake(config('filesystems.media_disk', 'public'));
        $project = $this->project();
        $file = UploadedFile::fake()->create('bohca.pdf', 12, 'application/pdf');

        $this->post('/api/panel/digital-bohca', [
            'project_id' => $project->id,
            'title' => 'Audit Bohca',
            'description' => 'Audit test',
            'visible_to_student' => true,
            'file' => $file,
        ])->assertCreated();

        $material = DigitalBohca::query()
            ->where('project_id', $project->id)
            ->where('title', 'Audit Bohca')
            ->firstOrFail();

        $createLog = Activity::query()
            ->where('description', 'digital_bohca.created')
            ->latest()
            ->first();

        $this->assertNotNull($createLog);
        $this->assertSame('digital_bohca.created', $createLog->event);
        $this->assertSame(DigitalBohca::class, $createLog->subject_type);
        $this->assertSame($material->id, (int) $createLog->subject_id);
        $this->assertSame($admin->id, (int) $createLog->causer_id);
        $this->assertSame('digital_bohca_created', data_get($createLog->properties->toArray(), 'domain.operation'));
        $this->assertSame('Audit Bohca', data_get($createLog->properties->toArray(), 'domain.title'));
        $this->assertTrue(data_get($createLog->properties->toArray(), 'domain.visible_to_student'));

        $this->deleteJson('/api/panel/digital-bohca/'.$material->id)->assertOk();

        $deleteLog = Activity::query()
            ->where('description', 'digital_bohca.deleted')
            ->latest()
            ->first();

        $this->assertNotNull($deleteLog);
        $this->assertSame('digital_bohca.deleted', $deleteLog->event);
        $this->assertSame(DigitalBohca::class, $deleteLog->subject_type);
        $this->assertSame($material->id, (int) $deleteLog->subject_id);
        $this->assertSame('digital_bohca_deleted', data_get($deleteLog->properties->toArray(), 'domain.operation'));
        $this->assertSame($material->file_path, data_get($deleteLog->properties->toArray(), 'domain.file_path'));
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

    public function test_dashboard_stats_reports_credit_risk_participants(): void
    {
        $this->actingSuperAdmin();

        $project = $this->project();
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => 'Risk Donemi',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'credit_start_amount' => 100,
            'credit_threshold' => 75,
            'status' => 'active',
        ]);
        $student = User::factory()->create([
            'name' => 'Risk',
            'surname' => 'Student',
            'email' => 'risk-student@test.local',
            'role' => 'student',
        ]);
        Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
            'credit' => 70,
        ]);

        $this->getJson('/api/panel/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('credit_risk.count', 1)
            ->assertJsonPath('credit_risk.participants.0.student', 'Risk Student')
            ->assertJsonPath('credit_risk.participants.0.credit', 70)
            ->assertJsonPath('credit_risk.participants.0.threshold', 75);

        $this->get("/api/panel/dashboard/credit-risk/export?format=csv&project_id={$project->id}&period_id={$period->id}")
            ->assertOk();
    }

    public function test_dashboard_stats_reports_current_user_assigned_calendar_tasks(): void
    {
        $admin = $this->actingSuperAdmin();
        $project = $this->project();
        $program = Program::query()->create([
            'project_id' => $project->id,
            'title' => 'Assigned Dashboard Task',
            'description' => 'Dashboard assignment summary',
            'location' => 'KADEME',
            'start_at' => now()->addDays(2),
            'end_at' => now()->addDays(2)->addHour(),
            'status' => 'scheduled',
            'created_by' => $admin->id,
        ]);

        CalendarEvent::query()->create([
            'project_id' => $project->id,
            'program_id' => $program->id,
            'title' => $program->title,
            'location' => $program->location,
            'start_at' => $program->start_at,
            'end_at' => $program->end_at,
            'assigned_users' => [$admin->id],
            'created_by' => $admin->id,
        ]);

        $this->getJson('/api/panel/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('assigned_tasks.0.title', 'Assigned Dashboard Task')
            ->assertJsonPath('assigned_tasks.0.project.name', 'Regression Project');
    }

    public function test_application_can_target_program_and_auto_reject_by_dynamic_rule(): void
    {
        $project = Project::query()->create([
            'name' => 'Application Program Project',
            'slug' => 'application-program-project',
            'type' => 'other',
            'status' => 'active',
            'application_open' => true,
        ]);
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
            'title' => 'Program Basvurusu',
            'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addHour(),
            'status' => 'scheduled',
            'created_by' => null,
        ]);
        ApplicationForm::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'program_id' => $program->id,
            'fields' => [
                ['id' => 'department', 'label' => 'Bolum', 'type' => 'text', 'required' => true],
            ],
            'auto_reject_rules' => [
                ['field' => 'department', 'operator' => 'equals', 'value' => 'Uyumsuz', 'message' => 'Bolum uyumsuzlugu nedeniyle reddedildi.'],
            ],
            'is_active' => true,
        ]);
        $student = User::factory()->create([
            'surname' => 'AutoReject',
            'role' => 'student',
            'status' => 'active',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');
        Sanctum::actingAs($student);

        $this->postJson('/api/applications', [
            'project_id' => $project->id,
            'program_id' => $program->id,
            'form_data' => ['department' => 'Uyumsuz'],
        ])
            ->assertCreated()
            ->assertJsonPath('application.status', 'rejected')
            ->assertJsonPath('application.program_id', $program->id)
            ->assertJsonPath('application.auto_rejected', true);

        $this->assertDatabaseHas('applications', [
            'user_id' => $student->id,
            'project_id' => $project->id,
            'program_id' => $program->id,
            'status' => 'rejected',
            'auto_rejected' => true,
        ]);
    }

    public function test_program_waitlist_order_and_invitation_can_be_managed(): void
    {
        $admin = $this->actingSuperAdmin();
        $project = Project::query()->create([
            'name' => 'Waitlist Project',
            'slug' => 'waitlist-project',
            'type' => 'other',
            'status' => 'active',
            'application_open' => true,
        ]);
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
            'title' => 'Kontenjanli Program',
            'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addHour(),
            'application_quota' => 1,
            'status' => 'scheduled',
            'created_by' => $admin->id,
        ]);
        $acceptedUser = User::factory()->create(['surname' => 'Accepted', 'role' => 'student', 'status' => 'active']);
        Application::query()->create([
            'user_id' => $acceptedUser->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'program_id' => $program->id,
            'status' => 'accepted',
        ]);
        $student = User::factory()->create([
            'surname' => 'Waitlist',
            'role' => 'student',
            'status' => 'active',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');

        Sanctum::actingAs($student);
        $applicationId = $this->postJson('/api/applications', [
            'project_id' => $project->id,
            'program_id' => $program->id,
        ])
            ->assertCreated()
            ->assertJsonPath('application.status', 'waitlisted')
            ->assertJsonPath('application.waitlist_order', 1)
            ->json('application.id');

        Sanctum::actingAs($admin);
        $this->putJson("/api/panel/applications/{$applicationId}/waitlist-order", [
            'waitlist_order' => 3,
        ])
            ->assertOk()
            ->assertJsonPath('application.waitlist_order', 3);

        $this->postJson("/api/panel/applications/{$applicationId}/waitlist-invite")
            ->assertOk()
            ->assertJsonPath('message', 'Yedek liste daveti gonderildi.');

        $this->assertDatabaseHas('applications', [
            'id' => $applicationId,
            'waitlist_order' => 3,
        ]);
        $this->assertNotNull(Application::query()->find($applicationId)?->waitlist_invited_at);
    }

    public function test_waitlist_invited_student_can_accept_invitation(): void
    {
        $admin = $this->actingSuperAdmin();
        $project = Project::query()->create([
            'name' => 'Waitlist Accept Project',
            'slug' => 'waitlist-accept-project',
            'type' => 'other',
            'status' => 'active',
            'application_open' => true,
            'quota' => 1,
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
            'credit_start_amount' => 120,
        ]);

        $acceptedUser = User::factory()->create(['surname' => 'Accepted', 'role' => 'student', 'status' => 'active']);
        Application::query()->create([
            'user_id' => $acceptedUser->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'accepted',
        ]);

        $student = User::factory()->create([
            'surname' => 'Accept',
            'role' => 'student',
            'status' => 'active',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');

        $application = Application::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'waitlisted',
            'waitlist_order' => 2,
            'waitlist_invited_at' => now()->subHour(),
            'waitlist_invitation_expires_at' => now()->addDay(),
        ]);

        Application::query()->where('user_id', $acceptedUser->id)->update(['status' => 'rejected']);

        Sanctum::actingAs($student);
        $this->postJson("/api/applications/{$application->id}/waitlist-response", [
            'decision' => 'accept',
        ])
            ->assertOk()
            ->assertJsonPath('application.status', 'accepted')
            ->assertJsonPath('application.waitlist_invitation_active', false);

        $this->assertDatabaseHas('participants', [
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
            'credit' => 120,
        ]);
    }

    public function test_waitlist_invitation_reject_and_expire_flow_is_enforced(): void
    {
        $admin = $this->actingSuperAdmin();
        $project = Project::query()->create([
            'name' => 'Waitlist Reject Project',
            'slug' => 'waitlist-reject-project',
            'type' => 'other',
            'status' => 'active',
            'application_open' => true,
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        $student = User::factory()->create([
            'surname' => 'Reject',
            'role' => 'student',
            'status' => 'active',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');

        $application = Application::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'waitlisted',
            'waitlist_order' => 1,
            'waitlist_invited_at' => now()->subHour(),
            'waitlist_invitation_expires_at' => now()->addDay(),
        ]);

        Sanctum::actingAs($student);
        $this->postJson("/api/applications/{$application->id}/waitlist-response", [
            'decision' => 'reject',
        ])
            ->assertOk()
            ->assertJsonPath('application.status', 'rejected');

        $expiredCandidate = User::factory()->create([
            'surname' => 'Expired',
            'role' => 'student',
            'status' => 'active',
            'kvkk_consent_at' => now(),
        ]);
        $expiredCandidate->assignRole('student');
        $expiredApp = Application::query()->create([
            'user_id' => $expiredCandidate->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'waitlisted',
            'waitlist_order' => 2,
            'waitlist_invited_at' => now()->subDays(2),
            'waitlist_invitation_expires_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($admin);
        $this->postJson("/api/panel/applications/{$expiredApp->id}/waitlist-refresh")
            ->assertOk()
            ->assertJsonPath('expired_count', 1)
            ->assertJsonPath('auto_invited_application_id', $expiredApp->id);

        $this->assertDatabaseHas('applications', [
            'id' => $expiredApp->id,
            'status' => 'waitlisted',
        ]);
        $this->assertNotNull(Application::query()->find($expiredApp->id)?->waitlist_invited_at);
    }

    public function test_waitlist_rejection_auto_invites_next_candidate_when_seat_is_available(): void
    {
        $project = Project::query()->create([
            'name' => 'Waitlist Auto Invite Project',
            'slug' => 'waitlist-auto-invite-project',
            'type' => 'other',
            'status' => 'active',
            'application_open' => true,
            'quota' => 1,
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        $firstUser = User::factory()->create([
            'surname' => 'First',
            'role' => 'student',
            'status' => 'active',
            'kvkk_consent_at' => now(),
        ]);
        $secondUser = User::factory()->create([
            'surname' => 'Second',
            'role' => 'student',
            'status' => 'active',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('student', 'web');
        $firstUser->assignRole('student');
        $secondUser->assignRole('student');

        $firstApplication = Application::query()->create([
            'user_id' => $firstUser->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'waitlisted',
            'waitlist_order' => 1,
            'waitlist_invited_at' => now()->subHour(),
            'waitlist_invitation_expires_at' => now()->addDay(),
        ]);
        $secondApplication = Application::query()->create([
            'user_id' => $secondUser->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'waitlisted',
            'waitlist_order' => 2,
        ]);

        Sanctum::actingAs($firstUser);
        $this->postJson("/api/applications/{$firstApplication->id}/waitlist-response", [
            'decision' => 'reject',
        ])->assertOk();

        $this->assertDatabaseHas('applications', [
            'id' => $firstApplication->id,
            'status' => 'rejected',
        ]);
        $this->assertNotNull(Application::query()->find($secondApplication->id)?->waitlist_invited_at);
    }

    public function test_admin_rejecting_application_auto_invites_first_waitlisted_candidate(): void
    {
        $admin = $this->actingSuperAdmin();
        $project = Project::query()->create([
            'name' => 'Admin Auto Invite Project',
            'slug' => 'admin-auto-invite-project',
            'type' => 'other',
            'status' => 'active',
            'application_open' => true,
            'quota' => 1,
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        $pendingUser = User::factory()->create(['surname' => 'Pending', 'role' => 'student', 'status' => 'active']);
        $waitlistedUser = User::factory()->create(['surname' => 'Waitlisted', 'role' => 'student', 'status' => 'active']);

        $pendingApplication = Application::query()->create([
            'user_id' => $pendingUser->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'pending',
        ]);
        $waitlistedApplication = Application::query()->create([
            'user_id' => $waitlistedUser->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'waitlisted',
            'waitlist_order' => 1,
        ]);

        Sanctum::actingAs($admin);
        $this->putJson("/api/panel/applications/{$pendingApplication->id}/status", [
            'status' => 'rejected',
            'rejection_reason' => 'Kontenjan planlamasi degisti.',
        ])->assertOk();

        $this->assertNotNull(Application::query()->find($waitlistedApplication->id)?->waitlist_invited_at);
    }

    public function test_program_creation_rejects_overlapping_time_across_projects(): void
    {
        $this->actingSuperAdmin();
        $firstProject = $this->project();
        $secondProject = Project::query()->create([
            'name' => 'Other Regression Project',
            'slug' => 'other-regression-project',
            'type' => 'other',
            'status' => 'active',
        ]);
        $firstPeriod = Period::query()->create([
            'project_id' => $firstProject->id,
            'name' => '2026 Guz',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
            'status' => 'active',
        ]);
        $secondPeriod = Period::query()->create([
            'project_id' => $secondProject->id,
            'name' => '2026 Guz',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
            'status' => 'active',
        ]);

        Program::query()->create([
            'project_id' => $firstProject->id,
            'period_id' => $firstPeriod->id,
            'title' => 'Mevcut Program',
            'location' => 'Salon A',
            'radius_meters' => 100,
            'start_at' => '2026-10-10 10:00:00',
            'end_at' => '2026-10-10 12:00:00',
            'credit_deduction' => 10,
            'status' => 'scheduled',
        ]);

        $this->postJson('/api/panel/programs', [
            'project_id' => $secondProject->id,
            'period_id' => $secondPeriod->id,
            'title' => 'Cakisan Program',
            'location' => 'Salon B',
            'radius_meters' => 100,
            'start_at' => '2026-10-10 11:00:00',
            'end_at' => '2026-10-10 13:00:00',
            'credit_deduction' => 10,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_at');

        $this->assertDatabaseMissing('programs', [
            'project_id' => $secondProject->id,
            'title' => 'Cakisan Program',
        ]);
    }

    public function test_program_application_quota_can_be_created_and_updated(): void
    {
        $this->actingSuperAdmin();
        $project = $this->project();
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Basvuru',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
            'status' => 'active',
        ]);

        $createResponse = $this->postJson('/api/panel/programs', [
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Kontenjanli Program',
            'location' => 'Salon A',
            'radius_meters' => 100,
            'start_at' => '2026-10-11 10:00:00',
            'end_at' => '2026-10-11 12:00:00',
            'credit_deduction' => 10,
            'application_quota' => 24,
        ])
            ->assertCreated()
            ->assertJsonPath('program.application_quota', 24);

        $programId = $createResponse->json('program.id');

        $this->putJson('/api/panel/programs/'.$programId, [
            'title' => 'Kontenjanli Program',
            'description' => null,
            'location' => 'Salon A',
            'latitude' => null,
            'longitude' => null,
            'radius_meters' => 100,
            'guest_info' => null,
            'start_at' => '2026-10-11 10:00:00',
            'end_at' => '2026-10-11 12:00:00',
            'credit_deduction' => 10,
            'application_quota' => 30,
            'status' => 'scheduled',
        ])
            ->assertOk()
            ->assertJsonPath('program.application_quota', 30);

        $this->assertDatabaseHas('programs', [
            'id' => $programId,
            'application_quota' => 30,
        ]);
    }

    public function test_program_update_rejects_overlapping_time_and_allows_cancelled_conflicts(): void
    {
        $this->actingSuperAdmin();
        $project = $this->project();
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Guz',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
            'status' => 'active',
        ]);
        Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Sabit Program',
            'location' => 'Salon A',
            'radius_meters' => 100,
            'start_at' => '2026-10-10 10:00:00',
            'end_at' => '2026-10-10 12:00:00',
            'credit_deduction' => 10,
            'status' => 'scheduled',
        ]);
        $program = Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Guncellenecek Program',
            'location' => 'Salon B',
            'radius_meters' => 100,
            'start_at' => '2026-10-10 14:00:00',
            'end_at' => '2026-10-10 15:00:00',
            'credit_deduction' => 10,
            'status' => 'scheduled',
        ]);

        $this->putJson('/api/panel/programs/'.$program->id, [
            'title' => 'Guncellenecek Program',
            'description' => null,
            'location' => 'Salon B',
            'latitude' => null,
            'longitude' => null,
            'radius_meters' => 100,
            'guest_info' => null,
            'start_at' => '2026-10-10 11:00:00',
            'end_at' => '2026-10-10 13:00:00',
            'credit_deduction' => 10,
            'status' => 'scheduled',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_at');

        $this->putJson('/api/panel/programs/'.$program->id, [
            'title' => 'Guncellenecek Program',
            'description' => null,
            'location' => 'Salon B',
            'latitude' => null,
            'longitude' => null,
            'radius_meters' => 100,
            'guest_info' => null,
            'start_at' => '2026-10-10 11:00:00',
            'end_at' => '2026-10-10 13:00:00',
            'credit_deduction' => 10,
            'status' => 'cancelled',
        ])->assertOk();

        $this->assertDatabaseHas('programs', [
            'id' => $program->id,
            'status' => 'cancelled',
        ]);
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
        $this->assertSame(90, $participant->fresh()->credit);
        $this->assertSame(-10, (int) CreditLog::query()
            ->where('participant_id', $participant->id)
            ->where('program_id', $program->id)
            ->sum('amount'));
        $this->assertSame(0, CreditLog::query()
            ->where('participant_id', $participant->id)
            ->where('program_id', $program->id)
            ->where('type', 'restore')
            ->count());

        $this->putJson("/api/panel/programs/{$program->id}/attendances/{$participant->id}", [
            'is_valid' => true,
        ])->assertOk();
        $this->assertSame(90, $participant->fresh()->credit);
        $this->assertSame(1, CreditLog::query()
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
        $this->assertSame(1, CreditLog::query()
            ->where('participant_id', $participant->id)
            ->where('program_id', $program->id)
            ->where('type', 'deduction')
            ->count());

        Sanctum::actingAs($student);
        $this->getJson('/api/programs')
            ->assertOk()
            ->assertJsonPath('programs.0.attendance_status', 'invalid')
            ->assertJsonPath('programs.0.credit.restored', false)
            ->assertJsonPath('programs.0.credit.net_amount', -10);
    }

    public function test_program_complete_and_manual_attendance_write_domain_audit_properties(): void
    {
        $admin = $this->actingSuperAdmin();
        $project = $this->project();
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Audit',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $program = Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Audit Program',
            'start_at' => now()->subHours(2),
            'end_at' => now()->subHour(),
            'status' => 'active',
            'credit_deduction' => 10,
        ]);
        $student = User::factory()->create([
            'surname' => 'Audit',
            'role' => 'student',
            'kvkk_consent_at' => now(),
        ]);
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

        $completeLog = Activity::query()
            ->where('description', 'program.completed')
            ->latest()
            ->first();

        $this->assertNotNull($completeLog);
        $this->assertSame('program.completed', $completeLog->event);
        $this->assertSame(Program::class, $completeLog->subject_type);
        $this->assertSame($program->id, (int) $completeLog->subject_id);
        $this->assertSame($admin->id, (int) $completeLog->causer_id);
        $this->assertSame('program_complete', data_get($completeLog->properties->toArray(), 'domain.operation'));
        $this->assertSame(1, data_get($completeLog->properties->toArray(), 'domain.deducted_participant_count'));

        $this->putJson("/api/panel/programs/{$program->id}/attendances/{$participant->id}", [
            'is_valid' => true,
            'manual_note' => 'Audit duzeltmesi',
        ])->assertOk();

        $manualLog = Activity::query()
            ->where('description', 'attendance.manual_updated')
            ->latest()
            ->first();

        $this->assertNotNull($manualLog);
        $this->assertSame('attendance.manual_updated', $manualLog->event);
        $this->assertSame(Program::class, $manualLog->subject_type);
        $this->assertSame($program->id, (int) $manualLog->subject_id);
        $this->assertSame('manual_attendance_update', data_get($manualLog->properties->toArray(), 'domain.operation'));
        $this->assertSame($participant->id, data_get($manualLog->properties->toArray(), 'domain.participant_id'));
        $this->assertTrue(data_get($manualLog->properties->toArray(), 'domain.is_valid'));
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

    public function test_absent_student_cannot_submit_feedback_or_restore_credit(): void
    {
        $this->actingSuperAdmin();
        $project = $this->project();
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Yoklama',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $program = Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Absent Feedback Program',
            'start_at' => now()->subHours(2),
            'end_at' => now()->subHour(),
            'status' => 'active',
            'credit_deduction' => 10,
        ]);
        $student = User::factory()->create(['surname' => 'AbsentFeedback', 'role' => 'student', 'kvkk_consent_at' => now()]);
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

        Sanctum::actingAs($student);
        $this->getJson('/api/feedbacks')
            ->assertOk()
            ->assertJsonCount(0, 'programs');

        $this->postJson('/api/feedbacks', [
            'program_id' => $program->id,
            'responses' => [
                'content_quality' => 5,
                'speaker_quality' => 5,
                'organization_quality' => 5,
                'comment' => 'Sonradan doldurmayi denedi.',
            ],
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Sadece gecerli yoklamasi alinmis oturumlar icin degerlendirme gonderebilirsin.');

        $this->assertSame(90, $participant->fresh()->credit);
        $this->assertSame(0, Feedback::query()->where('program_id', $program->id)->count());
        $this->assertSame(0, CreditLog::query()->where('program_id', $program->id)->where('type', 'restore')->count());
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
            ->assertJsonPath('redirect_to', '/student/evaluate');

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

    public function test_panel_qr_generation_persists_selected_rotation_window(): void
    {
        $this->actingSuperAdmin();
        $project = $this->project();
        $program = Program::query()->create([
            'project_id' => $project->id,
            'period_id' => Period::query()->create([
                'project_id' => $project->id,
                'name' => '2026 QR Rotation',
                'start_date' => now()->subDay()->toDateString(),
                'end_date' => now()->addMonth()->toDateString(),
                'status' => 'active',
            ])->id,
            'title' => 'QR Rotation Program',
            'start_at' => now()->subMinutes(10),
            'end_at' => now()->addHour(),
            'status' => 'scheduled',
        ]);

        $this->postJson("/api/panel/programs/{$program->id}/generate-qr", [
            'rotation_seconds' => 45,
        ])
            ->assertOk()
            ->assertJsonPath('refresh_in_seconds', 45)
            ->assertJsonStructure(['qr_token', 'expires_at', 'refresh_in_seconds']);

        $program->refresh();
        $this->assertSame('active', $program->status);
        $this->assertSame(45, (int) $program->qr_rotation_seconds);
        $this->assertNotNull($program->qr_token);
        $this->assertStringStartsWith('prg_'.$program->id.'_', $program->qr_token);
        $this->assertGreaterThan(30, strlen($program->qr_token));
        $this->assertNotNull($program->qr_expires_at);
    }

    public function test_panel_qr_generation_requires_program_attendance_window(): void
    {
        $this->actingSuperAdmin();
        $project = $this->project();
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 QR Window',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $program = Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Gelecek QR Programi',
            'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addHour(),
            'status' => 'scheduled',
        ]);

        $this->postJson("/api/panel/programs/{$program->id}/generate-qr", [
            'rotation_seconds' => 45,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'QR yoklama sadece program saat araliginda baslatilabilir.');

        $program->refresh();
        $this->assertSame('scheduled', $program->status);
        $this->assertNull($program->qr_token);
        $this->assertNull($program->qr_expires_at);
    }

    public function test_qr_attendance_rejects_valid_token_outside_program_window(): void
    {
        $project = $this->project();
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 QR Scan Window',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $student = User::factory()->create([
            'role' => 'student',
            'surname' => 'QrWindow',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');
        Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
            'credit' => 100,
        ]);
        $program = Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Saat Disi QR Programi',
            'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addHour(),
            'status' => 'active',
            'qr_token' => 'future-window-token',
            'qr_expires_at' => now()->addMinute(),
        ]);

        Sanctum::actingAs($student);

        $this->postJson('/api/attendances/qr', [
            'qr_token' => 'future-window-token',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'QR yoklama sadece program saat araliginda kullanilabilir.');

        $this->assertDatabaseMissing('attendances', [
            'program_id' => $program->id,
            'user_id' => $student->id,
        ]);
    }

    public function test_alumni_with_graduated_participation_can_use_qr_attendance(): void
    {
        $project = $this->project();
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Alumni QR',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $alumni = User::factory()->create([
            'role' => 'alumni',
            'surname' => 'QrAlumni',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('alumni', 'web');
        $alumni->assignRole('alumni');
        Participant::query()->create([
            'user_id' => $alumni->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'graduated',
            'graduation_status' => 'graduated',
            'graduated_at' => now()->subWeek(),
            'credit' => 90,
        ]);
        $program = Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Mezun Bulusmasi',
            'start_at' => now()->subMinutes(10),
            'end_at' => now()->addHour(),
            'status' => 'active',
            'credit_deduction' => 10,
            'target_audience' => ['alumni'],
            'qr_token' => 'alumni-qr-token',
            'qr_expires_at' => now()->addMinute(),
        ]);

        Sanctum::actingAs($alumni);

        $this->postJson('/api/attendances/qr', [
            'qr_token' => 'alumni-qr-token',
        ])->assertOk();

        $this->assertDatabaseHas('attendances', [
            'program_id' => $program->id,
            'user_id' => $alumni->id,
            'method' => 'qr',
            'is_valid' => true,
        ]);
    }

    public function test_program_target_audience_controls_student_and_alumni_visibility(): void
    {
        $project = $this->project();
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Audience',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $student = User::factory()->create(['surname' => 'AudienceStudent', 'role' => 'student', 'kvkk_consent_at' => now()]);
        $alumni = User::factory()->create(['surname' => 'AudienceAlumni', 'role' => 'alumni', 'kvkk_consent_at' => now()]);
        Role::findOrCreate('student', 'web');
        Role::findOrCreate('alumni', 'web');
        $student->assignRole('student');
        $alumni->assignRole('alumni');
        Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
            'credit' => 90,
        ]);
        Participant::query()->create([
            'user_id' => $alumni->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'graduated',
            'graduation_status' => 'graduated',
            'graduated_at' => now()->subWeek(),
            'credit' => 90,
        ]);
        $alumniOnly = Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Mezunlara Ozel Program',
            'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addHour(),
            'status' => 'scheduled',
            'radius_meters' => 150,
            'target_audience' => ['alumni'],
        ]);

        Sanctum::actingAs($student);
        $this->getJson('/api/programs')
            ->assertOk()
            ->assertJsonMissing(['id' => $alumniOnly->id]);

        Sanctum::actingAs($alumni);
        $this->getJson('/api/programs')
            ->assertOk()
            ->assertJsonPath('programs.0.id', $alumniOnly->id)
            ->assertJsonPath('programs.0.target_audience.0', 'alumni')
            ->assertJsonPath('programs.0.radius_meters', 150);
    }

    public function test_alumni_feedback_does_not_create_credit_deduction_or_restore(): void
    {
        $project = $this->project();
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Alumni Credit',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $alumni = User::factory()->create(['surname' => 'CreditAlumni', 'role' => 'alumni', 'kvkk_consent_at' => now()]);
        Role::findOrCreate('alumni', 'web');
        $alumni->assignRole('alumni');
        $participant = Participant::query()->create([
            'user_id' => $alumni->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'graduated',
            'graduation_status' => 'graduated',
            'graduated_at' => now()->subWeek(),
            'credit' => 88,
        ]);
        $program = Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Mezun Anket Programi',
            'start_at' => now()->subDay(),
            'end_at' => now()->subDay()->addHour(),
            'status' => 'completed',
            'credit_deduction' => 10,
            'target_audience' => ['alumni'],
        ]);
        Attendance::query()->create([
            'program_id' => $program->id,
            'user_id' => $alumni->id,
            'method' => 'qr',
            'is_valid' => true,
        ]);

        Sanctum::actingAs($alumni);

        $this->postJson('/api/feedbacks', [
            'program_id' => $program->id,
            'responses' => [
                'content_quality' => 5,
                'speaker_quality' => 5,
                'organization_quality' => 5,
                'comment' => 'Mezun olarak katildim.',
            ],
        ])->assertCreated()
            ->assertJsonPath('message', 'Degerlendirmen alindi. Mezun etkinliklerinde kredi islemi uygulanmaz.');

        $this->assertSame(88, $participant->fresh()->credit);
        $this->assertSame(0, CreditLog::query()->where('program_id', $program->id)->count());
        $this->assertSame(1, Feedback::query()->where('program_id', $program->id)->count());
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
                'motivation' => 'KatÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â±lmak istiyorum.',
                'cv_file' => [
                    'path' => 'application-files/sample.pdf',
                    'original_name' => 'cv.pdf',
                    'mime_type' => 'application/pdf',
                    'size' => 10,
                ],
            ],
        ]);

        $this->getJson('/api/panel/applications?project_id='.$project->id)
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

        $this->getJson('/api/panel/applications?project_id='.$project->id)
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

        $this->getJson('/api/panel/projects/'.$project->id.'/modules')
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

        $this->getJson('/api/panel/projects/'.$project->id.'/application-form?period_id='.$fall->id)
            ->assertOk()
            ->assertJsonPath('application_form.period_id', $fall->id)
            ->assertJsonPath('application_form.fields.0.id', 'fall_question');
    }

    public function test_application_form_builder_can_manage_program_specific_forms(): void
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
        $program = Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $fall->id,
            'title' => 'Guz Atolyesi',
            'start_at' => now()->addWeek(),
            'end_at' => now()->addWeek()->addHours(2),
            'status' => 'scheduled',
        ]);
        $springProgram = Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $spring->id,
            'title' => 'Bahar Atolyesi',
            'start_at' => now()->addMonths(2),
            'end_at' => now()->addMonths(2)->addHours(2),
            'status' => 'scheduled',
        ]);

        ApplicationForm::query()->create([
            'project_id' => $project->id,
            'period_id' => $fall->id,
            'fields' => [
                ['id' => 'period_question', 'type' => 'text', 'label' => 'Donem sorusu', 'required' => true],
            ],
            'is_active' => true,
        ]);

        $this->putJson('/api/panel/projects/'.$project->id.'/application-form', [
            'period_id' => $fall->id,
            'program_id' => $program->id,
            'fields' => [
                ['id' => 'program_question', 'type' => 'text', 'label' => 'Program sorusu', 'required' => true],
            ],
            'require_consent' => false,
            'is_active' => true,
        ])->assertOk()
            ->assertJsonPath('application_form.program_id', $program->id)
            ->assertJsonPath('application_form.period_id', $fall->id);

        $this->getJson('/api/panel/projects/'.$project->id.'/application-form?period_id='.$fall->id)
            ->assertOk()
            ->assertJsonPath('application_form.program_id', null)
            ->assertJsonPath('application_form.fields.0.id', 'period_question');

        $this->getJson('/api/panel/projects/'.$project->id.'/application-form?period_id='.$fall->id.'&program_id='.$program->id)
            ->assertOk()
            ->assertJsonPath('application_form.program_id', $program->id)
            ->assertJsonPath('application_form.fields.0.id', 'program_question')
            ->assertJsonCount(2, 'programs');

        $this->putJson('/api/panel/projects/'.$project->id.'/application-form', [
            'period_id' => $fall->id,
            'program_id' => $springProgram->id,
            'fields' => [
                ['id' => 'invalid_question', 'type' => 'text', 'label' => 'Hatali soru', 'required' => true],
            ],
            'is_active' => true,
        ])->assertStatus(422);
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

        $this->getJson('/api/projects/'.$project->slug)
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
                'motivation' => 'KatÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚ÂÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â±lmak istiyorum.',
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

    public function test_blacklisted_user_cannot_submit_public_application(): void
    {
        $project = $this->project();
        $project->update(['application_open' => true]);
        Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Basvuru',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        User::factory()->create([
            'name' => 'Blocked',
            'surname' => 'Applicant',
            'email' => 'blocked-applicant@test.local',
            'role' => 'student',
            'status' => 'blacklisted',
            'blacklisted_until' => now()->addMonth(),
        ]);

        $this->postJson('/api/applications/public', [
            'project_id' => $project->id,
            'form_data' => [],
            'applicant' => [
                'name' => 'Blocked',
                'surname' => 'Applicant',
                'email' => 'blocked-applicant@test.local',
                'phone' => '05550000001',
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('user');

        $this->assertDatabaseCount('applications', 0);
    }

    public function test_public_application_is_waitlisted_when_project_period_quota_is_full(): void
    {
        $project = $this->project();
        $project->update([
            'application_open' => true,
            'quota' => 1,
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Basvuru',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $acceptedUser = User::factory()->create([
            'surname' => 'Accepted',
            'role' => 'student',
        ]);
        Participant::query()->create([
            'user_id' => $acceptedUser->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
            'credit' => 100,
        ]);

        $this->postJson('/api/applications/public', [
            'project_id' => $project->id,
            'form_data' => [],
            'applicant' => [
                'name' => 'Wait',
                'surname' => 'Listed',
                'email' => 'waitlisted-applicant@test.local',
                'phone' => '05550000002',
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('application.status', 'waitlisted');

        $this->assertDatabaseHas('applications', [
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'waitlisted',
        ]);
    }

    public function test_accepting_application_is_blocked_when_project_period_quota_is_full(): void
    {
        $this->actingSuperAdmin();
        $project = $this->project();
        $project->update([
            'quota' => 1,
            'has_interview' => false,
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Basvuru',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $acceptedUser = User::factory()->create([
            'surname' => 'Accepted',
            'role' => 'student',
        ]);
        $applicant = User::factory()->create([
            'surname' => 'Applicant',
            'role' => 'student',
        ]);
        Participant::query()->create([
            'user_id' => $acceptedUser->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
            'credit' => 100,
        ]);
        $application = Application::query()->create([
            'user_id' => $applicant->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'pending',
        ]);

        $this->putJson('/api/panel/applications/'.$application->id.'/status', [
            'status' => 'accepted',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseMissing('participants', [
            'user_id' => $applicant->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
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

        $this->putJson('/api/panel/applications/'.$application->id.'/status', [
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
        $completedPeriod = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2025 Alumni Archive',
            'start_date' => now()->subMonths(8)->toDateString(),
            'end_date' => now()->subMonths(6)->toDateString(),
            'status' => 'completed',
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
            'period_id' => $completedPeriod->id,
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

        $this->getJson('/api/panel/participants?project_id='.$project->id)
            ->assertOk()
            ->assertJsonCount(1, 'participants')
            ->assertJsonPath('participants.0.graduation_status', 'graduated')
            ->assertJsonPath('summary.graduates', 1);

        $this->getJson('/api/panel/participants?project_id='.$project->id.'&period_id='.$completedPeriod->id)
            ->assertOk()
            ->assertJsonCount(1, 'participants')
            ->assertJsonPath('participants.0.period.id', $completedPeriod->id);
    }

    public function test_panel_participant_list_omits_heavy_cv_payload_and_cv_detail_loads_on_demand(): void
    {
        $this->actingSuperAdmin();
        $project = $this->project();
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 CV',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $student = User::factory()->create([
            'name' => 'CV',
            'surname' => 'Student',
            'role' => 'student',
        ]);
        $participant = Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
            'credit' => 100,
        ]);
        UserProfile::query()->create([
            'user_id' => $student->id,
            'digital_cv_data' => [
                'summary' => str_repeat('A', 5000),
                'education' => [['school' => 'Test University']],
            ],
            'linkedin_url' => 'https://linkedin.example/cv-student',
        ]);

        $this->getJson('/api/panel/participants?project_id='.$project->id)
            ->assertOk()
            ->assertJsonPath('participants.0.user.cv.has_digital_cv', true)
            ->assertJsonPath('participants.0.user.cv.linkedin_url', 'https://linkedin.example/cv-student')
            ->assertJsonMissingPath('participants.0.user.cv.digital_cv_data');

        $this->getJson("/api/panel/participants/{$participant->id}/cv")
            ->assertOk()
            ->assertJsonPath('participant.user.cv.digital_cv_data.education.0.school', 'Test University')
            ->assertJsonPath('participant.user.cv.linkedin_url', 'https://linkedin.example/cv-student');
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

        $eurodeskProjectId = $this->postJson("/api/panel/projects/{$project->id}/special-modules/eurodesk-projects", [
            'title' => 'Genclik Hibesi',
            'partner_organizations' => ['Ortak Kurum'],
            'grant_amount' => 1000,
            'grant_status' => 'approved',
        ])->assertCreated()->json('eurodesk_project.id');

        $this->postJson("/api/panel/projects/{$project->id}/special-modules/eurodesk-projects/{$eurodeskProjectId}/partnerships", [
            'organization_name' => 'Ortak Kurum',
            'country' => 'Turkiye',
            'contact_info' => 'Genclik ofisi',
        ])->assertCreated();

        $rewardTierId = $this->postJson("/api/panel/projects/{$project->id}/special-modules/reward-tiers", [
            'name' => 'Kademe Plus 1',
            'min_badges' => 2,
            'min_credits' => 50,
            'reward_description' => 'Hediye Seti',
        ])->assertCreated()->json('reward_tier.id');

        $rewardAwardId = $this->postJson("/api/panel/projects/{$project->id}/special-modules/reward-awards", [
            'participant_id' => $participant->id,
            'reward_tier_id' => $rewardTierId,
            'reward_name' => 'Hediye Seti',
            'status' => 'given',
        ])->assertCreated()
            ->assertJsonPath('reward_award.reward_name', 'Hediye Seti')
            ->json('reward_award.id');

        $this->patchJson("/api/panel/projects/{$project->id}/special-modules/reward-awards/{$rewardAwardId}/deliver")
            ->assertOk()
            ->assertJsonPath('award.status', 'delivered')
            ->assertJsonPath('award.deliverer', 'Panel Admin');

        $this->getJson("/api/panel/projects/{$project->id}/special-modules")
            ->assertOk()
            ->assertJsonCount(1, 'internships')
            ->assertJsonCount(1, 'mentors')
            ->assertJsonCount(1, 'eurodesk_projects')
            ->assertJsonPath('eurodesk_summary.total_projects', 1)
            ->assertJsonPath('eurodesk_summary.approved_projects', 1)
            ->assertJsonPath('eurodesk_summary.approved_grant_amount', 1000)
            ->assertJsonPath('eurodesk_summary.partnership_count', 1)
            ->assertJsonPath('eurodesk_summary.countries.0', 'Turkiye')
            ->assertJsonCount(1, 'reward_tiers')
            ->assertJsonCount(1, 'reward_awards')
            ->assertJsonPath('reward_awards.0.status', 'delivered')
            ->assertJsonPath('reward_awards.0.deliverer', 'Panel Admin')
            ->assertJsonPath('reward_awards.0.tier.id', $rewardTierId)
            ->assertJsonStructure(['reward_awards' => [['delivered_at']]]);
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

    public function test_public_project_students_require_visibility_flags_and_panel_scope_can_update_them(): void
    {
        $admin = $this->actingSuperAdmin();
        $project = $this->project();
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Public',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $student = User::factory()->create([
            'name' => 'Public',
            'surname' => 'Student',
            'role' => 'student',
            'status' => 'active',
            'university' => 'Test University',
            'department' => 'Test Department',
            'profile_photo_path' => 'profiles/public-student.jpg',
        ]);
        $participant = Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
            'credit' => 100,
        ]);

        $this->getJson("/api/projects/{$project->slug}")
            ->assertOk()
            ->assertJsonCount(0, 'project.active_students');

        $this->patchJson("/api/panel/participants/{$participant->id}/public-visibility", [
            'public_profile_visible' => true,
            'public_photo_visible' => false,
            'public_alumni_visible' => false,
        ])
            ->assertOk()
            ->assertJsonPath('participant.user.public_profile_visible', true)
            ->assertJsonPath('participant.user.public_photo_visible', false);

        $this->getJson("/api/projects/{$project->slug}")
            ->assertOk()
            ->assertJsonCount(1, 'project.active_students')
            ->assertJsonPath('project.active_students.0.name', 'Public Student')
            ->assertJsonPath('project.active_students.0.image', null);

        $this->patchJson("/api/panel/participants/{$participant->id}/public-visibility", [
            'public_photo_visible' => true,
        ])->assertOk();

        $this->getJson("/api/projects/{$project->slug}")
            ->assertOk()
            ->assertJsonPath('project.active_students.0.image', fn ($value) => is_string($value) && $value !== '');

        $this->assertSame($admin->id, auth()->id());
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
            'tc_verified' => true,
            'yok_verified' => false,
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

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.tc_verified', true)
            ->assertJsonPath('user.yok_verified', false);
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

        Internship::query()->create([
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

        RewardTier::query()->create([
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

        $opportunity = VolunteerOpportunity::query()->create([
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

    public function test_qr_attendance_feedback_block_points_to_existing_evaluate_page(): void
    {
        $project = $this->project();
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Bahar',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $student = User::factory()->create([
            'surname' => 'Feedback',
            'role' => 'student',
            'status' => 'active',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');
        Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'credit' => 90,
            'status' => 'active',
        ]);

        $previousProgram = Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Onceki Oturum',
            'start_at' => now()->subDays(2),
            'end_at' => now()->subDays(2)->addHour(),
            'credit_deduction' => 10,
            'radius_meters' => 100,
            'status' => 'completed',
            'created_by' => $student->id,
        ]);
        Attendance::query()->create([
            'program_id' => $previousProgram->id,
            'user_id' => $student->id,
            'method' => 'qr',
            'is_valid' => true,
        ]);
        $currentProgram = Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Yeni Oturum',
            'start_at' => now()->subMinute(),
            'end_at' => now()->addHour(),
            'credit_deduction' => 10,
            'radius_meters' => 100,
            'status' => 'active',
            'qr_token' => 'qr-feedback-block',
            'qr_expires_at' => now()->addMinutes(5),
            'created_by' => $student->id,
        ]);

        Sanctum::actingAs($student);

        $this->postJson('/api/attendances/qr', [
            'qr_token' => $currentProgram->qr_token,
        ])
            ->assertStatus(423)
            ->assertJsonPath('requires_feedback', true)
            ->assertJsonPath('program_id', $previousProgram->id)
            ->assertJsonPath('redirect_to', '/student/evaluate');
    }

    public function test_student_feedback_uses_program_dynamic_template_and_restores_credit(): void
    {
        $project = $this->project();
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Dinamik Form',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $student = User::factory()->create([
            'surname' => 'DynamicFeedback',
            'role' => 'student',
            'status' => 'active',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');
        $participant = Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'credit' => 90,
            'status' => 'active',
        ]);
        $template = FeedbackFormTemplate::query()->create([
            'project_id' => $project->id,
            'name' => 'Dinamik Degerlendirme',
            'is_default' => true,
            'is_active' => true,
        ]);
        FeedbackFormQuestion::query()->create([
            'feedback_form_template_id' => $template->id,
            'question_key' => 'session_value',
            'label' => 'Oturum sana ne kadar deger katti?',
            'type' => 'rating',
            'min_value' => 1,
            'max_value' => 7,
            'is_required' => true,
            'sort_order' => 1,
        ]);
        FeedbackFormQuestion::query()->create([
            'feedback_form_template_id' => $template->id,
            'question_key' => 'open_note',
            'label' => 'Kisa not',
            'type' => 'text',
            'is_required' => false,
            'sort_order' => 2,
        ]);
        FeedbackFormQuestion::query()->create([
            'feedback_form_template_id' => $template->id,
            'question_key' => 'format_choice',
            'label' => 'Oturum formati uygun muydu?',
            'type' => 'choice',
            'options' => ['Evet', 'Kismen', 'Hayir'],
            'is_required' => true,
            'sort_order' => 3,
        ]);
        $program = Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'feedback_form_template_id' => $template->id,
            'title' => 'Dinamik Form Oturumu',
            'start_at' => now()->subDay(),
            'end_at' => now()->subDay()->addHour(),
            'credit_deduction' => 10,
            'status' => 'completed',
        ]);
        Attendance::query()->create([
            'program_id' => $program->id,
            'user_id' => $student->id,
            'method' => 'qr',
            'is_valid' => true,
        ]);
        CreditLog::query()->create([
            'participant_id' => $participant->id,
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'program_id' => $program->id,
            'amount' => -10,
            'type' => 'deduction',
            'reason' => 'Etkinlik tamamlandi, degerlendirme bekleniyor',
            'created_by' => $student->id,
        ]);

        Sanctum::actingAs($student);

        $this->getJson('/api/feedbacks')
            ->assertOk()
            ->assertJsonPath('programs.0.questions.0.id', 'session_value')
            ->assertJsonPath('programs.0.questions.0.max', 7)
            ->assertJsonPath('programs.0.questions.2.type', 'choice')
            ->assertJsonPath('programs.0.questions.2.options.1', 'Kismen');

        $this->postJson('/api/feedbacks', [
            'program_id' => $program->id,
            'responses' => [
                'session_value' => 6,
                'open_note' => 'Faydaliydi',
                'format_choice' => 'Kismen',
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('current_credit', 100);

        $this->assertDatabaseHas('feedbacks', [
            'program_id' => $program->id,
        ]);
        $this->assertSame(100, (int) $participant->fresh()->credit);
    }

    public function test_panel_can_create_feedback_template_and_assign_it_to_program(): void
    {
        $this->actingSuperAdmin();
        $project = $this->project();
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Panel Form',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        $templateId = $this->postJson('/api/panel/feedback-form-templates', [
            'project_id' => $project->id,
            'name' => 'Panelden Acilan Form',
            'description' => 'Regression form sablonu',
            'is_default' => true,
            'questions' => [
                [
                    'question_key' => 'panel_rating',
                    'label' => 'Panel rating',
                    'type' => 'rating',
                    'min_value' => 1,
                    'max_value' => 5,
                    'is_required' => true,
                ],
                [
                    'question_key' => 'panel_comment',
                    'label' => 'Panel yorum',
                    'type' => 'text',
                    'is_required' => false,
                ],
                [
                    'question_key' => 'panel_choice',
                    'label' => 'Panel secim',
                    'type' => 'choice',
                    'options' => ['Evet', 'Hayir'],
                    'is_required' => true,
                ],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('template.questions.0.question_key', 'panel_rating')
            ->assertJsonPath('template.questions.2.options.0', 'Evet')
            ->json('template.id');

        $this->postJson('/api/panel/programs', [
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Sablonlu Program',
            'description' => null,
            'location' => 'Test salonu',
            'latitude' => null,
            'longitude' => null,
            'radius_meters' => 100,
            'start_at' => now()->addDay()->setTime(10, 0)->toIso8601String(),
            'end_at' => now()->addDay()->setTime(11, 0)->toIso8601String(),
            'credit_deduction' => 10,
            'application_quota' => null,
            'feedback_form_template_id' => $templateId,
            'is_public' => true,
            'is_featured' => false,
        ])
            ->assertCreated()
            ->assertJsonPath('program.feedback_form_template_id', $templateId);

        $this->assertDatabaseHas('programs', [
            'title' => 'Sablonlu Program',
            'feedback_form_template_id' => $templateId,
        ]);
    }

    public function test_panel_feedback_summary_aggregates_numeric_choice_and_text_answers(): void
    {
        $this->actingSuperAdmin();
        $project = $this->project();
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Ozet Anket',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $template = FeedbackFormTemplate::query()->create([
            'project_id' => $project->id,
            'name' => 'Ozet Form',
            'is_active' => true,
        ]);
        FeedbackFormQuestion::query()->create([
            'feedback_form_template_id' => $template->id,
            'question_key' => 'session_value',
            'label' => 'Oturum puani',
            'type' => 'rating',
            'min_value' => 1,
            'max_value' => 7,
            'is_required' => true,
            'sort_order' => 1,
        ]);
        FeedbackFormQuestion::query()->create([
            'feedback_form_template_id' => $template->id,
            'question_key' => 'format_choice',
            'label' => 'Format',
            'type' => 'choice',
            'options' => ['Evet', 'Kismen', 'Hayir'],
            'is_required' => true,
            'sort_order' => 2,
        ]);
        FeedbackFormQuestion::query()->create([
            'feedback_form_template_id' => $template->id,
            'question_key' => 'comment',
            'label' => 'Yorum',
            'type' => 'text',
            'is_required' => false,
            'sort_order' => 3,
        ]);
        $program = Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'feedback_form_template_id' => $template->id,
            'title' => 'Ozet Program',
            'start_at' => now()->subDay(),
            'end_at' => now()->subDay()->addHour(),
            'status' => 'completed',
            'credit_deduction' => 10,
        ]);

        Feedback::query()->create([
            'program_id' => $program->id,
            'anonymous_token' => 'summary-token-1',
            'responses' => [
                'session_value' => 6,
                'format_choice' => 'Evet',
                'comment' => 'Cok iyiydi.',
            ],
            'submitted_at' => now(),
        ]);
        Feedback::query()->create([
            'program_id' => $program->id,
            'anonymous_token' => 'summary-token-2',
            'responses' => [
                'session_value' => 4,
                'format_choice' => 'Kismen',
                'comment' => '',
            ],
            'submitted_at' => now()->subMinute(),
        ]);

        $response = $this->getJson('/api/panel/programs/feedback-summary?project_id='.$project->id.'&period_id='.$period->id)
            ->assertOk()
            ->assertJsonPath('summary.program_count', 1)
            ->assertJsonPath('summary.total_feedback', 2)
            ->assertJsonPath('summary.with_comment', 1)
            ->assertJsonPath('question_stats.session_value.type', 'rating')
            ->assertJsonPath('question_stats.session_value.average', 5)
            ->assertJsonPath('question_stats.format_choice.type', 'choice')
            ->assertJsonPath('question_stats.format_choice.distribution.Evet', 1)
            ->assertJsonPath('question_stats.format_choice.distribution.Kismen', 1)
            ->assertJsonPath('project_breakdown.0.name', $project->name)
            ->assertJsonPath('project_breakdown.0.feedback_count', 2)
            ->assertJsonPath('period_breakdown.0.name', $period->name)
            ->assertJsonPath('period_breakdown.0.feedback_count', 2)
            ->assertJsonPath('recent_comments.0.comment', 'Cok iyiydi.');

        $this->assertSame('Ozet Program', $response->json('programs.0.title'));

        $this->getJson('/api/panel/programs/feedback-summary/export?project_id='.$project->id.'&period_id='.$period->id)
            ->assertOk();

        Feedback::query()->create([
            'program_id' => $program->id,
            'anonymous_token' => 'summary-token-3',
            'responses' => [
                'session_value' => 'sayisal olmayan not',
                'format_choice' => 'Hayir',
                'comment' => 'Puan yerine metin geldi.',
            ],
            'submitted_at' => now()->subMinutes(2),
        ]);

        $statsResponse = $this->getJson("/api/panel/programs/{$program->id}/feedback-stats")
            ->assertOk()
            ->assertJsonPath('summary.total_feedback', 3)
            ->assertJsonPath('summary.rating_question_count', 1)
            ->assertJsonPath('summary.choice_question_count', 1)
            ->assertJsonPath('summary.text_question_count', 1)
            ->assertJsonPath('summary.text_response_count', 2)
            ->assertJsonPath('summary.anonymous', true)
            ->assertJsonPath('summary.identity_redacted', true)
            ->assertJsonPath('question_stats.session_value.count', 2)
            ->assertJsonPath('question_stats.session_value.average', 5)
            ->assertJsonPath('question_stats.format_choice.distribution.Hayir', 1)
            ->assertJsonPath('text_responses.0.question', 'Yorum')
            ->assertJsonPath('text_responses.0.answer', 'Cok iyiydi.');

        $this->assertNotEmpty($statsResponse->json('text_responses.0.anonymous_report_id'));

        $this->get('/api/panel/programs/'.$program->id.'/feedback-stats/export?format=csv')
            ->assertOk();
    }

    public function test_student_personality_test_uses_active_dynamic_template(): void
    {
        $student = User::factory()->create([
            'surname' => 'Personality',
            'role' => 'student',
            'status' => 'active',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');

        $template = PersonalityTestTemplate::query()->create([
            'name' => 'Dinamik Kisilik Analizi',
            'description' => 'Regression test sablonu',
            'is_active' => true,
        ]);
        PersonalityTestQuestion::query()->create([
            'personality_test_template_id' => $template->id,
            'question_key' => 'strategic_focus',
            'category' => 'strategy',
            'text' => 'Stratejik hedefleri parcalara ayiririm.',
            'sort_order' => 1,
        ]);
        PersonalityTestQuestion::query()->create([
            'personality_test_template_id' => $template->id,
            'question_key' => 'team_energy',
            'category' => 'teamwork',
            'text' => 'Ekip motivasyonunu yuksek tutmaya calisirim.',
            'sort_order' => 2,
        ]);
        PersonalityTestResultRange::query()->create([
            'personality_test_template_id' => $template->id,
            'category' => 'strategy',
            'summary' => 'Strateji odagin one cikiyor.',
        ]);

        Sanctum::actingAs($student);

        $this->getJson('/api/user/personality-test')
            ->assertOk()
            ->assertJsonPath('template_id', $template->id)
            ->assertJsonPath('questions.0.id', 'strategic_focus');

        $this->postJson('/api/user/personality-test', [
            'answers' => [
                'strategic_focus' => 5,
                'team_energy' => 3,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('result.template_id', $template->id)
            ->assertJsonPath('result.top_category', 'strategy')
            ->assertJsonPath('result.summary', 'Strateji odagin one cikiyor.');

        $profileData = $student->fresh()->profile?->personality_test_data;
        $this->assertSame($template->id, $profileData['template_id'] ?? null);
        $this->assertSame(5, $profileData['answers']['strategic_focus'] ?? null);
    }

    public function test_panel_can_manage_and_activate_personality_test_template(): void
    {
        $this->actingSuperAdmin();

        $firstId = $this->postJson('/api/panel/personality-test-templates', [
            'name' => 'Panel Kisilik Analizi',
            'description' => 'Panelden yonetilen test',
            'is_active' => false,
            'questions' => [
                [
                    'question_key' => 'planning',
                    'category' => 'execution',
                    'text' => 'Planlari takip ederim.',
                    'sort_order' => 1,
                ],
            ],
            'result_ranges' => [
                [
                    'category' => 'execution',
                    'summary' => 'Uygulama disiplinin one cikiyor.',
                ],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('template.name', 'Panel Kisilik Analizi')
            ->json('template.id');

        $activeId = $this->postJson('/api/panel/personality-test-templates', [
            'name' => 'Aktif Panel Kisilik Analizi',
            'description' => null,
            'is_active' => true,
            'questions' => [
                [
                    'question_key' => 'empathy_focus',
                    'category' => 'social',
                    'text' => 'Gorusmelerde karsi tarafi dikkatle dinlerim.',
                    'sort_order' => 1,
                ],
            ],
            'result_ranges' => [
                [
                    'category' => 'social',
                    'summary' => 'Iletisim tarafin guclu gorunuyor.',
                ],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('template.is_active', true)
            ->json('template.id');

        $this->assertDatabaseHas('personality_test_templates', [
            'id' => $firstId,
            'is_active' => false,
        ]);

        $this->putJson("/api/panel/personality-test-templates/{$firstId}", [
            'name' => 'Panel Kisilik Analizi Guncel',
            'description' => 'Guncellenen test',
            'is_active' => false,
            'questions' => [
                [
                    'question_key' => 'planning',
                    'category' => 'execution',
                    'text' => 'Planlari takip ederim.',
                    'sort_order' => 1,
                ],
                [
                    'question_key' => 'focus',
                    'category' => 'execution',
                    'text' => 'Uzun sure odakli calisabilirim.',
                    'sort_order' => 2,
                ],
            ],
            'result_ranges' => [
                [
                    'category' => 'execution',
                    'summary' => 'Uygulama disiplinin one cikiyor.',
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonCount(2, 'template.questions');

        $this->postJson("/api/panel/personality-test-templates/{$firstId}/activate")
            ->assertOk()
            ->assertJsonPath('template.is_active', true);

        $this->assertDatabaseHas('personality_test_templates', [
            'id' => $activeId,
            'is_active' => false,
        ]);

        $student = User::factory()->create([
            'surname' => 'Template',
            'role' => 'student',
            'status' => 'active',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');
        Sanctum::actingAs($student);

        $this->getJson('/api/user/personality-test')
            ->assertOk()
            ->assertJsonPath('template_id', $firstId)
            ->assertJsonPath('questions.1.id', 'focus');
    }

    public function test_student_digital_cv_draft_is_persisted_for_panel_cv_view(): void
    {
        $project = $this->project();
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => 'CV Donemi',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        $student = User::factory()->create([
            'name' => 'Digital',
            'surname' => 'Cv',
            'role' => 'student',
            'status' => 'active',
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
            'graduation_status' => null,
        ]);

        Sanctum::actingAs($student);

        $this->putJson('/api/dashboard/digital-cv', [
            'form' => [
                'fullName' => 'Digital Cv',
                'email' => 'digital-cv@test.local',
                'summary' => 'Panelde gorunmesi gereken kalici CV ozeti.',
                'skills' => 'Liderlik, Analiz',
                'experience' => [
                    [
                        'id' => 'exp-1',
                        'title' => 'Gonullu',
                        'subtitle' => 'KADEME',
                        'date' => '2026',
                        'description' => 'Etkinlik operasyon destegi.',
                    ],
                ],
                'education' => [],
                'projects' => [],
                'certificates' => [],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('saved_draft.form.summary', 'Panelde gorunmesi gereken kalici CV ozeti.');

        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $student->id,
        ]);

        $this->actingSuperAdmin();

        $this->getJson("/api/panel/participants/{$participant->id}/cv")
            ->assertOk()
            ->assertJsonPath('participant.user.cv.digital_cv_data.form.skills', 'Liderlik, Analiz');
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
            'period_id' => $period->id,
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
            'period_id' => $period->id,
            'room_id' => $room->id,
            'status' => 'scheduled',
        ]);

        $this->getJson('/api/panel/kpd/appointments?period_id='.$period->id)
            ->assertOk()
            ->assertJsonPath('room_schedule.0.id', $room->id)
            ->assertJsonPath('room_schedule.0.appointment_count', 1)
            ->assertJsonPath('room_schedule.0.appointments.0.counselee.id', $student->id)
            ->assertJsonPath('room_schedule.0.appointments.0.period.id', $period->id);
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

        $appointment = KpdAppointment::query()->create([
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

    public function test_user_can_create_kvkk_forget_request_once(): void
    {
        $student = User::factory()->create([
            'surname' => 'Forget',
            'role' => 'student',
            'status' => 'active',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');
        Sanctum::actingAs($student);

        $this->postJson('/api/user/kvkk/forget-request', [
            'request_note' => 'Hesabimin anonimlestirilmesini talep ediyorum.',
        ])
            ->assertCreated()
            ->assertJsonPath('forget_request.status', 'pending');

        $this->postJson('/api/user/kvkk/forget-request')->assertStatus(422);

        $this->assertDatabaseHas('kvkk_forget_requests', [
            'user_id' => $student->id,
            'status' => 'pending',
        ]);
    }

    public function test_panel_can_approve_kvkk_forget_and_anonymize_user(): void
    {
        $admin = $this->actingSuperAdmin();
        $student = User::factory()->create([
            'name' => 'Ayse',
            'surname' => 'Yilmaz',
            'email' => 'kvkk-anon@test.local',
            'phone' => '5551112233',
            'role' => 'student',
            'status' => 'active',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');

        $requestItem = KvkkForgetRequest::query()->create([
            'user_id' => $student->id,
            'status' => 'pending',
            'request_note' => 'Unutulma hakki talebi',
        ]);

        Sanctum::actingAs($admin);
        $this->postJson("/api/panel/kvkk/forget-requests/{$requestItem->id}/resolve", [
            'decision' => 'approve',
            'reviewer_note' => 'KVKK talebi uygun bulundu.',
        ])
            ->assertOk()
            ->assertJsonPath('forget_request.status', 'completed')
            ->assertJsonPath('forget_request.user.kvkk_forgotten', true);

        $this->assertDatabaseHas('kvkk_forget_requests', [
            'id' => $requestItem->id,
            'status' => 'completed',
            'reviewed_by' => $admin->id,
        ]);

        $updatedStudent = User::query()->findOrFail($student->id);
        $this->assertTrue((bool) $updatedStudent->kvkk_forgotten);
        $this->assertNull($updatedStudent->phone);
        $this->assertStringContainsString('@anon.local', (string) $updatedStudent->email);
    }
}

