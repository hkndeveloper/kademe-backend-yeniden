<?php

namespace Tests\Feature;

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitMembership;
use App\Models\Project;
use App\Models\User;
use App\Services\CoordinationCutoverReadinessService;
use App\Services\CoordinationUnitBackfillService;
use App\Services\CoordinationUnitPermissionRuleSyncService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoordinationCutoverReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Project::query()->create([
            'name' => 'Readiness Project',
            'slug' => 'readiness-project',
            'type' => 'other',
            'status' => 'active',
        ]);
        app(CoordinationUnitBackfillService::class)->execute(true);
        app(CoordinationUnitPermissionRuleSyncService::class)->execute(true);
    }

    public function test_shadow_can_be_technically_ready_without_real_membership_data_but_enforce_cannot(): void
    {
        $service = app(CoordinationCutoverReadinessService::class);

        $shadow = $service->inspect('shadow');
        $enforce = $service->inspect('enforce');

        $this->assertTrue($shadow['summary']['technical_ready']);
        $this->assertTrue($shadow['summary']['requested_target_ready']);
        $this->assertFalse($shadow['summary']['membership_data_ready']);
        $this->assertFalse($enforce['summary']['requested_target_ready']);
        $this->assertSame('no_authority_users_for_target', $enforce['findings']['data_blockers'][0]['code']);
        $this->assertSame('read_only', $shadow['meta']['audit_mode']);
    }

    public function test_pilot_readiness_evaluates_only_selected_synthetic_user(): void
    {
        $pilot = $this->authority('coordinator', 'Pilot');
        $unmapped = $this->authority('staff', 'Unmapped');
        $purchase = CoordinationUnit::query()->where('code', 'service_purchase_organization')->firstOrFail();
        CoordinationUnitMembership::query()->create([
            'unit_id' => $purchase->id,
            'user_id' => $pilot->id,
            'position' => CoordinationUnitMembership::POSITION_COORDINATOR,
            'is_primary' => true,
            'status' => CoordinationUnitMembership::STATUS_ACTIVE,
        ]);

        $service = app(CoordinationCutoverReadinessService::class);
        $pilotReport = $service->inspect('pilot', [$pilot->id]);
        $enforceReport = $service->inspect('enforce');

        $this->assertTrue($pilotReport['summary']['requested_target_ready']);
        $this->assertSame(1, $pilotReport['summary']['evaluated_user_count']);
        $this->assertSame([$pilot->id], $pilotReport['membership_audit']['pilot_user_ids']);
        $this->assertFalse($enforceReport['summary']['requested_target_ready']);
        $this->assertSame($unmapped->id, $enforceReport['membership_audit']['users_without_active_membership'][0]['id']);
    }

    public function test_readiness_command_is_read_only_and_strict_exit_tracks_target(): void
    {
        $beforeUnits = CoordinationUnit::query()->count();
        $beforeMemberships = CoordinationUnitMembership::query()->count();

        $this->artisan('coordination-units:cutover-readiness', ['--target' => 'shadow', '--format' => 'json', '--strict' => true])
            ->assertSuccessful();
        $this->artisan('coordination-units:cutover-readiness', ['--target' => 'enforce', '--format' => 'json', '--strict' => true])
            ->assertFailed();

        $this->assertSame($beforeUnits, CoordinationUnit::query()->count());
        $this->assertSame($beforeMemberships, CoordinationUnitMembership::query()->count());
    }

    private function authority(string $role, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'surname' => 'Readiness',
            'role' => $role,
            'status' => 'active',
        ]);
        $user->assignRole($role);

        return $user;
    }
}
