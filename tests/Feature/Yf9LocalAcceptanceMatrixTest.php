<?php

namespace Tests\Feature;

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitMembership;
use App\Models\Participant;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use App\Services\PermissionResolver;
use App\Support\CoordinationUnitCatalog;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Yf9LocalAcceptanceMatrixTest extends TestCase
{
    use RefreshDatabase;

    private array $baseline;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(DatabaseSeeder::class);
        config()->set('coordination_authorization.mode', 'enforce');
        config()->set('coordination_authorization.active_unit_fallback', 'primary');
        $this->baseline = require base_path('tests/Fixtures/yf0_authorization_baseline.php');
    }

    public function test_all_18_accounts_have_the_exact_sidebar_root_and_button_capability_contract(): void
    {
        $cases = $this->authorityCases();

        $this->assertCount(18, $cases);
        $this->assertCount(18, collect($cases)->pluck('email')->unique());

        foreach ($cases as $case) {
            $this->actAs($case);

            $response = $this->getJson('/api/panel/modules')
                ->assertOk()
                ->assertHeader('X-Coordination-Unit-Id', (string) $case['unit']->id)
                ->assertJsonPath('authorization_context.active_unit_id', $case['unit']->id);
            $modules = collect($response->json('modules'))
                ->where('panel_type', 'authority')
                ->values();

            $this->assertSameSorted(
                $case['expected_modules'],
                $modules->pluck('id')->map(fn ($id) => (string) $id)->all(),
                "Unexpected sidebar snapshot for {$case['email']}."
            );

            foreach ($modules as $module) {
                $this->assertStringStartsWith('/panel/', (string) $module['href'], "Invalid root route for {$case['email']}: {$module['id']}.");
                $this->assertSame(
                    [],
                    array_values(array_diff($module['enabled_actions'], $module['actions'])),
                    "Manifest enabled an undeclared button action for {$case['email']}: {$module['id']}."
                );
            }

            $this->getJson('/api/auth/me')
                ->assertOk()
                ->assertJsonPath('user.organization_context.authoritative', true)
                ->assertJsonPath('user.organization_context.active_unit_id', $case['unit']->id)
                ->assertJsonPath('user.organization_context.active_membership_id', $case['membership']->id);
        }
    }

    public function test_all_18_accounts_can_log_in_with_the_documented_local_password(): void
    {
        foreach ($this->authorityCases() as $case) {
            $response = $this->postJson('/api/auth/login', [
                'email' => $case['email'],
                'password' => 'Demo1234!',
            ])->assertOk()
                ->assertJsonPath('token_type', 'Bearer')
                ->assertJsonPath('user.id', $case['user']->id)
                ->assertJsonPath('user.email', $case['email'])
                ->assertJsonPath('user.organization_context.authoritative', true)
                ->assertJsonPath('user.organization_context.active_unit_id', $case['unit']->id);

            $this->assertNotEmpty($response->json('access_token'), "Login token missing for {$case['email']}.");
        }
    }

    public function test_all_18_accounts_receive_only_their_program_list_dropdown_and_record_scope(): void
    {
        $allProjectIds = Project::query()->where('status', 'active')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach ($this->authorityCases() as $case) {
            $this->actAs($case);
            $resolver = app(PermissionResolver::class);
            $permission = $case['program_permission'];
            $expectedProjectIds = $resolver->projectIdsForPermission($case['user'], $permission);
            sort($expectedProjectIds);

            $dropdownIds = collect($this->getJson('/api/panel/projects/manageable?permission='.$permission)
                ->assertOk()
                ->json('projects'))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->sort()
                ->values()
                ->all();
            $this->assertSame($expectedProjectIds, $dropdownIds, "Unexpected project dropdown for {$case['email']}.");

            $programResponse = $this->getJson('/api/panel/programs')
                ->assertOk()
                ->assertJsonPath('work_mode', $case['work_mode']);
            $programs = collect($programResponse->json('programs'));
            $actualProjectIds = $programs->pluck('project_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
            $this->assertSame($expectedProjectIds, $actualProjectIds, "Unexpected program project scope for {$case['email']}.");

            if ($case['kind'] === CoordinationUnit::KIND_PROJECT) {
                $this->assertCount(1, $programs, "Project unit must see only its core program for {$case['email']}.");
                $this->assertSame(Program::KIND_CORE_PROGRAM, $programs->first()['program_kind']);
                $ownCore = $this->coreProgram((int) $case['project_id']);
                $outsideProjectId = collect($allProjectIds)->first(fn (int $id) => $id !== (int) $case['project_id']);
                $this->getJson('/api/panel/programs/'.$ownCore->id)->assertOk();
                $this->getJson('/api/panel/programs/'.$this->coreProgram((int) $outsideProjectId)->id)->assertForbidden();

                continue;
            }

            $this->assertSame($allProjectIds, $expectedProjectIds, "Service scope must cover its six responsibility projects for {$case['email']}.");
            if ($case['unit']->code === 'service_community_culture') {
                $this->assertCount(6, $programs);
                $this->assertTrue($programs->every(fn (array $program) => $program['program_kind'] === Program::KIND_COMMUNITY_EVENT));
                $this->getJson('/api/panel/programs/'.$this->communityProgram($allProjectIds[0])->id)->assertOk();
                $this->getJson('/api/panel/programs/'.$this->coreProgram($allProjectIds[0])->id)->assertForbidden();
            } else {
                $this->assertCount(12, $programs);
                $this->getJson('/api/panel/programs/'.$this->coreProgram($allProjectIds[0])->id)->assertOk();
                $this->getJson('/api/panel/programs/'.$this->coreProgram($allProjectIds[5])->id)->assertOk();
            }
        }
    }

    public function test_all_18_accounts_have_matching_direct_api_export_download_and_action_results(): void
    {
        foreach ($this->authorityCases() as $index => $case) {
            $this->actAs($case);
            $resolver = app(PermissionResolver::class);

            $exclusiveEndpoints = [
                'content.view' => '/api/panel/content',
                'announcements.view' => '/api/panel/announcements',
                'alumni_opportunities.view' => '/api/panel/alumni-opportunities',
                'financial.view' => '/api/panel/financials',
                'volunteer.view' => '/api/panel/volunteer/opportunities',
                'motivation.view' => '/api/panel/motivation/lists',
            ];
            foreach ($exclusiveEndpoints as $permission => $endpoint) {
                $response = $this->getJson($endpoint);
                $resolver->hasPermission($case['user'], $permission)
                    ? $response->assertOk()
                    : $response->assertForbidden();
            }

            foreach ([
                'requests.export' => '/api/panel/requests/export?format=csv',
                'support.export' => '/api/panel/support/tickets/export?format=csv',
                'programs.export' => '/api/panel/programs/export?format=csv',
            ] as $permission => $endpoint) {
                $response = $this->get($endpoint);
                $resolver->hasPermission($case['user'], $permission)
                    ? $response->assertOk()
                    : $response->assertForbidden();
            }

            $projectId = (int) ($case['project_id'] ?? Project::query()->where('status', 'active')->orderBy('id')->value('id'));
            $attendanceProgram = $case['unit']->code === 'service_community_culture'
                ? $this->communityProgram($projectId)
                : $this->coreProgram($projectId);
            $attendancePermission = $case['unit']->code === 'service_community_culture'
                ? 'programs.community_event.attendance.export'
                : 'programs.attendance.export';
            $attendanceExport = $this->get("/api/panel/programs/{$attendanceProgram->id}/attendances/export?format=csv");
            $resolver->hasPermission($case['user'], $attendancePermission)
                ? $attendanceExport->assertOk()
                : $attendanceExport->assertForbidden();

            $invoiceDownload = $this->get('/api/panel/financials/999999/invoice');
            $resolver->hasPermission($case['user'], 'financial.invoice.download')
                ? $invoiceDownload->assertNotFound()
                : $invoiceDownload->assertForbidden();

            $this->performRepresentativeSuccessfulAction($case, $index);
        }
    }

    public function test_all_18_accounts_can_use_dashboard_profile_leave_request_and_support_flows(): void
    {
        foreach ($this->authorityCases() as $index => $case) {
            $this->actAs($case);
            $this->getJson('/api/panel/dashboard/stats')->assertOk();
            $this->getJson('/api/user/profile')->assertOk();
            $requestOptions = $this->getJson('/api/panel/requests')->assertOk();
            $this->assertCount(9, $requestOptions->json('coordination_units'));
            $this->getJson('/api/panel/support/tickets')->assertOk();
            $this->getJson('/api/panel/inbox/messages')->assertOk();

            $leave = $this->postJson('/api/leave-requests', [
                'unit_id' => $case['unit']->id,
                'start_date' => now()->addDays(10 + $index)->toDateString(),
                'end_date' => now()->addDays(11 + $index)->toDateString(),
                'reason' => 'YF-9 yerel kabul izin yönlendirmesi.',
            ])->assertCreated()
                ->assertJsonPath('leave_request.unit_id', $case['unit']->id)
                ->assertJsonPath('leave_request.membership_id', $case['membership']->id)
                ->assertJsonPath(
                    'leave_request.reviewer_scope',
                    $case['position'] === CoordinationUnitMembership::POSITION_COORDINATOR ? 'super_admin' : 'unit_coordinator'
                );
            $this->assertNotNull($leave->json('leave_request.id'));

            $target = $this->counterpart($case);
            $workflowRequest = $this->postJson('/api/panel/requests', [
                'type' => 'other',
                'target_unit_id' => $case['unit']->id,
                'target_user_id' => $target->id,
                'description' => "YF-9 hedef üyelik kabul talebi {$case['email']}.",
            ])->assertCreated()
                ->assertJsonPath('request_item.target_unit_id', $case['unit']->id)
                ->assertJsonPath('request_item.target_user.id', $target->id);
            $requestId = (int) $workflowRequest->json('request_item.id');

            $this->putJson("/api/panel/requests/{$requestId}/status", ['status' => 'in_progress'])
                ->assertForbidden();

            $targetMembership = CoordinationUnitMembership::query()
                ->active()
                ->where('unit_id', $case['unit']->id)
                ->where('user_id', $target->id)
                ->firstOrFail();
            Sanctum::actingAs($target);
            $this->withHeader('X-Coordination-Unit-Id', (string) $case['unit']->id)
                ->putJson("/api/panel/requests/{$requestId}/status", ['status' => 'in_progress'])
                ->assertOk()
                ->assertJsonPath('request_item.target_membership_id', $targetMembership->id)
                ->assertJsonPath('request_item.status', 'in_progress');

            $this->actAs($case);
            $this->postJson('/api/panel/support/tickets', [
                'subject' => "YF-9 destek {$index}",
                'category' => 'general',
                'message' => 'YF-9 authority hesabı destek oluşturma kabul kaydı.',
            ])->assertCreated();
        }
    }

    public function test_two_demo_accounts_switch_context_without_permission_leakage(): void
    {
        $mediaCoordinator = User::query()->where('email', 'demo.coordinator.media@kademe.org')->firstOrFail();
        $media = CoordinationUnit::query()->where('code', 'service_media')->firstOrFail();
        $purchase = CoordinationUnit::query()->where('code', 'service_purchase_organization')->firstOrFail();
        Sanctum::actingAs($mediaCoordinator);

        $this->withHeader('X-Coordination-Unit-Id', (string) $media->id)
            ->getJson('/api/panel/modules')
            ->assertOk()
            ->assertJsonPath('authorization_context.active_unit_id', $media->id)
            ->assertJsonFragment(['id' => 'content'])
            ->assertJsonMissing(['id' => 'financials']);
        $this->withHeader('X-Coordination-Unit-Id', (string) $purchase->id)
            ->getJson('/api/panel/modules')
            ->assertOk()
            ->assertJsonPath('authorization_context.active_unit_id', $purchase->id)
            ->assertJsonFragment(['id' => 'financials'])
            ->assertJsonMissing(['id' => 'content']);
        $this->withHeader('X-Coordination-Unit-Id', (string) $purchase->id)
            ->getJson('/api/panel/financials')->assertOk();
        $this->withHeader('X-Coordination-Unit-Id', (string) $media->id)
            ->getJson('/api/panel/financials')->assertForbidden();

        $projectStaff = User::query()->where('email', 'demo.staff.p01@kademe.org')->firstOrFail();
        $projectUnit = $projectStaff->coordinationUnitMemberships()->active()->where('is_primary', true)->firstOrFail()->unit;
        $community = CoordinationUnit::query()->where('code', 'service_community_culture')->firstOrFail();
        Sanctum::actingAs($projectStaff);

        $this->withHeader('X-Coordination-Unit-Id', (string) $projectUnit->id)
            ->getJson('/api/panel/modules')
            ->assertOk()
            ->assertJsonFragment(['id' => 'my_project'])
            ->assertJsonMissing(['id' => 'volunteer']);
        $this->withHeader('X-Coordination-Unit-Id', (string) $community->id)
            ->getJson('/api/panel/modules')
            ->assertOk()
            ->assertJsonFragment(['id' => 'volunteer'])
            ->assertJsonMissing(['id' => 'my_project']);
        $this->withHeader('X-Coordination-Unit-Id', (string) $community->id)
            ->getJson('/api/panel/volunteer/opportunities')->assertOk();
        $this->withHeader('X-Coordination-Unit-Id', (string) $projectUnit->id)
            ->getJson('/api/panel/volunteer/opportunities')->assertForbidden();

        $unownedUnit = CoordinationUnit::query()
            ->whereNotIn('id', $projectStaff->coordinationUnitMemberships()->active()->pluck('unit_id'))
            ->firstOrFail();
        $this->withHeader('X-Coordination-Unit-Id', (string) $unownedUnit->id)
            ->getJson('/api/panel/modules')
            ->assertForbidden()
            ->assertJsonPath('error', 'invalid_coordination_unit_context');
    }

    /** @return list<array<string, mixed>> */
    private function authorityCases(): array
    {
        $cases = [];
        foreach (Project::query()->where('status', 'active')->orderBy('id')->get() as $project) {
            $suffix = str_pad((string) $project->id, 2, '0', STR_PAD_LEFT);
            $extraModules = $this->baseline['project_extra_modules_by_type'][$project->type] ?? [];
            foreach ([CoordinationUnitMembership::POSITION_COORDINATOR, CoordinationUnitMembership::POSITION_STAFF] as $position) {
                $cases[] = $this->case(
                    "demo.{$position}.p{$suffix}@kademe.org",
                    CoordinationUnitCatalog::projectUnitCode((int) $project->id),
                    $position,
                    [...$this->baseline["project_{$position}_modules"], ...$extraModules],
                    'programs.view',
                    'core',
                    (int) $project->id
                );
            }
        }

        foreach ([
            'service_media' => ['slug' => 'media', 'permission' => 'programs.view', 'mode' => 'media'],
            'service_purchase_organization' => ['slug' => 'purchase.organization', 'permission' => 'programs.logistics.view', 'mode' => 'logistics'],
            'service_community_culture' => ['slug' => 'community.culture', 'permission' => 'programs.community_event.view', 'mode' => 'community_event'],
        ] as $unitCode => $definition) {
            foreach ([CoordinationUnitMembership::POSITION_COORDINATOR, CoordinationUnitMembership::POSITION_STAFF] as $position) {
                $cases[] = $this->case(
                    "demo.{$position}.{$definition['slug']}@kademe.org",
                    $unitCode,
                    $position,
                    $this->baseline['service_modules'][$unitCode][$position],
                    $definition['permission'],
                    $definition['mode']
                );
            }
        }

        return $cases;
    }

    /** @return array<string, mixed> */
    private function case(
        string $email,
        string $unitCode,
        string $position,
        array $expectedModules,
        string $programPermission,
        string $workMode,
        ?int $projectId = null
    ): array {
        $user = User::query()->where('email', $email)->firstOrFail();
        $unit = CoordinationUnit::query()->where('code', $unitCode)->firstOrFail();
        $membership = CoordinationUnitMembership::query()
            ->active()
            ->where('unit_id', $unit->id)
            ->where('user_id', $user->id)
            ->where('position', $position)
            ->firstOrFail();

        return [
            'email' => $email,
            'user' => $user,
            'unit' => $unit,
            'membership' => $membership,
            'position' => $position,
            'expected_modules' => $expectedModules,
            'program_permission' => $programPermission,
            'work_mode' => $workMode,
            'project_id' => $projectId,
            'kind' => $unit->kind,
        ];
    }

    private function actAs(array $case): void
    {
        Sanctum::actingAs($case['user']);
        $this->withHeader('X-Coordination-Unit-Id', (string) $case['unit']->id);
    }

    private function coreProgram(int $projectId): Program
    {
        return Program::query()
            ->where('project_id', $projectId)
            ->where('program_kind', Program::KIND_CORE_PROGRAM)
            ->firstOrFail();
    }

    private function communityProgram(int $projectId): Program
    {
        return Program::query()
            ->where('project_id', $projectId)
            ->where('program_kind', Program::KIND_COMMUNITY_EVENT)
            ->firstOrFail();
    }

    private function performRepresentativeSuccessfulAction(array $case, int $index): void
    {
        $projectId = (int) ($case['project_id'] ?? Project::query()->where('status', 'active')->orderBy('id')->value('id'));

        if ($case['kind'] === CoordinationUnit::KIND_PROJECT) {
            $program = $this->coreProgram($projectId);
            $participant = Participant::query()->where('project_id', $projectId)->where('status', 'active')->firstOrFail();
            $this->putJson("/api/panel/programs/{$program->id}/attendances/{$participant->id}", [
                'is_valid' => true,
                'manual_note' => "YF-9 proje action {$index}",
            ])->assertOk();

            return;
        }

        if ($case['unit']->code === 'service_media') {
            $this->patchJson("/api/panel/projects/{$projectId}/public-content", [
                'short_description' => "YF-9 medya action {$index}",
            ])->assertOk();

            return;
        }

        $program = $case['unit']->code === 'service_community_culture'
            ? $this->communityProgram($projectId)
            : $this->coreProgram($projectId);
        $this->patchJson("/api/panel/programs/{$program->id}/logistics", [
            'location' => "YF-9 Lojistik {$index}",
        ])->assertOk();
    }

    private function counterpart(array $case): User
    {
        $targetPosition = $case['position'] === CoordinationUnitMembership::POSITION_COORDINATOR
            ? CoordinationUnitMembership::POSITION_STAFF
            : CoordinationUnitMembership::POSITION_COORDINATOR;

        return CoordinationUnitMembership::query()
            ->active()
            ->where('unit_id', $case['unit']->id)
            ->where('position', $targetPosition)
            ->where('user_id', '!=', $case['user']->id)
            ->with('user')
            ->firstOrFail()
            ->user;
    }

    private function assertSameSorted(array $expected, array $actual, string $message = ''): void
    {
        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual, $message);
    }
}
