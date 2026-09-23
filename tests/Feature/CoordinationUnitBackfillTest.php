<?php

namespace Tests\Feature;

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitMembership;
use App\Models\CoordinationUnitProjectResponsibility;
use App\Models\Project;
use App\Models\User;
use App\Services\CoordinationUnitBackfillService;
use App\Support\CoordinationUnitCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoordinationUnitBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function project(string $name, string $status = 'active'): Project
    {
        return Project::query()->create([
            'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'type' => 'other',
            'status' => $status,
        ]);
    }

    public function test_dry_run_plans_active_project_and_service_units_without_writing_data_or_memberships(): void
    {
        $first = $this->project('First Active Project');
        $second = $this->project('Second Active Project');
        $this->project('Passive Project', 'passive');

        $legacyCoordinator = User::factory()->create([
            'surname' => 'Legacy',
            'role' => 'coordinator',
        ]);
        $legacyCoordinator->coordinatedProjects()->attach($first->id);

        $report = app(CoordinationUnitBackfillService::class)->execute(false);

        $this->assertSame(2, $report['summary']['active_project_count']);
        $this->assertSame(2, $report['summary']['project_unit_change_count']);
        $this->assertSame(3, $report['summary']['service_unit_change_count']);
        $this->assertSame(8, $report['summary']['responsibility_change_count']);
        $this->assertSame(0, $report['summary']['membership_change_count']);
        $this->assertSame(13, $report['summary']['proposed_change_count']);
        $this->assertSame(0, CoordinationUnit::query()->count());
        $this->assertSame(0, CoordinationUnitMembership::query()->count());
        $this->assertTrue($legacyCoordinator->coordinatedProjects()->whereKey($first->id)->exists());
        $this->assertFalse($legacyCoordinator->coordinatedProjects()->whereKey($second->id)->exists());
    }

    public function test_apply_is_idempotent_and_does_not_import_legacy_memberships(): void
    {
        $first = $this->project('First Active Project');
        $second = $this->project('Second Active Project');
        $legacyStaff = User::factory()->create([
            'surname' => 'LegacyStaff',
            'role' => 'staff',
        ]);
        $legacyStaff->assignedProjects()->attach($first->id);

        $service = app(CoordinationUnitBackfillService::class);
        $firstRun = $service->execute(true);
        $secondRun = $service->execute(true);

        $this->assertSame(13, $firstRun['summary']['applied_change_count']);
        $this->assertTrue($firstRun['verification']['healthy']);
        $this->assertTrue($firstRun['verification']['idempotent']);
        $this->assertSame(0, $secondRun['summary']['applied_change_count']);
        $this->assertTrue($secondRun['verification']['idempotent']);

        $this->assertSame(5, CoordinationUnit::query()->count());
        $this->assertSame(8, CoordinationUnitProjectResponsibility::query()->count());
        $this->assertSame(0, CoordinationUnitMembership::query()->count());
        $this->assertSame(0, $legacyStaff->coordinationUnitMemberships()->count());

        $this->assertDatabaseHas('coordination_units', [
            'code' => CoordinationUnitCatalog::projectUnitCode($first->id),
            'project_id' => $first->id,
            'kind' => CoordinationUnit::KIND_PROJECT,
        ]);
        $this->assertDatabaseHas('coordination_units', [
            'code' => CoordinationUnitCatalog::projectUnitCode($second->id),
            'project_id' => $second->id,
            'kind' => CoordinationUnit::KIND_PROJECT,
        ]);
        $this->assertDatabaseHas('coordination_units', [
            'code' => 'service_purchase_organization',
            'kind' => CoordinationUnit::KIND_SERVICE,
        ]);
    }

    public function test_conflicting_existing_unit_blocks_the_entire_apply(): void
    {
        $project = $this->project('Conflict Project');
        CoordinationUnit::query()->create([
            'code' => 'service_media',
            'name' => 'Yanlış Proje Birimi',
            'kind' => CoordinationUnit::KIND_PROJECT,
            'project_id' => $project->id,
            'status' => CoordinationUnit::STATUS_ACTIVE,
        ]);

        $report = app(CoordinationUnitBackfillService::class)->execute(true);

        $this->assertGreaterThan(0, $report['summary']['blocker_count']);
        $this->assertSame(0, $report['summary']['applied_change_count']);
        $this->assertSame(1, CoordinationUnit::query()->count());
        $this->assertSame(0, CoordinationUnitProjectResponsibility::query()->count());
    }

    public function test_console_command_defaults_to_non_mutating_dry_run(): void
    {
        $this->project('Command Project');

        $this->artisan('coordination-units:backfill --format=json')
            ->assertSuccessful();

        $this->assertSame(0, CoordinationUnit::query()->count());
    }
}
