<?php

namespace Tests\Feature;

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitMembership;
use App\Models\CoordinationUnitProjectResponsibility;
use App\Models\Period;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use App\Services\CoordinationUnitBackfillService;
use App\Services\CoordinationUnitPermissionRuleSyncService;
use App\Services\PermissionResolver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SharedModuleWorkModeAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Period $period;

    private CoordinationUnit $projectUnit;

    private CoordinationUnit $mediaUnit;

    private CoordinationUnit $purchaseUnit;

    private CoordinationUnit $communityUnit;

    private User $projectStaff;

    private User $mediaStaff;

    private User $purchaseStaff;

    private User $communityStaff;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(RolePermissionSeeder::class);
        $this->project = Project::query()->create([
            'name' => 'YF4 Shared Module Project',
            'slug' => 'yf4-shared-module-project',
            'type' => 'other',
            'status' => 'active',
            'application_open' => false,
            'next_application_date' => now()->addMonth()->toDateString(),
            'has_interview' => false,
        ]);
        $this->period = Period::query()->create([
            'project_id' => $this->project->id,
            'name' => '2026 YF4',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        app(CoordinationUnitBackfillService::class)->execute(true);
        $this->projectUnit = CoordinationUnit::query()->where('project_id', $this->project->id)->firstOrFail();
        $this->mediaUnit = CoordinationUnit::query()->where('code', 'service_media')->firstOrFail();
        $this->purchaseUnit = CoordinationUnit::query()->where('code', 'service_purchase_organization')->firstOrFail();
        $this->communityUnit = CoordinationUnit::query()->where('code', 'service_community_culture')->firstOrFail();

        foreach ([
            [$this->mediaUnit, 'media'],
            [$this->purchaseUnit, 'finance_procurement'],
            [$this->purchaseUnit, 'organization'],
            [$this->communityUnit, 'community_culture'],
        ] as [$unit, $domain]) {
            CoordinationUnitProjectResponsibility::query()->firstOrCreate([
                'unit_id' => $unit->id,
                'project_id' => $this->project->id,
                'service_domain' => $domain,
            ], [
                'is_primary' => true,
                'status' => CoordinationUnitProjectResponsibility::STATUS_ACTIVE,
            ]);
        }

        app(CoordinationUnitPermissionRuleSyncService::class)->execute(true);

        $this->projectStaff = $this->authority('staff', 'Project');
        $this->mediaStaff = $this->authority('staff', 'Media');
        $this->purchaseStaff = $this->authority('staff', 'Purchase');
        $this->communityStaff = $this->authority('staff', 'Community');
        $this->membership($this->projectUnit, $this->projectStaff);
        $this->membership($this->mediaUnit, $this->mediaStaff);
        $this->membership($this->purchaseUnit, $this->purchaseStaff);
        $this->membership($this->communityUnit, $this->communityStaff);

        config()->set('coordination_authorization.mode', 'enforce');
    }

    public function test_same_program_endpoint_returns_four_bounded_work_modes(): void
    {
        $core = $this->program('Core Program', Program::KIND_CORE_PROGRAM);
        $community = $this->program('Community Event', Program::KIND_COMMUNITY_EVENT, $this->communityUnit->id);

        Sanctum::actingAs($this->projectStaff);
        $this->getJson('/api/panel/programs?project_id='.$this->project->id)
            ->assertOk()
            ->assertJsonPath('work_mode', 'core')
            ->assertJsonCount(1, 'programs')
            ->assertJsonPath('programs.0.id', $core->id);
        $this->getJson('/api/panel/programs/'.$community->id)->assertForbidden();

        Sanctum::actingAs($this->mediaStaff);
        $this->getJson('/api/panel/programs?project_id='.$this->project->id)
            ->assertOk()
            ->assertJsonPath('work_mode', 'media')
            ->assertJsonCount(2, 'programs')
            ->assertJsonPath('programs.0.capabilities.view_media', true)
            ->assertJsonMissingPath('programs.0.credit_deduction');
        $this->putJson('/api/panel/programs/'.$core->id, ['title' => 'Media core overwrite'])
            ->assertForbidden();

        Sanctum::actingAs($this->purchaseStaff);
        $this->getJson('/api/panel/programs?project_id='.$this->project->id)
            ->assertOk()
            ->assertJsonPath('work_mode', 'logistics')
            ->assertJsonCount(2, 'programs')
            ->assertJsonPath('programs.0.capabilities.update_logistics', true)
            ->assertJsonPath('programs.0.capabilities.view_media', false)
            ->assertJsonMissingPath('programs.0.credit_deduction');
        $this->patchJson('/api/panel/programs/'.$core->id.'/logistics', [
            'location' => 'YF4 Logistics Hall',
            'radius_meters' => 175,
        ])->assertOk();
        $this->putJson('/api/panel/programs/'.$core->id, ['title' => 'Purchase core overwrite'])
            ->assertForbidden();
        $this->getJson('/api/panel/programs/'.$core->id.'/photos')->assertForbidden();

        Sanctum::actingAs($this->communityStaff);
        $this->getJson('/api/panel/programs?project_id='.$this->project->id)
            ->assertOk()
            ->assertJsonPath('work_mode', 'community_event')
            ->assertJsonCount(1, 'programs')
            ->assertJsonPath('programs.0.id', $community->id);
        $this->getJson('/api/panel/programs/'.$core->id)->assertForbidden();

        $this->assertDatabaseHas('programs', [
            'id' => $core->id,
            'title' => 'Core Program',
            'location' => 'YF4 Logistics Hall',
            'radius_meters' => 175,
        ]);
    }

    public function test_service_units_receive_common_request_and_support_create_capabilities(): void
    {
        $resolver = app(PermissionResolver::class);

        foreach ([$this->mediaStaff, $this->purchaseStaff, $this->communityStaff] as $actor) {
            $this->assertTrue($resolver->hasPermission($actor, 'requests.create'));
            $this->assertTrue($resolver->hasPermission($actor, 'support.create'));
        }

        Sanctum::actingAs($this->mediaStaff);
        $this->postJson('/api/panel/requests', [
            'type' => 'other',
            'target_unit_id' => $this->purchaseUnit->id,
            'target_user_id' => $this->purchaseStaff->id,
            'description' => 'YF4 ortak talep oluşturma yeteneği doğrulaması.',
            'project_id' => $this->project->id,
        ])->assertCreated();
        $this->postJson('/api/panel/support/tickets', [
            'subject' => 'YF4 ortak destek',
            'category' => 'technical',
            'message' => 'Hizmet birimi personeli destek kaydı oluşturabilir.',
            'project_id' => $this->project->id,
        ])->assertCreated();
    }

    private function authority(string $role, string $suffix): User
    {
        $user = User::factory()->create([
            'name' => 'YF4',
            'surname' => $suffix,
            'email' => 'yf4-'.strtolower($suffix).'@test.local',
            'role' => $role,
            'status' => 'active',
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function membership(CoordinationUnit $unit, User $user): void
    {
        CoordinationUnitMembership::query()->create([
            'unit_id' => $unit->id,
            'user_id' => $user->id,
            'position' => CoordinationUnitMembership::POSITION_STAFF,
            'is_primary' => true,
            'status' => CoordinationUnitMembership::STATUS_ACTIVE,
        ]);
    }

    private function program(string $title, string $kind, ?int $managingUnitId = null): Program
    {
        return Program::query()->create([
            'project_id' => $this->project->id,
            'period_id' => $this->period->id,
            'program_kind' => $kind,
            'managing_unit_id' => $managingUnitId,
            'title' => $title,
            'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addHour(),
            'status' => 'scheduled',
            'credit_deduction' => 10,
        ]);
    }
}
