<?php

namespace App\Services;

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitProjectResponsibility;
use App\Models\Project;
use App\Support\CoordinationUnitCatalog;
use Illuminate\Support\Facades\DB;

class CoordinationUnitBackfillService
{
    public function execute(bool $apply): array
    {
        $plan = $this->plan();

        if (! $apply || $plan['blockers'] !== []) {
            return $this->report($apply, $plan, 0);
        }

        $appliedChangeCount = DB::transaction(function () use ($plan): int {
            $changes = 0;

            foreach ($plan['project_units_to_create'] as $item) {
                CoordinationUnit::query()->create($item);
                $changes++;
            }

            foreach ($plan['service_units_to_create'] as $item) {
                CoordinationUnit::query()->create($item);
                $changes++;
            }

            foreach ($plan['responsibilities_to_create'] as $item) {
                $unitId = CoordinationUnit::query()
                    ->where('code', $item['unit_code'])
                    ->value('id');

                CoordinationUnitProjectResponsibility::query()->create([
                    'unit_id' => $unitId,
                    'project_id' => $item['project_id'],
                    'service_domain' => $item['service_domain'],
                    'is_primary' => true,
                    'status' => CoordinationUnitProjectResponsibility::STATUS_ACTIVE,
                ]);
                $changes++;
            }

            return $changes;
        });

        $verificationPlan = $this->plan();
        $report = $this->report(true, $plan, $appliedChangeCount);
        $report['verification'] = [
            'healthy' => $verificationPlan['blockers'] === [],
            'idempotent' => $this->proposedChangeCount($verificationPlan) === 0,
            'remaining_change_count' => $this->proposedChangeCount($verificationPlan),
            'blocker_count' => count($verificationPlan['blockers']),
        ];

        return $report;
    }

    private function plan(): array
    {
        $projects = Project::query()
            ->where('status', 'active')
            ->orderBy('id')
            ->get(['id', 'name']);

        $plan = [
            'project_units_to_create' => [],
            'service_units_to_create' => [],
            'responsibilities_to_create' => [],
            'blockers' => [],
            'active_project_count' => $projects->count(),
        ];

        foreach ($projects as $project) {
            $expected = [
                'code' => CoordinationUnitCatalog::projectUnitCode((int) $project->id),
                'name' => $project->name.' Koordinatörlüğü',
                'kind' => CoordinationUnit::KIND_PROJECT,
                'project_id' => (int) $project->id,
                'status' => CoordinationUnit::STATUS_ACTIVE,
            ];

            $existing = CoordinationUnit::withTrashed()
                ->where('project_id', $project->id)
                ->orWhere('code', $expected['code'])
                ->get();

            if ($existing->isEmpty()) {
                $plan['project_units_to_create'][] = $expected;

                continue;
            }

            if ($existing->count() !== 1 || ! $this->matchesExpectedUnit($existing->first(), $expected)) {
                $plan['blockers'][] = [
                    'type' => 'project_unit_conflict',
                    'project_id' => (int) $project->id,
                    'expected_code' => $expected['code'],
                    'existing_unit_ids' => $existing->pluck('id')->map(fn ($id) => (int) $id)->all(),
                ];
            }
        }

        foreach (CoordinationUnitCatalog::serviceUnits() as $code => $definition) {
            $expected = [
                'code' => $code,
                'name' => $definition['name'],
                'kind' => CoordinationUnit::KIND_SERVICE,
                'project_id' => null,
                'status' => CoordinationUnit::STATUS_ACTIVE,
            ];
            $existing = CoordinationUnit::withTrashed()->where('code', $code)->first();

            if (! $existing) {
                $plan['service_units_to_create'][] = $expected;

                continue;
            }

            if (! $this->matchesExpectedUnit($existing, $expected)) {
                $plan['blockers'][] = [
                    'type' => 'service_unit_conflict',
                    'unit_code' => $code,
                    'existing_unit_id' => (int) $existing->id,
                ];
            }
        }

        if ($plan['blockers'] !== []) {
            return $plan;
        }

        foreach (CoordinationUnitCatalog::serviceUnits() as $code => $definition) {
            $unit = CoordinationUnit::query()->where('code', $code)->first();

            foreach ($projects as $project) {
                foreach ($definition['domains'] as $domain) {
                    $existing = $unit
                        ? CoordinationUnitProjectResponsibility::withTrashed()
                            ->where('unit_id', $unit->id)
                            ->where('project_id', $project->id)
                            ->where('service_domain', $domain)
                            ->first()
                        : null;

                    if (! $existing) {
                        $plan['responsibilities_to_create'][] = [
                            'unit_code' => $code,
                            'project_id' => (int) $project->id,
                            'service_domain' => $domain,
                        ];

                        continue;
                    }

                    if ($existing->trashed() || $existing->status !== CoordinationUnitProjectResponsibility::STATUS_ACTIVE) {
                        $plan['blockers'][] = [
                            'type' => 'responsibility_conflict',
                            'unit_code' => $code,
                            'project_id' => (int) $project->id,
                            'service_domain' => $domain,
                            'existing_responsibility_id' => (int) $existing->id,
                        ];
                    }
                }
            }
        }

        return $plan;
    }

    private function matchesExpectedUnit(CoordinationUnit $unit, array $expected): bool
    {
        return ! $unit->trashed()
            && $unit->code === $expected['code']
            && $unit->kind === $expected['kind']
            && ($unit->project_id === null ? null : (int) $unit->project_id) === $expected['project_id'];
    }

    private function report(bool $apply, array $plan, int $appliedChangeCount): array
    {
        return [
            'meta' => [
                'mode' => $apply ? 'apply' : 'dry-run',
                'memberships_policy' => 'Gercek koordinatör ve personel üyelikleri bu backfill tarafından oluşturulmaz.',
            ],
            'summary' => [
                'active_project_count' => $plan['active_project_count'],
                'project_unit_change_count' => count($plan['project_units_to_create']),
                'service_unit_change_count' => count($plan['service_units_to_create']),
                'responsibility_change_count' => count($plan['responsibilities_to_create']),
                'membership_change_count' => 0,
                'proposed_change_count' => $this->proposedChangeCount($plan),
                'applied_change_count' => $appliedChangeCount,
                'blocker_count' => count($plan['blockers']),
                'can_apply' => $plan['blockers'] === [],
            ],
            'changes' => [
                'project_units' => $plan['project_units_to_create'],
                'service_units' => $plan['service_units_to_create'],
                'responsibilities' => $plan['responsibilities_to_create'],
                'memberships' => [],
            ],
            'blockers' => $plan['blockers'],
        ];
    }

    private function proposedChangeCount(array $plan): int
    {
        return count($plan['project_units_to_create'])
            + count($plan['service_units_to_create'])
            + count($plan['responsibilities_to_create']);
    }
}
