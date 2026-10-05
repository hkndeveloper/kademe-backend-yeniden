<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Certificate;
use App\Models\KpdReport;
use App\Models\Participant;
use App\Models\Period;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\KpdReportStorage;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Spatie\Permission\Models\Role;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class PhaseZeroSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function projectAndPeriod(): array
    {
        $project = Project::query()->create([
            'name' => 'Phase Zero Project',
            'slug' => 'phase-zero-project',
            'type' => 'other',
            'status' => 'active',
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Phase Zero',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        return [$project, $period];
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'surname' => 'Safety', 'kvkk_consent_at' => now()]);
        Role::findOrCreate($role, 'web');
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_general_program_form_cannot_complete_or_reopen_program(): void
    {
        [$project, $period] = $this->projectAndPeriod();
        $this->actor('super_admin');
        $fields = [
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Oturum',
            'start_at' => now()->subHours(2)->toIso8601String(),
            'end_at' => now()->subHour()->toIso8601String(),
        ];

        $this->postJson('/api/panel/programs', $fields + ['status' => 'completed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $program = Program::query()->create($fields + ['status' => 'scheduled']);
        $this->putJson("/api/panel/programs/{$program->id}", ['status' => 'completed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->assertSame('scheduled', $program->fresh()->status);

        $program->update(['status' => 'completed']);
        $this->putJson("/api/panel/programs/{$program->id}", ['status' => 'active'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->putJson("/api/panel/programs/{$program->id}", ['credit_deduction' => 100])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('credit_deduction');
        $this->assertSame('completed', $program->fresh()->status);
    }

    public function test_expired_feedback_does_not_lock_all_future_qr_attendance(): void
    {
        [$project, $period] = $this->projectAndPeriod();
        $student = $this->actor('student');
        Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
            'credit' => 90,
        ]);
        $first = Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Ilk oturum',
            'start_at' => now()->subDays(2),
            'end_at' => now()->subDays(2)->addHour(),
            'status' => 'completed',
        ]);
        Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Ikinci oturum',
            'start_at' => now()->subDay(),
            'end_at' => now()->subDay()->addHour(),
            'status' => 'completed',
        ]);
        $third = Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Ucuncu oturum',
            'start_at' => now()->subMinutes(10),
            'end_at' => now()->addHour(),
            'status' => 'active',
            'qr_token' => 'phase-zero-token',
            'qr_expires_at' => now()->addMinutes(10),
            'latitude' => 41.0082,
            'longitude' => 28.9784,
        ]);
        Attendance::query()->create([
            'program_id' => $first->id,
            'user_id' => $student->id,
            'method' => 'qr',
            'is_valid' => true,
        ]);

        $this->postJson('/api/attendances/qr', [
            'qr_token' => 'phase-zero-token',
            'latitude' => 41.0082,
            'longitude' => 28.9784,
        ])
            ->assertOk();
        $this->assertDatabaseHas('attendances', [
            'program_id' => $third->id,
            'user_id' => $student->id,
            'is_valid' => true,
        ]);
    }

    public function test_qr_without_expiration_is_rejected_while_valid_token_remains_single_use_per_student(): void
    {
        [$project, $period] = $this->projectAndPeriod();
        $student = $this->actor('student');
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
            'title' => 'Sureli QR',
            'start_at' => now()->subMinutes(10),
            'end_at' => now()->addHour(),
            'status' => 'active',
            'qr_token' => 'expiry-required-token',
            'qr_expires_at' => null,
            'latitude' => 41.0082,
            'longitude' => 28.9784,
            'radius_meters' => 100,
        ]);
        $payload = [
            'qr_token' => 'expiry-required-token',
            'latitude' => 41.0082,
            'longitude' => 28.9784,
        ];

        $this->postJson('/api/attendances/qr', $payload)->assertStatus(400);
        $this->assertDatabaseMissing('attendances', ['program_id' => $program->id, 'user_id' => $student->id]);
        $expiredAudit = Activity::query()->where('event', 'attendance.qr.attempt')->latest('id')->firstOrFail();
        $this->assertSame($program->id, $expiredAudit->subject_id);
        $this->assertSame(400, $expiredAudit->properties->get('status_code'));
        $this->assertArrayNotHasKey('qr_token', $expiredAudit->properties->get('domain'));

        $program->update(['qr_expires_at' => now()->subSecond()]);
        $this->postJson('/api/attendances/qr', $payload)->assertStatus(400);

        $program->update(['qr_expires_at' => now()->addMinute()]);
        $this->postJson('/api/attendances/qr', array_merge($payload, ['latitude' => 42.0]))->assertStatus(422);
        $outsideAudit = Activity::query()->where('event', 'attendance.qr.attempt')->latest('id')->firstOrFail();
        $this->assertSame('outside_radius', $outsideAudit->properties->get('domain')['location_check']);
        $this->postJson('/api/attendances/qr', $payload)->assertOk();
        $this->postJson('/api/attendances/qr', $payload)->assertOk();
        $this->assertSame(1, Attendance::query()->where('program_id', $program->id)->where('user_id', $student->id)->count());
    }

    public function test_qr_accuracy_is_advisory_and_program_review_counts_repeat_outside_attempts(): void
    {
        [$project, $period] = $this->projectAndPeriod();
        $student = $this->actor('student');
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
            'title' => 'QR gozlem',
            'start_at' => now()->subMinutes(10),
            'end_at' => now()->addHour(),
            'status' => 'active',
            'qr_token' => 'qr-review-token',
            'qr_expires_at' => now()->addMinutes(10),
            'latitude' => 41.0082,
            'longitude' => 28.9784,
            'radius_meters' => 100,
        ]);
        $outside = [
            'qr_token' => 'qr-review-token',
            'latitude' => 42.0,
            'longitude' => 28.9784,
            'accuracy_meters' => 250,
        ];
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson('/api/attendances/qr', $outside)->assertUnprocessable();
        }
        $this->postJson('/api/attendances/qr', [
            'qr_token' => 'qr-review-token',
            'latitude' => 41.0082,
            'longitude' => 28.9784,
            'accuracy_meters' => 250,
        ])->assertOk();
        $this->assertDatabaseHas('attendances', ['program_id' => $program->id, 'user_id' => $student->id, 'is_valid' => true]);
        $successAudit = Activity::query()->where('event', 'attendance.qr.attempt')->latest('id')->firstOrFail();
        $this->assertSame('over_radius', $successAudit->properties->get('domain')['accuracy_bucket']);
        $this->assertSame('inside_radius', $successAudit->properties->get('domain')['location_check']);
        $this->assertArrayNotHasKey('accuracy_meters', $successAudit->properties->get('domain'));

        $this->getJson("/api/panel/programs/{$program->id}/attendances")->assertForbidden();
        $this->actor('super_admin');
        $this->getJson("/api/panel/programs/{$program->id}/attendances")
            ->assertOk()
            ->assertJsonPath('summary.qr_review.outside_radius_attempts', 3)
            ->assertJsonPath('summary.qr_review.repeat_outside_radius_users', 1)
            ->assertJsonPath('summary.qr_review.accuracy_reported_attempts', 4)
            ->assertJsonPath('summary.qr_review.reported_low_accuracy_attempts', 4);
    }

    public function test_student_uploaded_certificate_requires_owner_download_and_is_not_publicly_verified(): void
    {
        Storage::fake(config('filesystems.media_disk', 'public'));
        $student = $this->actor('student');
        Storage::disk(config('filesystems.media_disk', 'public'))->put('certificates/student-uploads/test.pdf', 'PDF');
        $certificate = Certificate::query()->create([
            'user_id' => $student->id,
            'type' => 'achievement',
            'title' => 'Kisisel belge',
            'issuer' => 'Baska kurum',
            'verification_code' => 'PERSONAL2026',
            'certificate_path' => 'certificates/student-uploads/test.pdf',
            'source' => 'student_upload',
            'uploaded_by_user_id' => $student->id,
            'issued_at' => now(),
        ]);

        $this->getJson('/api/certificates/verify/PERSONAL2026')->assertNotFound();
        $this->getJson('/api/certificates/PERSONAL2026/download')->assertNotFound();
        $this->getJson('/api/certificates')
            ->assertOk()
            ->assertJsonPath('certificates.0.verification_code', null)
            ->assertJsonPath('certificates.0.download_url', url("/api/certificates/mine/{$certificate->id}/download"));
        config()->set('filesystems.direct_media_downloads', true);
        $this->get("/api/certificates/mine/{$certificate->id}/download")
            ->assertOk()
            ->assertHeader('content-disposition');

        $this->actor('student');
        $this->get("/api/certificates/mine/{$certificate->id}/download")->assertNotFound();
    }

    public function test_cv_cannot_select_another_users_certificate(): void
    {
        $student = $this->actor('student');
        $other = User::factory()->create(['role' => 'student', 'surname' => 'Other']);
        $certificate = Certificate::query()->create([
            'user_id' => $other->id,
            'type' => 'achievement',
            'verification_code' => 'OTHER2026',
            'certificate_path' => 'certificates/other.pdf',
            'issued_at' => now(),
        ]);

        $this->putJson('/api/dashboard/digital-cv', ['form' => ['certificateIds' => [$certificate->id]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('form.certificateIds.0');
        $this->postJson('/api/dashboard/digital-cv/pdf', ['form' => ['certificateIds' => [$certificate->id]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('form.certificateIds.0');
    }

    public function test_cv_pdf_reads_certificate_from_owner_record_instead_of_client_payload(): void
    {
        $student = $this->actor('student');
        $certificate = Certificate::query()->create([
            'user_id' => $student->id,
            'type' => 'achievement',
            'title' => 'Gercek kisisel belge',
            'issuer' => 'Harici kurum',
            'verification_code' => 'PERSONALCV2026',
            'certificate_path' => 'certificates/student-uploads/cv.pdf',
            'source' => 'student_upload',
            'issued_at' => now(),
        ]);
        $renderer = Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $renderer->shouldReceive('setPaper')->once()->with('a4')->andReturnSelf();
        $renderer->shouldReceive('download')->once()->andReturn(response('PDF'));
        Pdf::shouldReceive('loadView')->once()->withArgs(function ($view, $data) use ($certificate) {
            return $view === 'pdf.digital-cv'
                && ($data['certificates'][0]['title'] ?? null) === $certificate->title
                && array_key_exists('verification_code', $data['certificates'][0] ?? [])
                && $data['certificates'][0]['verification_code'] === null
                && count($data['certificates']) === 1;
        })->andReturn($renderer);

        $this->postJson('/api/dashboard/digital-cv/pdf', [
            'form' => ['fullName' => 'Ogrenci', 'certificateIds' => [$certificate->id]],
            'certificates' => [['title' => 'Sahte KADEME sertifikasi', 'verification_code' => 'FAKE']],
        ])->assertOk();
    }

    public function test_new_kpd_report_uses_private_disk(): void
    {
        Storage::fake('application_private');
        Storage::fake(config('filesystems.media_disk', 'public'));

        $path = KpdReportStorage::put(UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'));

        $this->assertTrue(KpdReportStorage::isPrivate($path));
        Storage::disk('application_private')->assertExists(KpdReportStorage::key($path));
        Storage::disk(config('filesystems.media_disk', 'public'))->assertMissing(KpdReportStorage::key($path));
    }

    public function test_kpd_report_upload_and_download_stay_behind_authorized_private_stream(): void
    {
        Storage::fake('application_private');
        Storage::fake(config('filesystems.media_disk', 'public'));
        $project = Project::query()->create([
            'name' => 'KPD',
            'slug' => 'kpd-phase-zero',
            'type' => 'kpd',
            'status' => 'active',
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => 'KPD 2026',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $student = User::factory()->create(['role' => 'student', 'surname' => 'Counselee', 'kvkk_consent_at' => now()]);
        Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
            'credit' => 100,
        ]);
        $this->mock(NotificationService::class, function ($mock) {
            $mock->shouldReceive('sendEmail')->andReturn(1);
        });
        $this->actor('super_admin');

        $this->postJson('/api/panel/kpd/reports', [
            'user_id' => $student->id,
            'title' => 'Gizli rapor',
            'file' => UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'),
        ])->assertCreated();

        $report = KpdReport::query()->firstOrFail();
        $this->assertTrue(KpdReportStorage::isPrivate($report->file_path));
        Storage::disk('application_private')->assertExists(KpdReportStorage::key($report->file_path));
        Storage::disk(config('filesystems.media_disk', 'public'))->assertMissing(KpdReportStorage::key($report->file_path));
        $this->get("/api/panel/kpd/reports/{$report->id}/download")->assertOk();

        Role::findOrCreate('student', 'web');
        $student->assignRole('student');
        Sanctum::actingAs($student);
        $this->get("/api/kpd/reports/{$report->id}/download")->assertOk();
    }

    public function test_legacy_kpd_report_migration_is_dry_run_by_default_and_removes_public_copy_on_apply(): void
    {
        Storage::fake('application_private');
        Storage::fake(config('filesystems.media_disk', 'public'));
        $user = User::factory()->create(['surname' => 'Kpd']);
        $path = 'kpd-reports/legacy.pdf';
        Storage::disk(config('filesystems.media_disk', 'public'))->put($path, 'private report');
        $report = KpdReport::query()->create([
            'user_id' => $user->id,
            'counselor_id' => $user->id,
            'title' => 'Legacy',
            'file_path' => $path,
        ]);

        $this->artisan('kpd:privatize-reports')->assertSuccessful();
        $this->assertSame($path, $report->fresh()->file_path);
        Storage::disk(config('filesystems.media_disk', 'public'))->assertExists($path);

        $this->artisan('kpd:privatize-reports --apply')->assertSuccessful();
        $privatePath = $report->fresh()->file_path;
        $this->assertTrue(KpdReportStorage::isPrivate($privatePath));
        Storage::disk('application_private')->assertExists(KpdReportStorage::key($privatePath));
        Storage::disk(config('filesystems.media_disk', 'public'))->assertMissing($path);
    }
}
