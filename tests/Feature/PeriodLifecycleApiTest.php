<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Period;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use App\Services\PeriodLifecycleService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PeriodLifecycleApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_coordinator_can_create_planned_activate_and_start_closing_in_own_project(): void
    {
        [$actor, $project] = $this->coordinatorProject('period-api-flow');

        $createResponse = $this->postJson('/api/panel/periods', $this->periodPayload($project));
        $createResponse
            ->assertCreated()
            ->assertJsonPath('period.status', 'planned')
            ->assertJsonPath('period.lifecycle.allowed_transitions.0', 'activate');

        $periodId = (int) $createResponse->json('period.id');

        $this->postJson("/api/panel/periods/{$periodId}/activate", [
            'reason' => 'Yeni egitim donemi basliyor.',
        ])
            ->assertOk()
            ->assertJsonPath('period.status', 'active')
            ->assertJsonPath('period.lifecycle.is_current', true)
            ->assertJsonPath('period.lifecycle.allowed_transitions.0', 'start_closing');

        $this->assertSame($periodId, $project->fresh()->current_period_id);

        $this->postJson("/api/panel/periods/{$periodId}/closing/start", [
            'reason' => 'Donem sonu kontrolleri baslatildi.',
        ])
            ->assertOk()
            ->assertJsonPath('period.status', 'closing')
            ->assertJsonPath('period.lifecycle.is_current', true)
            ->assertJsonPath('period.lifecycle.allowed_transitions.0', 'cancel_closing')
            ->assertJsonPath('period.lifecycle.allowed_transitions.1', 'complete');

        $this->assertSame($actor->id, Period::query()->findOrFail($periodId)->closing_started_by);
    }

    public function test_generic_update_cannot_change_lifecycle_status(): void
    {
        [, $project] = $this->coordinatorProject('period-api-generic-update');
        $period = $this->period($project, 'Aktif Donem', 'active');

        $this->putJson("/api/panel/periods/{$period->id}", [
            'name' => $period->name,
            'start_date' => $period->start_date->toDateString(),
            'end_date' => $period->end_date->toDateString(),
            'credit_start_amount' => 100,
            'credit_threshold' => 75,
            'status' => 'completed',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Donem durumu genel duzenleme formundan degistirilemez. Uygun yasam dongusu islemini kullanin.');

        $this->assertSame('active', $period->fresh()->status);
        $this->assertDatabaseCount('period_archives', 0);
    }

    public function test_period_workspace_detail_returns_scoped_lifecycle_timeline(): void
    {
        [$actor, $project] = $this->coordinatorProject('period-api-workspace');
        $period = app(PeriodLifecycleService::class)->createPlanned(
            $this->periodPayload($project, 'Calisma Alani Donemi'),
            $actor,
        );

        $this->getJson("/api/panel/periods/{$period->id}")
            ->assertOk()
            ->assertJsonPath('period.id', $period->id)
            ->assertJsonPath('period.lifecycle.is_archive_mode', false)
            ->assertJsonPath('period.lifecycle.allowed_transitions.0', 'activate')
            ->assertJsonPath('period.lifecycle_events.0.event_type', 'created')
            ->assertJsonPath('period.lifecycle_events.0.actor.id', $actor->id);
    }

    public function test_active_creation_conflict_rolls_back_the_new_planned_record(): void
    {
        [, $project] = $this->coordinatorProject('period-api-create-conflict');
        $current = $this->period($project, 'Mevcut Donem', 'active');
        $project->currentPeriod()->associate($current);
        $project->save();

        $this->postJson('/api/panel/periods', [
            ...$this->periodPayload($project, 'Cakisan Donem'),
            'status' => 'active',
        ])
            ->assertConflict()
            ->assertJsonPath('message', 'Bu projenin zaten guncel bir donemi var. Once mevcut donemi kapatin.');

        $this->assertDatabaseMissing('periods', ['project_id' => $project->id, 'name' => 'Cakisan Donem']);
    }

    public function test_completion_uses_lifecycle_service_and_reopen_is_restricted(): void
    {
        [, $project] = $this->coordinatorProject('period-api-complete');
        $period = $this->period($project, 'Kapanacak Donem', 'active');

        $this->postJson("/api/panel/periods/{$period->id}/complete", [
            'notes' => 'Koordinator kapanisi.',
        ])
            ->assertOk()
            ->assertJsonPath('period.status', 'completed')
            ->assertJsonPath('archive.archive_version', 1);

        $this->assertNull($project->fresh()->current_period_id);
        $this->assertSame(
            ['closing_started', 'completed'],
            $period->lifecycleEvents()->pluck('event_type')->all(),
        );

        $this->postJson("/api/panel/periods/{$period->id}/complete", [
            'notes' => 'Tekrarlanan kapanis.',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Yalniz aktif veya kapanis hazirligindaki donem tamamlanabilir.');
        $this->assertDatabaseCount('period_archives', 1);

        $this->postJson("/api/panel/periods/{$period->id}/reopen", [
            'target_status' => 'planned',
            'reason' => 'Bu gerekce yeterince uzun.',
        ])->assertForbidden();

        $superAdmin = User::factory()->create([
            'name' => 'Super',
            'surname' => 'Admin',
            'role' => 'super_admin',
        ]);
        $superAdmin->assignRole(Role::findByName('super_admin', 'web'));
        Sanctum::actingAs($superAdmin);

        $this->postJson("/api/panel/periods/{$period->id}/reopen", [
            'target_status' => 'planned',
            'reason' => 'Arsivdeki katilimci sonucu duzeltilecek.',
        ])
            ->assertOk()
            ->assertJsonPath('period.status', 'planned')
            ->assertJsonPath('period.lifecycle.allowed_transitions.0', 'activate');

        $this->postJson("/api/panel/periods/{$period->id}/activate", [
            'reason' => 'Arsiv duzeltmesi yeniden kapatilacak.',
        ])->assertOk();
        $this->postJson("/api/panel/periods/{$period->id}/complete", [
            'notes' => 'Katilimci sonucu duzeltildi.',
        ])
            ->assertOk()
            ->assertJsonPath('archive.archive_version', 2)
            ->assertJsonPath('archive.correction_reason', 'Arsivdeki katilimci sonucu duzeltilecek.');

        $archives = $this->getJson("/api/panel/periods/{$period->id}/archives")
            ->assertOk()
            ->assertJsonCount(2, 'archives');
        $latestArchiveId = (int) $archives->json('archives.0.id');
        $this->postJson("/api/panel/periods/{$period->id}/archives/{$latestArchiveId}/verify")
            ->assertOk()
            ->assertJsonPath('verification.status', 'verified')
            ->assertJsonPath('verification.chain_valid', true);
    }

    public function test_reopen_requires_a_meaningful_reason_before_lifecycle_state_changes(): void
    {
        $project = $this->project('period-api-reopen-reason');
        $period = $this->period($project, 'Arsiv Donemi', 'completed');
        $superAdmin = User::factory()->create([
            'name' => 'Super',
            'surname' => 'Admin',
            'role' => 'super_admin',
        ]);
        $superAdmin->assignRole(Role::findByName('super_admin', 'web'));
        Sanctum::actingAs($superAdmin);

        $this->postJson("/api/panel/periods/{$period->id}/reopen", [
            'target_status' => 'planned',
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->postJson("/api/panel/periods/{$period->id}/reopen", [
            'target_status' => 'planned',
            'reason' => 'Kisa',
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->assertSame('completed', $period->fresh()->status);
        $this->assertDatabaseCount('period_lifecycle_events', 0);
    }

    public function test_closing_blocks_new_program_but_allows_existing_application_resolution(): void
    {
        [, $project] = $this->coordinatorProject('period-api-closing-policy');
        $period = $this->period($project, 'Kapanis Politikasi', 'active');
        $project->forceFill(['current_period_id' => $period->id])->save();
        $student = User::factory()->create([
            'name' => 'Basvuru',
            'surname' => 'Ogrencisi',
            'role' => 'student',
        ]);
        $application = Application::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'pending',
        ]);

        $this->postJson("/api/panel/periods/{$period->id}/closing/start", [
            'reason' => 'Yeni kayitlar durduruluyor.',
        ])->assertOk();

        $this->postJson('/api/panel/programs', [
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Kapanista Acilmamali',
            'start_at' => now()->addDay()->toIso8601String(),
            'end_at' => now()->addDay()->addHour()->toIso8601String(),
        ])
            ->assertStatus(423)
            ->assertJsonPath('message', 'Kapanis hazirligindaki donemde yeni kayit acilamaz; yalniz mevcut isler sonuclandirilabilir.');

        $this->putJson("/api/panel/applications/{$application->id}/status", [
            'status' => 'accepted',
            'evaluation_note' => 'Kapanis oncesi kesin karar.',
        ])
            ->assertOk()
            ->assertJsonPath('application.status', 'accepted');
    }

    public function test_completion_rechecks_readiness_and_rolls_back_archive_when_blocked(): void
    {
        [$actor, $project] = $this->coordinatorProject('period-api-readiness-block');
        $period = $this->period($project, 'Engelli Kapanis', 'active');
        $project->forceFill(['current_period_id' => $period->id])->save();
        Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Sonuclanmamis Program',
            'start_at' => now(),
            'end_at' => now()->addHour(),
            'status' => 'scheduled',
        ]);

        $this->postJson("/api/panel/periods/{$period->id}/closing/start", [
            'reason' => 'Hazirlik kontrolu basladi.',
        ])->assertOk();

        Log::spy();
        $this->postJson("/api/panel/periods/{$period->id}/complete", [
            'notes' => 'Blocker varken kapanmamalidir.',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.blockers.0', 'Planlanmis veya devam eden programlar var. (1)');

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $event, array $context) => $event === 'period_lifecycle.closure_blocked'
                && $context['period_id'] === $period->id
                && $context['project_id'] === $project->id
                && $context['actor_id'] === $actor->id
                && $context['blocker_count'] > 0
                && is_string($context['watermark'])
        )->once();

        $this->assertSame('closing', $period->fresh()->status);
        $this->assertDatabaseCount('period_archives', 0);
    }

    private function coordinatorProject(string $slug): array
    {
        $actor = User::factory()->create([
            'name' => 'Donem',
            'surname' => 'Koordinatoru',
            'role' => 'coordinator',
        ]);
        $actor->assignRole(Role::findByName('coordinator', 'web'));
        $project = $this->project($slug);
        $project->coordinators()->attach($actor->id);
        Sanctum::actingAs($actor);

        return [$actor, $project];
    }

    private function project(string $slug): Project
    {
        return Project::query()->create([
            'name' => str($slug)->headline()->toString(),
            'slug' => $slug,
            'type' => 'other',
            'status' => 'active',
        ]);
    }

    private function periodPayload(Project $project, string $name = 'Yeni Donem'): array
    {
        return [
            'project_id' => $project->id,
            'name' => $name,
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'credit_start_amount' => 100,
            'credit_threshold' => 75,
        ];
    }

    private function period(Project $project, string $name, string $status): Period
    {
        return Period::query()->create([
            'project_id' => $project->id,
            'name' => $name,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'credit_start_amount' => 100,
            'credit_threshold' => 75,
            'status' => $status,
        ]);
    }
}
