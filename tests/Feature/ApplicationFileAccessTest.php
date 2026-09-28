<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationForm;
use App\Models\Period;
use App\Models\Project;
use App\Models\RolePermissionScope;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\ApplicationFileStorage;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApplicationFileAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['filesystems.media_disk' => 'public']);
        Storage::fake('public');
        Storage::fake('application_private');
        $this->seed(RolePermissionSeeder::class);
        $this->mock(NotificationService::class, function ($mock) {
            $mock->shouldReceive('sendTemplatedEmail')->andReturn(1)->byDefault();
        });
    }

    public function test_uploaded_file_is_private_and_only_the_applicant_can_use_the_student_download(): void
    {
        [$project] = $this->scope('private-file');
        $owner = $this->user();
        Sanctum::actingAs($owner);

        $response = $this->post('/api/applications', [
            'project_id' => $project->id,
            'consent_accepted' => true,
            'form_files' => ['doc' => UploadedFile::fake()->create('cv.pdf', 5, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertCreated();
        $id = $response->json('application.id');
        $file = Application::query()->findOrFail($id)->form_data['doc'];

        $this->assertSame('application_private', $file['storage']);
        $this->assertArrayNotHasKey('url', $file);
        $this->assertArrayNotHasKey('path', $response->json('application.form_data.doc'));
        Storage::disk('application_private')->assertExists($file['path']);
        $this->assertSame([], Storage::disk('public')->allFiles('application-files'));

        $this->getJson('/api/applications')->assertOk()
            ->assertJsonPath('applications.0.form_entries.0.file.download_url', "/applications/{$id}/form-files/doc")
            ->assertJsonMissingPath('applications.0.form_entries.0.file.path');
        $this->get("/api/applications/{$id}/form-files/doc")->assertOk()->assertDownload('cv.pdf');

        Sanctum::actingAs($this->user());
        $this->get("/api/applications/{$id}/form-files/doc")->assertNotFound();
    }

    public function test_panel_download_requires_project_permission_even_if_the_file_url_is_known(): void
    {
        [$project, $period, $form] = $this->scope('managed-file');
        [$otherProject] = $this->scope('outside-file');
        Storage::disk('application_private')->put('application-files/managed.pdf', 'private content');
        $application = $this->application($project, $period, $form, [
            'path' => 'application-files/managed.pdf',
            'storage' => 'application_private',
            'original_name' => 'managed.pdf',
        ]);

        Sanctum::actingAs($this->user());
        $this->get("/api/panel/applications/{$application->id}/form-files/doc")->assertForbidden();

        $coordinator = $this->coordinatorFor($otherProject);
        Sanctum::actingAs($coordinator);
        $this->get("/api/panel/applications/{$application->id}/form-files/doc")->assertForbidden();

        $managedCoordinator = $this->coordinatorFor($project);
        Sanctum::actingAs($managedCoordinator);
        $this->get("/api/panel/applications/{$application->id}/form-files/doc")
            ->assertOk()->assertDownload('managed.pdf');
    }

    public function test_legacy_file_still_streams_through_authorized_routes_without_returning_public_url(): void
    {
        [$project, $period, $form] = $this->scope('legacy-file');
        $owner = $this->user();
        Storage::disk('public')->put('application-files/legacy.pdf', 'legacy content');
        $application = $this->application($project, $period, $form, [
            'path' => 'application-files/legacy.pdf',
            'original_name' => 'legacy.pdf',
        ], $owner);
        config(['filesystems.direct_media_downloads' => true]);

        Sanctum::actingAs($owner);
        $this->get("/api/applications/{$application->id}/form-files/doc")
            ->assertOk()->assertDownload('legacy.pdf');

        $admin = $this->user('super_admin');
        Sanctum::actingAs($admin);
        $this->get("/api/panel/applications/{$application->id}/form-files/doc")
            ->assertOk()->assertDownload('legacy.pdf');
    }

    public function test_client_supplied_file_path_cannot_be_attached_to_a_new_application(): void
    {
        [$project] = $this->scope('forged-path');
        Sanctum::actingAs($this->user());
        Storage::disk('application_private')->put('application-files/another-person.pdf', 'private content');

        $this->postJson('/api/applications', [
            'project_id' => $project->id,
            'consent_accepted' => true,
            'form_data' => ['doc' => ['path' => 'application-files/another-person.pdf']],
        ])->assertUnprocessable()->assertJsonValidationErrors('doc');

        $this->assertDatabaseCount('applications', 0);
        Storage::disk('application_private')->assertExists('application-files/another-person.pdf');
    }

    public function test_production_upload_rejects_a_local_or_shared_public_bucket(): void
    {
        app()->detectEnvironment(fn () => 'production');
        $file = UploadedFile::fake()->create('cv.pdf', 5, 'application/pdf');

        try {
            ApplicationFileStorage::putFile($file);
            $this->fail('Production local disk must be rejected.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(503, $exception->getResponse()->getStatusCode());
        }

        config([
            'filesystems.media_disk' => 'r2',
            'filesystems.disks.application_private.driver' => 's3',
            'filesystems.disks.application_private.bucket' => 'shared-bucket',
            'filesystems.disks.r2.bucket' => 'shared-bucket',
        ]);

        try {
            ApplicationFileStorage::putFile($file);
            $this->fail('The public media bucket must be rejected.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(503, $exception->getResponse()->getStatusCode());
        }
    }

    private function scope(string $slug): array
    {
        $project = Project::query()->create([
            'name' => $slug, 'slug' => $slug, 'type' => 'other',
            'status' => 'active', 'application_open' => true,
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id, 'name' => 'Active period', 'status' => 'active',
            'start_date' => now()->subMonth(), 'end_date' => now()->addMonth(),
        ]);
        $project->update(['current_period_id' => $period->id]);
        $form = ApplicationForm::query()->create([
            'project_id' => $project->id, 'period_id' => $period->id, 'is_active' => true,
            'fields' => [['id' => 'doc', 'type' => 'file', 'label' => 'Belge', 'required' => true]],
        ]);

        return [$project, $period, $form];
    }

    private function application(Project $project, Period $period, ApplicationForm $form, array $file, ?User $owner = null): Application
    {
        return Application::query()->create([
            'user_id' => ($owner ?? $this->user())->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'application_form_id' => $form->id,
            'status' => 'pending',
            'form_data' => ['doc' => $file],
        ]);
    }

    private function user(string $role = 'student'): User
    {
        $user = User::factory()->create([
            'surname' => 'File applicant', 'role' => $role, 'status' => 'active',
            'kvkk_consent_at' => now(), 'must_change_password' => false,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function coordinatorFor(Project $project): User
    {
        $user = $this->user('coordinator');
        $project->coordinators()->attach($user->id);
        $role = Role::findOrCreate('coordinator', 'web');
        Permission::findOrCreate('applications.view', 'web');
        $role->givePermissionTo('applications.view');
        RolePermissionScope::query()->updateOrCreate(
            ['role_name' => 'coordinator', 'permission_name' => 'applications.view'],
            ['scope_type' => 'own_projects', 'scope_payload' => []],
        );

        return $user;
    }
}
