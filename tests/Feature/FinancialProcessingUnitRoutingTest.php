<?php

namespace Tests\Feature;

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitMembership;
use App\Models\FinancialTransaction;
use App\Models\Project;
use App\Models\User;
use App\Services\CoordinationUnitBackfillService;
use App\Services\CoordinationUnitPermissionRuleSyncService;
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

    public function test_legacy_project_financial_is_preserved_for_purchase_unit_while_project_users_cannot_create_new_records(): void
    {
        $project = Project::query()->create([
            'name' => 'Pergel Finans',
            'slug' => 'pergel-finans',
            'type' => 'other',
            'status' => 'active',
        ]);

        app(CoordinationUnitBackfillService::class)->execute(true);
        app(CoordinationUnitPermissionRuleSyncService::class)->execute(true);

        $projectUnit = CoordinationUnit::query()->where('project_id', $project->id)->firstOrFail();
        $purchaseUnit = CoordinationUnit::query()
            ->where('code', 'service_purchase_organization')
            ->firstOrFail();

        $projectCoordinator = $this->authority('coordinator', 'Project');
        $otherProjectCoordinator = $this->authority('coordinator', 'ProjectOther');
        $purchaseCoordinator = $this->authority('coordinator', 'Purchase');
        $purchaseStaff = $this->authority('staff', 'Purchase');
        $this->membership($projectUnit, $projectCoordinator, CoordinationUnitMembership::POSITION_COORDINATOR);
        $this->membership($projectUnit, $otherProjectCoordinator, CoordinationUnitMembership::POSITION_COORDINATOR);
        $this->membership($purchaseUnit, $purchaseCoordinator, CoordinationUnitMembership::POSITION_COORDINATOR);
        $this->membership($purchaseUnit, $purchaseStaff, CoordinationUnitMembership::POSITION_STAFF);

        Sanctum::actingAs($projectCoordinator);
        $this->postJson('/api/panel/financials', [
            'project_id' => $project->id,
            'type' => 'expense',
            'category' => 'food',
            'spending_unit' => 'Pergel Ekibi',
            'payee_name' => 'Tedarikci A.S.',
            'amount' => 1250,
        ])->assertForbidden();

        $this->getJson('/api/panel/financials')->assertForbidden();
        $this->getJson('/api/coordinator/financials')->assertForbidden();

        $transaction = FinancialTransaction::query()->create([
            'project_id' => $project->id,
            'processing_unit_id' => $purchaseUnit->id,
            'type' => 'expense',
            'category' => 'food',
            'spending_unit' => 'Pergel Ekibi',
            'payee_name' => 'Tedarikci A.S.',
            'amount' => 1250,
            'status' => 'pending',
            'submitted_by' => $projectCoordinator->id,
            'submitted_at' => now(),
        ]);
        $transactionId = (int) $transaction->id;
        $this->putJson("/api/panel/financials/{$transactionId}/approve")->assertForbidden();

        Sanctum::actingAs($otherProjectCoordinator);
        $this->getJson('/api/panel/financials')->assertForbidden();
        $this->getJson("/api/panel/financials/{$transactionId}")->assertForbidden();

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
