<?php

namespace Tests\Feature;

use App\Models\AlumniOpportunity;
use App\Models\Certificate;
use App\Models\Participant;
use App\Models\Period;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AlumniPortalEnhancementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_bulk_graduation_marks_participants_graduated(): void
    {
        $admin = $this->makeSuperAdmin('bulk-grad-admin@test.local');
        $studentA = $this->makeStudent('bulk-grad-a@test.local');
        $studentB = $this->makeStudent('bulk-grad-b@test.local');

        [$project, $period] = $this->makeProjectWithPeriod('bulk-grad-proj');
        $pA = $this->makeParticipant($studentA, $project, $period);
        $pB = $this->makeParticipant($studentB, $project, $period);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/panel/participants/bulk-graduation', [
            'participant_ids' => [$pA->id, $pB->id],
            'graduation_status' => 'graduated',
        ]);

        $response->assertOk();
        $results = $response->json('results');
        $this->assertCount(2, $results);
        $this->assertTrue($results[0]['ok']);
        $this->assertTrue($results[1]['ok']);

        $studentA->refresh();
        $studentB->refresh();
        $this->assertSame('alumni', $studentA->role);
        $this->assertSame('alumni', $studentB->role);
        $this->assertTrue($studentA->hasRole('alumni'));
        $this->assertTrue($studentB->hasRole('alumni'));
    }

    public function test_graduation_backfills_incomplete_certificate_record(): void
    {
        $admin = $this->makeSuperAdmin('cert-backfill-admin@test.local');
        $student = $this->makeStudent('cert-backfill-stu@test.local');
        [$project, $period] = $this->makeProjectWithPeriod('cert-backfill-proj');
        $participant = $this->makeParticipant($student, $project, $period);

        $placeholder = Certificate::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'type' => 'participation',
            'verification_code' => 'TEMP'.strtoupper(bin2hex(random_bytes(4))),
            'issued_at' => now(),
            'created_by' => $admin->id,
        ]);
        $placeholder->update([
            'verification_code' => '',
            'created_by' => null,
        ]);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/panel/participants/{$participant->id}/graduation", [
            'graduation_status' => 'completed',
        ])->assertOk();

        $certificate = Certificate::query()
            ->where('user_id', $student->id)
            ->where('project_id', $project->id)
            ->where('type', 'participation')
            ->first();

        $this->assertNotNull($certificate);
        $this->assertNotEmpty($certificate->verification_code);
        $this->assertNotNull($certificate->issued_at);
        $this->assertSame($admin->id, (int) $certificate->created_by);
    }

    public function test_student_sees_scoped_alumni_opportunities(): void
    {
        $student = $this->makeStudent('opp-stu@test.local');
        [$projectA, $periodA] = $this->makeProjectWithPeriod('opp-proj-a');
        [$projectB] = $this->makeProjectWithPeriod('opp-proj-b');

        $this->makeParticipant($student, $projectA, $periodA);

        $creator = $this->makeSuperAdmin('opp-creator@test.local');

        $global = AlumniOpportunity::query()->create([
            'project_id' => null,
            'created_by' => $creator->id,
            'title' => 'Global firsat',
            'kind' => 'network',
            'published_at' => now()->subDay(),
            'target_audience' => null,
        ]);

        $inProjectA = AlumniOpportunity::query()->create([
            'project_id' => $projectA->id,
            'created_by' => $creator->id,
            'title' => 'Proje A',
            'kind' => 'internship',
            'published_at' => now()->subDay(),
            'target_audience' => ['student'],
        ]);

        AlumniOpportunity::query()->create([
            'project_id' => $projectB->id,
            'created_by' => $creator->id,
            'title' => 'Baska proje',
            'kind' => 'event',
            'published_at' => now()->subDay(),
            'target_audience' => ['student'],
        ]);

        Sanctum::actingAs($student);

        $response = $this->getJson('/api/alumni-opportunities');
        $response->assertOk();
        $ids = collect($response->json('opportunities'))->pluck('id')->all();
        $this->assertContains($global->id, $ids);
        $this->assertContains($inProjectA->id, $ids);
        $this->assertCount(2, $ids);
    }

    public function test_panel_can_crud_alumni_opportunity_with_announcement_permissions(): void
    {
        $admin = $this->makeSuperAdmin('opp-panel-admin@test.local');
        [$project] = $this->makeProjectWithPeriod('opp-panel-proj');

        Sanctum::actingAs($admin);

        $create = $this->postJson('/api/panel/alumni-opportunities', [
            'title' => 'Staj ilani',
            'kind' => 'internship',
            'summary' => 'Kisa ozet',
            'project_id' => $project->id,
            'target_audience' => ['alumni', 'student'],
        ]);

        $create->assertCreated();
        $id = (int) $create->json('opportunity.id');

        $this->putJson("/api/panel/alumni-opportunities/{$id}", [
            'title' => 'Staj ilani guncel',
        ])->assertOk();

        $this->getJson("/api/panel/alumni-opportunities/{$id}")->assertOk();

        $this->deleteJson("/api/panel/alumni-opportunities/{$id}")->assertOk();
    }

    private function makeSuperAdmin(string $email): User
    {
        $user = User::factory()->create([
            'name' => 'Admin',
            'surname' => 'Test',
            'email' => $email,
            'role' => 'super_admin',
        ]);
        Role::findOrCreate('super_admin', 'web');
        $user->assignRole('super_admin');

        return $user;
    }

    private function makeStudent(string $email): User
    {
        $user = User::factory()->create([
            'name' => 'Ogr',
            'surname' => 'Test',
            'email' => $email,
            'role' => 'student',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('student', 'web');
        $user->assignRole('student');

        return $user;
    }

    /**
     * @return array{0: Project, 1: Period}
     */
    private function makeProjectWithPeriod(string $slug): array
    {
        $project = Project::query()->create([
            'name' => 'T '.$slug,
            'slug' => $slug,
            'type' => 'kademe_plus',
            'status' => 'active',
            'application_open' => true,
        ]);

        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => 'Donem',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonths(6),
            'credit_start_amount' => 100,
            'credit_threshold' => 75,
            'status' => 'active',
        ]);

        return [$project, $period];
    }

    private function makeParticipant(User $user, Project $project, Period $period): Participant
    {
        return Participant::query()->create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
            'credit' => 100,
        ]);
    }
}
