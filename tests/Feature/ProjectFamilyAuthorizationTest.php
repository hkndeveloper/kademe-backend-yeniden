<?php

namespace Tests\Feature;

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitMembership;
use App\Models\Project;
use App\Models\User;
use App\Services\CoordinationUnitBackfillService;
use App\Services\CoordinationUnitPermissionRuleSyncService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectFamilyAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Project> */
    private array $projects = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        foreach ([
            'diplomasi360', 'kademe_plus', 'eurodesk',
            'pergel_fellowship', 'kpd', 'zirve_kademe',
        ] as $type) {
            $this->projects[$type] = Project::query()->create([
                'name' => $type,
                'slug' => 'family-'.$type,
                'type' => $type,
                'status' => 'active',
            ]);
        }

        app(CoordinationUnitBackfillService::class)->execute(true);
        app(CoordinationUnitPermissionRuleSyncService::class)->execute(true);
        config()->set('coordination_authorization.mode', 'enforce');
    }

    public function test_sidebar_manifest_exposes_only_the_members_project_family(): void
    {
        $expectations = [
            'diplomasi360' => ['visible' => 'diplomasi360', 'hidden' => ['pergel', 'eurodesk', 'kademe_plus', 'zirve_kademe', 'kpd', 'assignments']],
            'kademe_plus' => ['visible' => 'kademe_plus', 'hidden' => ['diplomasi360', 'pergel', 'eurodesk', 'zirve_kademe', 'kpd', 'assignments']],
            'eurodesk' => ['visible' => 'eurodesk', 'hidden' => ['diplomasi360', 'pergel', 'kademe_plus', 'zirve_kademe', 'kpd', 'assignments']],
            'pergel_fellowship' => ['visible' => 'pergel', 'hidden' => ['diplomasi360', 'eurodesk', 'kademe_plus', 'zirve_kademe', 'kpd']],
            'kpd' => ['visible' => 'kpd', 'hidden' => ['diplomasi360', 'pergel', 'eurodesk', 'kademe_plus', 'zirve_kademe', 'assignments']],
            'zirve_kademe' => ['visible' => 'zirve_kademe', 'hidden' => ['diplomasi360', 'pergel', 'eurodesk', 'kademe_plus', 'kpd', 'assignments']],
        ];

        foreach ($expectations as $type => $expectation) {
            Sanctum::actingAs($this->member($type, CoordinationUnitMembership::POSITION_COORDINATOR));
            $modules = collect($this->getJson('/api/panel/modules')->assertOk()->json('modules'))
                ->pluck('id');

            $this->assertTrue($modules->contains($expectation['visible']), $type.' visible family');
            $this->assertTrue($modules->contains('digital_bohca'), $type.' digital bohca');
            foreach ($expectation['hidden'] as $module) {
                $this->assertFalse($modules->contains($module), $type.' must hide '.$module);
            }
            $this->assertSame(
                $type === 'pergel_fellowship',
                $modules->contains('assignments'),
                $type.' assignments metadata'
            );
        }
    }

    public function test_direct_assignment_and_kpd_endpoints_follow_family_permissions_for_coordinator_and_staff(): void
    {
        foreach ([
            CoordinationUnitMembership::POSITION_COORDINATOR,
            CoordinationUnitMembership::POSITION_STAFF,
        ] as $position) {
            Sanctum::actingAs($this->member('diplomasi360', $position));
            $this->getJson('/api/panel/assignments')->assertForbidden();
            $this->getJson('/api/panel/kpd/appointments')->assertForbidden();

            Sanctum::actingAs($this->member('pergel_fellowship', $position));
            $this->getJson('/api/panel/assignments')->assertOk();
            $this->getJson('/api/panel/kpd/appointments')->assertForbidden();

            Sanctum::actingAs($this->member('kpd', $position));
            $this->getJson('/api/panel/assignments')->assertForbidden();
            $this->getJson('/api/panel/kpd/appointments')->assertOk();
        }
    }

    private function member(string $type, string $position): User
    {
        $role = $position === CoordinationUnitMembership::POSITION_COORDINATOR ? 'coordinator' : 'staff';
        $email = "family.{$type}.{$position}@test.local";
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $type,
                'surname' => $position,
                'password' => bcrypt('password'),
                'role' => $role,
                'status' => 'active',
            ]
        );
        $user->syncRoles([$role]);
        $unit = CoordinationUnit::query()->where('project_id', $this->projects[$type]->id)->firstOrFail();
        CoordinationUnitMembership::query()->firstOrCreate(
            ['unit_id' => $unit->id, 'user_id' => $user->id],
            [
                'position' => $position,
                'is_primary' => true,
                'status' => CoordinationUnitMembership::STATUS_ACTIVE,
            ]
        );

        return $user;
    }
}
