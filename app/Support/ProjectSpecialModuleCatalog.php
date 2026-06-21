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
     * Proje enum type'ına göre modül anahtarlarını döner.
     * Metin tabanlı substring yerine sabit enum değerleri kullanılır.
     *
     * @return list<string>
     */
    public static function moduleKeys(?string $type, ?string $name, ?string $slug): array
    {
        // Önce enum type ile eşleştir (kesin ve güvenli)
        $modules = self::modulesByType($type);
        if ($modules !== null) {
            return $modules;
        }

        // Fallback: eski sistemden gelen veya 'other' türündeki projeler için
        // isim/slug üzerinden geniş eşleştirme (geriye dönük uyumluluk)
        $h = self::normalizeHaystack(null, $name, $slug);

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
        if (str_contains($h, 'kademe')) {
            return ['digital_bohca', 'badges', 'reward_tiers', 'participants_by_module'];
        }
        if (str_contains($h, 'eurodesk')) {
            return ['digital_bohca', 'eurodesk_projects'];
        }

        return ['digital_bohca'];
    }

    /**
     * Project.type enum değerine göre modül listesini döner.
     * NULL döndürmesi "enum eşleşmesi yok, fallback kullan" demektir.
     *
     * @return list<string>|null
     */
    private static function modulesByType(?string $type): ?array
    {
        return match ($type) {
            'diplomasi360'     => ['digital_bohca', 'internships', 'uploaded_files'],
            'pergel_fellowship' => ['digital_bohca', 'mentors', 'assignments'],
            'kpd'              => ['digital_bohca', 'kpd_appointments', 'kpd_reports'],
            'zirve_kademe'     => ['digital_bohca', 'badges', 'reward_tiers', 'participants_by_module'],
            'kademe_plus'      => ['digital_bohca', 'badges', 'reward_tiers', 'participants_by_module'],
            'eurodesk'         => ['digital_bohca', 'eurodesk_projects'],
            'other'            => ['digital_bohca', 'internships', 'mentors', 'eurodesk_projects', 'reward_tiers'],
            null               => null, // fallback'e bırak
            default            => null,
        };
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
