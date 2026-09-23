<?php

namespace App\Services;

use App\Models\CoordinationUnitProjectResponsibility;
use App\Models\FinancialTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class FinancialProcessingUnitBackfillService
{
    public function execute(bool $apply): array
    {
        $plan = $this->plan();
        $applied = 0;

        if ($apply && $plan['assignments'] !== []) {
            $applied = DB::transaction(function () use ($plan): int {
                $count = 0;

                foreach ($plan['assignments'] as $assignment) {
                    $count += FinancialTransaction::query()
                        ->whereKey($assignment['financial_transaction_id'])
                        ->whereNull('processing_unit_id')
                        ->update(['processing_unit_id' => $assignment['processing_unit_id']]);
                }

                return $count;
            });
        }

        $report = [
            'meta' => [
                'mode' => $apply ? 'apply' : 'dry-run',
                'policy' => 'Yalniz processing_unit_id bos ve finance_procurement birincil sorumlulugu bulunan kayitlar atanir.',
            ],
            'summary' => [
                'proposed_change_count' => count($plan['assignments']),
                'applied_change_count' => $applied,
                'skipped_count' => count($plan['skipped']),
            ],
            'assignments' => $plan['assignments'],
            'skipped' => $plan['skipped'],
        ];

        if ($apply) {
            $verification = $this->plan();
            $report['verification'] = [
                'idempotent' => $verification['assignments'] === [],
                'remaining_change_count' => count($verification['assignments']),
                'remaining_skipped_count' => count($verification['skipped']),
            ];
        }

        return $report;
    }

    private function plan(): array
    {
        $responsibilities = CoordinationUnitProjectResponsibility::query()
            ->active()
            ->where('service_domain', FinancialTransactionAccessService::SERVICE_DOMAIN)
            ->where('is_primary', true)
            ->whereHas('unit', fn (Builder $query) => $query->where('status', 'active'))
            ->get(['project_id', 'unit_id'])
            ->keyBy(fn (CoordinationUnitProjectResponsibility $item) => (int) $item->project_id);

        $assignments = [];
        $skipped = [];

        FinancialTransaction::query()
            ->whereNull('processing_unit_id')
            ->orderBy('id')
            ->get(['id', 'project_id'])
            ->each(function (FinancialTransaction $transaction) use ($responsibilities, &$assignments, &$skipped) {
                if ($transaction->project_id === null) {
                    $skipped[] = [
                        'financial_transaction_id' => (int) $transaction->id,
                        'project_id' => null,
                        'reason' => 'projectless_record_requires_global_legacy_scope',
                    ];

                    return;
                }

                $responsibility = $responsibilities->get((int) $transaction->project_id);

                if (! $responsibility) {
                    $skipped[] = [
                        'financial_transaction_id' => (int) $transaction->id,
                        'project_id' => (int) $transaction->project_id,
                        'reason' => 'active_primary_finance_procurement_responsibility_missing',
                    ];

                    return;
                }

                $assignments[] = [
                    'financial_transaction_id' => (int) $transaction->id,
                    'project_id' => (int) $transaction->project_id,
                    'processing_unit_id' => (int) $responsibility->unit_id,
                ];
            });

        return compact('assignments', 'skipped');
    }
}
