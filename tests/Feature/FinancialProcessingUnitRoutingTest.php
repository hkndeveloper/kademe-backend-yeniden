<?php

namespace Tests\Feature;

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitMembership;
use App\Models\FinancialTransaction;
use App\Models\Project;
use App\Models\User;
use App\Services\CoordinationUnitBackfillService;
use App\Services\CoordinationUnitPermissionRuleSyncService;
use App\Support\PanelModuleCatalog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinancialProcessingUnitRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config()->set('coordination_authorization.mode', 'enforce');
    }

    private function authority(string $role, string $suffix): User
    {
        $user = User::factory()->create([
            'name' => ucfirst($role),
            'surname' => $suffix,
            'email' => strtolower($role).'-'.strtolower($suffix).'@test.local',
            'role' => $role,
            'status' => 'active',
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function membership(CoordinationUnit $unit, User $user, string $position): void
    {
        CoordinationUnitMembership::query()->create([
            'unit_id' => $unit->id,
            'user_id' => $user->id,
            'position' => $position,
            'is_primary' => true,
            'status' => CoordinationUnitMembership::STATUS_ACTIVE,
        ]);
    }

    public function test_project_team_sees_its_own_financials_while_purchase_unit_keeps_final_approval(): void
    {
        $project = Project::query()->create([
            'name' => 'Pergel Finans',
            'slug' => 'pergel-finans',
            'type' => 'other',
            'status' => 'active',
        ]);
        $otherProject = Project::query()->create([
            'name' => 'Diger Proje',
            'slug' => 'diger-proje',
            'type' => 'other',
            'status' => 'active',
        ]);

        app(CoordinationUnitBackfillService::class)->execute(true);
        app(CoordinationUnitPermissionRuleSyncService::class)->execute(true);

        $projectUnit = CoordinationUnit::query()->where('project_id', $project->id)->firstOrFail();
        $otherProjectUnit = CoordinationUnit::query()->where('project_id', $otherProject->id)->firstOrFail();
        $purchaseUnit = CoordinationUnit::query()
            ->where('code', 'service_purchase_organization')
            ->firstOrFail();

        $projectCoordinator = $this->authority('coordinator', 'Project');
        $projectStaff = $this->authority('staff', 'Project');
        $otherProjectCoordinator = $this->authority('coordinator', 'ProjectOther');
        $purchaseCoordinator = $this->authority('coordinator', 'Purchase');
        $purchaseStaff = $this->authority('staff', 'Purchase');
        $this->membership($projectUnit, $projectCoordinator, CoordinationUnitMembership::POSITION_COORDINATOR);
        $this->membership($projectUnit, $projectStaff, CoordinationUnitMembership::POSITION_STAFF);
        $this->membership($otherProjectUnit, $otherProjectCoordinator, CoordinationUnitMembership::POSITION_COORDINATOR);
        $this->membership($purchaseUnit, $purchaseCoordinator, CoordinationUnitMembership::POSITION_COORDINATOR);
        $this->membership($purchaseUnit, $purchaseStaff, CoordinationUnitMembership::POSITION_STAFF);

        $projectMenu = app(PanelModuleCatalog::class)->visibleFor($projectCoordinator);
        $this->assertContains('financials', array_column($projectMenu['modules'], 'id'));

        Sanctum::actingAs($projectCoordinator);
        $created = $this->postJson('/api/panel/financials', [
            'project_id' => $project->id,
            'type' => 'expense',
            'category' => 'food',
            'spending_unit' => 'Pergel Ekibi',
            'payee_name' => 'Tedarikci A.S.',
            'amount' => 1250,
        ])->assertCreated();
        $transactionId = (int) $created->json('transaction.id');
        $this->assertDatabaseHas('financial_transactions', [
            'id' => $transactionId,
            'project_id' => $project->id,
            'processing_unit_id' => $purchaseUnit->id,
            'status' => 'pending',
        ]);

        $this->postJson('/api/panel/financials', [
            'project_id' => $project->id,
            'type' => 'expense',
            'category' => 'food',
            'payee_name' => 'Tedarikci A.S.',
            'amount' => 100,
            'payment_date' => '2026-09-24',
        ])->assertUnprocessable();
        $this->postJson('/api/panel/financials', [
            'project_id' => $project->id,
            'type' => 'payment',
            'category' => 'food',
            'payee_name' => 'Tedarikci A.S.',
            'amount' => 100,
        ])->assertUnprocessable();

        $this->getJson('/api/panel/financials')
            ->assertOk()
            ->assertJsonPath('transactions.data.0.id', $transactionId)
            ->assertJsonPath('transactions.data.0.capabilities.approve', false);
        $this->getJson('/api/coordinator/financials')->assertOk();
        $this->putJson("/api/panel/financials/{$transactionId}/approve")->assertForbidden();

        Sanctum::actingAs($projectStaff);
        $this->getJson('/api/panel/financials')
            ->assertOk()
            ->assertJsonPath('transactions.data.0.id', $transactionId)
            ->assertJsonPath('transactions.data.0.capabilities.approve', false);
        $this->getJson("/api/panel/financials/{$transactionId}")->assertOk();
        $this->getJson('/api/panel/financials/export')->assertForbidden();
        $this->putJson("/api/panel/financials/{$transactionId}/approve")->assertForbidden();

        Sanctum::actingAs($otherProjectCoordinator);
        $this->getJson('/api/panel/financials')->assertOk()->assertJsonCount(0, 'transactions.data');
        $this->getJson("/api/panel/financials/{$transactionId}")->assertForbidden();
        $this->postJson('/api/panel/financials', [
            'project_id' => $project->id,
            'type' => 'expense',
            'category' => 'food',
            'payee_name' => 'Yanlis Proje',
            'amount' => 1,
        ])->assertForbidden();

        Sanctum::actingAs($purchaseStaff);
        $this->getJson('/api/panel/financials')
            ->assertOk()
            ->assertJsonPath('transactions.data.0.id', $transactionId)
            ->assertJsonPath('transactions.data.0.capabilities.approve', false);
        $this->putJson("/api/panel/financials/{$transactionId}/approve")->assertForbidden();

        Sanctum::actingAs($purchaseCoordinator);
        $this->getJson('/api/panel/financials')
            ->assertOk()
            ->assertJsonPath('transactions.data.0.capabilities.approve', true)
            ->assertJsonPath('transactions.data.0.capabilities.mark_paid', true);
        $this->getJson('/api/panel/participants?project_id='.$project->id)->assertForbidden();
        $this->putJson("/api/panel/financials/{$transactionId}/approve")
            ->assertOk()
            ->assertJsonPath('transaction.status', 'approved');
        $this->putJson("/api/panel/financials/{$transactionId}/pay")
            ->assertOk()
            ->assertJsonPath('transaction.status', 'paid');

        $this->assertDatabaseHas('workflow_status_histories', [
            'subject_type' => FinancialTransaction::class,
            'subject_id' => $transactionId,
            'from_status' => 'pending',
            'to_status' => 'approved',
            'changed_by' => $purchaseCoordinator->id,
            'unit_id' => $purchaseUnit->id,
        ]);
        $this->assertDatabaseHas('workflow_status_histories', [
            'subject_type' => FinancialTransaction::class,
            'subject_id' => $transactionId,
            'from_status' => 'approved',
            'to_status' => 'paid',
            'changed_by' => $purchaseCoordinator->id,
            'unit_id' => $purchaseUnit->id,
        ]);
    }
}
