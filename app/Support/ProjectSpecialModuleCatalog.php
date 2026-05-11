<?php

namespace App\Support;

use App\Models\Project;

/**
 * Proje turune gore ozel modul anahtarlarinin tek kaynagi (Diplomasi, Pergel, KPD, KADEME+, Zirve, Eurodesk).
 */
final class ProjectSpecialModuleCatalog
{
    public static function normalizeHaystack(?string $type, ?string $name, ?string $slug): string
    {
        return mb_strtolower(trim(implode(' ', array_filter([$type, $name, $slug]))));
    }

    /**
     * @return list<string>
     */
    public static function moduleKeys(?string $type, ?string $name, ?string $slug): array
    {
        $h = self::normalizeHaystack($type, $name, $slug);

        if (str_contains($h, 'diplomasi')) {
            return ['digital_bohca', 'internships', 'uploaded_files'];
        }

        if (str_contains($h, 'pergel')) {
            return ['digital_bohca', 'mentors', 'assignments'];
        }

        if (str_contains($h, 'kpd') || str_contains($h, 'psikolojik')) {
            return ['digital_bohca', 'kpd_appointments', 'kpd_reports'];
        }

        if (str_contains($h, 'zirve')) {
            return ['digital_bohca', 'badges', 'reward_tiers', 'participants_by_module'];
        }

        if (str_contains($h, 'kademe_plus') || str_contains($h, 'kademe-plus') || str_contains($h, 'kademe plus') || str_contains($h, 'kademe+')) {
            return ['digital_bohca', 'badges', 'reward_tiers', 'participants_by_module'];
        }

        if (str_contains($h, 'eurodesk')) {
            return ['digital_bohca', 'eurodesk_projects'];
        }

        return ['digital_bohca'];
    }

    /**
     * @return list<string>
     */
    public static function forProject(Project $project): array
    {
        return self::moduleKeys($project->type, $project->name, $project->slug);
    }

    /**
     * KADEME+ rozet kapsami, dashboard ozeti ve liderlik tablosu (KADEME+ ve Zirve Kademe).
     */
    public static function usesKademePlusStyleBadges(Project $project): bool
    {
        return in_array('participants_by_module', self::forProject($project), true);
    }

    /**
     * Panelde KADEME+ modul (ProjectModule) CRUD ve ogrenci kayit uclari.
     */
    public static function supportsKademeModuleWorkflow(Project $project): bool
    {
        return self::usesKademePlusStyleBadges($project);
    }
}
