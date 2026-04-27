<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Project;
use App\Models\Period;
use Illuminate\Support\Str;

class ProjectSeeder extends Seeder
{
    public function run(): void
    {
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

            // Her projeye bir aktif dönem ekleyelim
            Period::firstOrCreate([
                'project_id' => $project->id,
                'name' => '2024-2025 Güz Dönemi',
            ], [
                'start_date' => now()->startOfMonth(),
                'end_date' => now()->addMonths(4)->endOfMonth(),
                'credit_start_amount' => 100,
                'credit_threshold' => 75,
                'status' => 'active',
            ]);
        }
    }
}
