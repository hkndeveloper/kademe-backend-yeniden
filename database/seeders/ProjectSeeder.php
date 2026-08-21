<?php

namespace Database\Seeders;

use App\Models\ApplicationWindow;
use App\Models\Period;
use App\Models\Project;
use App\Models\User;
use App\Services\PeriodLifecycleService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProjectSeeder extends Seeder
{
    public function run(PeriodLifecycleService $lifecycleService): void
    {
        $actor = User::query()->where('role', 'super_admin')->orderBy('id')->first();
        $projects = [
            [
                'name' => 'Diplomasi360',
                'type' => 'diplomasi360',
                'description' => 'Uluslararası ilişkiler ve diplomasi alanında yetkinlik kazandırma programı.',
            ],
            [
                'name' => 'KADEME+',
                'type' => 'kademe_plus',
                'description' => 'Oyunlaştırma tabanlı, modüler ve rozet sistemli yetkinlik geliştirme platformu.',
            ],
            [
                'name' => 'Eurodesk',
                'type' => 'eurodesk',
                'description' => 'Avrupa fırsatları ve hibe programları hakkında bilgilendirme ağı.',
            ],
            [
                'name' => 'Pergel Fellowship',
                'type' => 'pergel_fellowship',
                'description' => 'Genç liderler için özel tasarlanmış mentorluk ve gelişim programı.',
            ],
            [
                'name' => 'Kariyer Psikolojik Danışmanlık (KPD)',
                'type' => 'kpd',
                'description' => 'Öğrencilere yönelik profesyonel psikolojik test ve danışmanlık hizmeti.',
            ],
            [
                'name' => 'Zirve KADEME',
                'type' => 'zirve_kademe',
                'description' => 'Geleneksel KADEME büyük gençlik zirvesi.',
            ],
        ];

        foreach ($projects as $proj) {
            $project = Project::firstOrCreate([
                'slug' => Str::slug($proj['name']),
            ], [
                'name' => $proj['name'],
                'type' => $proj['type'],
                'short_description' => $proj['description'],
                'status' => 'active',
                'application_open' => true,
            ]);

            $period = Period::query()
                ->where('project_id', $project->id)
                ->where('name', '2024-2025 Güz Dönemi')
                ->first();

            if (! $period) {
                $period = $lifecycleService->createPlanned([
                    'project_id' => $project->id,
                    'name' => '2024-2025 Güz Dönemi',
                    'start_date' => now()->startOfMonth()->toDateString(),
                    'end_date' => now()->addMonths(4)->endOfMonth()->toDateString(),
                    'credit_start_amount' => 100,
                    'credit_threshold' => 75,
                ], $actor);
                $period = $lifecycleService->activate(
                    $period->id,
                    $actor,
                    'Proje seeder aktif donem kurulumu.',
                );
            } elseif (in_array($period->status, [
                PeriodLifecycleService::PLANNED,
                PeriodLifecycleService::LEGACY_PASSIVE,
            ], true) && $project->current_period_id === null) {
                $period = $lifecycleService->activate(
                    $period->id,
                    $actor,
                    'Proje seeder mevcut donem aktivasyonu.',
                );
            } elseif (in_array($period->status, [
                PeriodLifecycleService::ACTIVE,
                PeriodLifecycleService::CLOSING,
            ], true) && $project->current_period_id === null) {
                $project->forceFill(['current_period_id' => $period->id])->save();
            }

            $project = $project->fresh();
            $isOpen = $period->status === PeriodLifecycleService::ACTIVE
                && (bool) $project->application_open;

            ApplicationWindow::query()->firstOrCreate([
                'project_id' => $project->id,
                'period_id' => $period->id,
            ], [
                'is_open' => $isOpen,
                'starts_at' => $project->application_start_at,
                'ends_at' => $project->application_end_at,
                'next_application_date' => $project->next_application_date,
                'has_interview' => (bool) $project->has_interview,
                'quota' => $project->quota,
                'change_note' => 'Proje seeder tarafindan donem baglaminda olusturuldu.',
                'opened_by' => $isOpen ? $actor?->id : null,
                'opened_at' => $isOpen ? now() : null,
                'updated_by' => $actor?->id,
                'status_changed_at' => now(),
            ]);
        }
    }
}
