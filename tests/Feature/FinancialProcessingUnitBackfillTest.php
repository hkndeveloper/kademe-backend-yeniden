<?php

namespace Tests\Feature;

use App\Models\CoordinationUnit;
use App\Models\FinancialTransaction;
use App\Models\Project;
use App\Models\User;
use App\Services\CoordinationUnitBackfillService;
use App\Services\FinancialProcessingUnitBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialProcessingUnitBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_is_dry_run_by_default_additive_and_idempotent(): void
    {
        $project = Project::query()->create([
            'name' => 'Backfill Project',
            'slug' => 'backfill-project',
            'type' => 'other',
            'status' => 'active',
        ]);
        $user = User::factory()->create(['surname' => 'Backfill']);
        app(CoordinationUnitBackfillService::class)->execute(true);
        $purchase = CoordinationUnit::query()
            ->where('code', 'service_purchase_organization')
            ->firstOrFail();

        $legacy = FinancialTransaction::query()->create([
            'project_id' => $project->id,
            'type' => 'expense',
            'category' => 'food',
            'payee_name' => 'Legacy Tedarikci',
            'amount' => 100,
            'status' => 'pending',
            'submitted_by' => $user->id,
            'submitted_at' => now(),
        ]);
        $alreadyAssigned = FinancialTransaction::query()->create([
            'project_id' => $project->id,
            'processing_unit_id' => $purchase->id,
            'type' => 'expense',
            'category' => 'food',
            'payee_name' => 'Atanmis Tedarikci',
            'amount' => 200,
            'status' => 'pending',
            'submitted_by' => $user->id,
            'submitted_at' => now(),
        ]);
        $projectless = FinancialTransaction::query()->create([
            'project_id' => null,
            'type' => 'expense',
            'category' => 'other',
            'payee_name' => 'Global Legacy Tedarikci',
            'amount' => 300,
            'status' => 'pending',
            'submitted_by' => $user->id,
            'submitted_at' => now(),
        ]);

        $service = app(FinancialProcessingUnitBackfillService::class);
        $dryRun = $service->execute(false);
        $this->assertSame(1, $dryRun['summary']['proposed_change_count']);
        $this->assertSame(1, $dryRun['summary']['skipped_count']);
        $this->assertSame('projectless_record_requires_global_legacy_scope', $dryRun['skipped'][0]['reason']);
        $this->assertNull($legacy->fresh()->processing_unit_id);

        $applied = $service->execute(true);
        $this->assertSame(1, $applied['summary']['applied_change_count']);
        $this->assertTrue($applied['verification']['idempotent']);
        $this->assertSame($purchase->id, $legacy->fresh()->processing_unit_id);
        $this->assertSame($purchase->id, $alreadyAssigned->fresh()->processing_unit_id);
        $this->assertNull($projectless->fresh()->processing_unit_id);

        $secondRun = $service->execute(true);
        $this->assertSame(0, $secondRun['summary']['applied_change_count']);
        $this->assertSame(0, $secondRun['summary']['proposed_change_count']);
    }
}
