<?php

namespace Tests\Feature;

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitMembership;
use App\Models\CoordinationUnitPermissionRule;
use App\Models\FinancialTransaction;
use App\Models\Project;
use App\Models\User;
use App\Services\CoordinationUnitBackfillService;
use App\Services\CoordinationUnitPermissionRuleSyncService;
use App\Support\PanelModuleCatalog;
use App\Support\MediaStorage;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
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

    public function test_pending_invoice_can_be_edited_with_scoped_permission_and_keeps_downloadable_history(): void
    {
        Storage::fake(MediaStorage::diskName());
        $project = Project::query()->create([
            'name' => 'Finans Taslağı',
            'slug' => 'finans-taslagi',
            'type' => 'other',
            'status' => 'active',
        ]);
        $otherProject = Project::query()->create([
            'name' => 'Baska Proje',
            'slug' => 'baska-proje',
            'type' => 'other',
            'status' => 'active',
        ]);
        app(CoordinationUnitBackfillService::class)->execute(true);
        app(CoordinationUnitPermissionRuleSyncService::class)->execute(true);

        $projectUnit = CoordinationUnit::query()->where('project_id', $project->id)->firstOrFail();
        $otherUnit = CoordinationUnit::query()->where('project_id', $otherProject->id)->firstOrFail();
        $purchaseUnit = CoordinationUnit::query()->where('code', 'service_purchase_organization')->firstOrFail();
        $coordinator = $this->authority('coordinator', 'DraftOwner');
        $projectStaff = $this->authority('staff', 'DraftStaff');
        $outsider = $this->authority('coordinator', 'DraftOutsider');
        $purchase = $this->authority('coordinator', 'DraftPurchase');
        $this->membership($projectUnit, $coordinator, CoordinationUnitMembership::POSITION_COORDINATOR);
        $this->membership($projectUnit, $projectStaff, CoordinationUnitMembership::POSITION_STAFF);
        $this->membership($otherUnit, $outsider, CoordinationUnitMembership::POSITION_COORDINATOR);
        $this->membership($purchaseUnit, $purchase, CoordinationUnitMembership::POSITION_COORDINATOR);

        Sanctum::actingAs($coordinator);
        $created = $this->post('/api/panel/financials', [
            'project_id' => $project->id,
            'type' => 'expense',
            'category' => 'food',
            'payee_name' => 'İlk Tedarikçi',
            'amount' => 120,
            'invoice' => UploadedFile::fake()->create('ilk.pdf', 20, 'application/pdf'),
        ])->assertCreated()
            ->assertJsonPath('transaction.capabilities.edit', true);
        $transactionId = (int) $created->json('transaction.id');
        $originalPath = FinancialTransaction::findOrFail($transactionId)->invoice_path;

        $this->withHeader('Accept', 'application/json')->post("/api/panel/financials/{$transactionId}", [
            '_method' => 'PUT',
            'category' => 'other',
            'payee_name' => 'Yeni Tedarikçi',
            'amount' => 180,
            'invoice' => UploadedFile::fake()->create('gecersiz.pdf', 20, 'application/pdf'),
        ])->assertUnprocessable();
        $this->assertCount(1, Storage::disk(MediaStorage::diskName())->allFiles('invoices'));

        $this->post("/api/panel/financials/{$transactionId}", [
            '_method' => 'PUT',
            'category' => 'other',
            'category_note' => 'Etkinlik malzemesi',
            'payee_name' => 'Yeni Tedarikçi',
            'amount' => 180,
            'invoice' => UploadedFile::fake()->create('yeni.pdf', 20, 'application/pdf'),
        ])->assertOk()
            ->assertJsonPath('transaction.status', 'pending')
            ->assertJsonPath('transaction.invoice_revisions_count', 1)
            ->assertJsonPath('transaction.capabilities.edit', true);

        $transaction = FinancialTransaction::findOrFail($transactionId);
        $this->assertSame($project->id, $transaction->project_id);
        $this->assertSame($purchaseUnit->id, $transaction->processing_unit_id);
        $this->assertSame('180.00', $transaction->amount);
        $this->assertNotSame($originalPath, $transaction->invoice_path);
        Storage::disk(MediaStorage::diskName())->assertExists([$originalPath, $transaction->invoice_path]);
        $revision = $transaction->invoiceRevisions()->firstOrFail();
        $this->assertSame($originalPath, $revision->invoice_path);
        $this->assertSame($coordinator->id, $revision->replaced_by);
        $editLog = Activity::query()->where('description', 'financial.updated')->latest('id')->firstOrFail();
        $this->assertSame($coordinator->id, (int) $editLog->causer_id);
        $this->assertSame($revision->id, data_get($editLog->properties->toArray(), 'domain.invoice_revision_id'));
        $this->assertContains('amount', data_get($editLog->properties->toArray(), 'domain.changed_fields'));
        $this->putJson("/api/panel/financials/{$transactionId}", [
            'amount' => 200,
            'status' => 'paid',
            'project_id' => $otherProject->id,
        ])->assertUnprocessable();

        $this->getJson("/api/panel/financials/{$transactionId}/invoice-revisions")
            ->assertOk()
            ->assertJsonPath('invoice_revisions.0.id', $revision->id)
            ->assertJsonMissingPath('invoice_revisions.0.invoice_path');
        $this->get("/api/panel/financials/{$transactionId}/invoice-revisions/{$revision->id}/download")
            ->assertOk();

        Sanctum::actingAs($projectStaff);
        $this->putJson("/api/panel/financials/{$transactionId}", ['amount' => 200])->assertForbidden();
        Sanctum::actingAs($outsider);
        $this->putJson("/api/panel/financials/{$transactionId}", ['amount' => 200])->assertForbidden();
        $this->getJson("/api/panel/financials/{$transactionId}/invoice-revisions")->assertForbidden();

        Sanctum::actingAs($purchase);
        $this->putJson("/api/panel/financials/{$transactionId}", ['invoice_no' => 'F-2026-1'])
            ->assertOk();
        $this->putJson("/api/panel/financials/{$transactionId}/approve")->assertOk();
        $this->putJson("/api/panel/financials/{$transactionId}", ['amount' => 200])->assertUnprocessable();
        $this->assertSame('180.00', FinancialTransaction::findOrFail($transactionId)->amount);
    }

    public function test_financial_update_rule_upgrade_preserves_existing_admin_decisions(): void
    {
        foreach (['rule-project-a', 'rule-project-b'] as $slug) {
            Project::query()->create([
                'name' => $slug,
                'slug' => $slug,
                'type' => 'other',
                'status' => 'active',
            ]);
        }
        app(CoordinationUnitBackfillService::class)->execute(true);
        app(CoordinationUnitPermissionRuleSyncService::class)->execute(true);
        $units = CoordinationUnit::query()->where('kind', CoordinationUnit::KIND_PROJECT)->orderBy('id')->get();
        $firstRule = CoordinationUnitPermissionRule::query()
            ->where('unit_id', $units[0]->id)
            ->where('position', 'coordinator')
            ->where('permission_name', 'financial.update')
            ->firstOrFail();
        $firstRule->forceDelete();
        $secondRule = CoordinationUnitPermissionRule::query()
            ->where('unit_id', $units[1]->id)
            ->where('position', 'coordinator')
            ->where('permission_name', 'financial.update')
            ->firstOrFail();
        $secondRule->update(['effect' => 'deny']);

        $migration = require database_path('migrations/2026_10_02_000002_add_project_coordinator_financial_update_rule.php');
        $migration->up();

        $this->assertDatabaseHas('coordination_unit_permission_rules', [
            'unit_id' => $units[0]->id,
            'position' => 'coordinator',
            'permission_name' => 'financial.update',
            'effect' => CoordinationUnitPermissionRule::EFFECT_ALLOW,
            'status' => CoordinationUnitPermissionRule::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseHas('coordination_unit_permission_rules', [
            'id' => $secondRule->id,
            'effect' => 'deny',
        ]);
    }
}
