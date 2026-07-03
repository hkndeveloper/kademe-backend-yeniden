<?php

namespace App\Support;

final class ProjectFamilyCatalog
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'diplomasi360' => [
                'key' => 'diplomasi360',
                'label' => 'Diplomasi360',
                'project_types' => ['diplomasi360'],
                'special_modules' => ['internships', 'uploaded_files'],
                'permissions' => ['projects.internships.view', 'projects.internships.manage'],
                'tabs' => [
                    ['id' => 'overview', 'label' => 'Ozet', 'permissions' => ['projects.internships.view', 'projects.internships.manage']],
                    ['id' => 'internships', 'label' => 'Stajlar', 'permissions' => ['projects.internships.view', 'projects.internships.manage']],
                    ['id' => 'files', 'label' => 'Dosyalar', 'permissions' => ['projects.internships.manage']],
                ],
            ],
            'pergel' => [
                'key' => 'pergel',
                'label' => 'Pergel Fellowship',
                'project_types' => ['pergel_fellowship'],
                'special_modules' => ['mentors'],
                'permissions' => ['projects.mentors.view', 'projects.mentors.manage'],
                'tabs' => [
                    ['id' => 'overview', 'label' => 'Ozet', 'permissions' => ['projects.mentors.view', 'projects.mentors.manage']],
                    ['id' => 'mentors', 'label' => 'Mentorler', 'permissions' => ['projects.mentors.view', 'projects.mentors.manage']],
                    ['id' => 'assignments', 'label' => 'Eslestirmeler', 'permissions' => ['projects.mentors.manage']],
                ],
            ],
            'eurodesk' => [
                'key' => 'eurodesk',
                'label' => 'Eurodesk',
                'project_types' => ['eurodesk'],
                'special_modules' => ['eurodesk_projects'],
                'permissions' => ['projects.eurodesk.view', 'projects.eurodesk.manage'],
                'tabs' => [
                    ['id' => 'overview', 'label' => 'Ozet', 'permissions' => ['projects.eurodesk.view', 'projects.eurodesk.manage']],
                    ['id' => 'projects', 'label' => 'Projeler', 'permissions' => ['projects.eurodesk.view', 'projects.eurodesk.manage']],
                    ['id' => 'partnerships', 'label' => 'Ortakliklar', 'permissions' => ['projects.eurodesk.manage']],
                ],
            ],
            'kademe-plus' => [
                'key' => 'kademe-plus',
                'label' => 'KADEME+',
                'project_types' => ['kademe_plus'],
                'special_modules' => ['badges', 'reward_tiers', 'participants_by_module'],
                'permissions' => ['projects.rewards.view', 'projects.rewards.manage'],
                'tabs' => [
                    ['id' => 'overview', 'label' => 'Ozet', 'permissions' => ['projects.rewards.view', 'projects.rewards.manage']],
                    ['id' => 'badges', 'label' => 'Rozetler', 'permissions' => ['projects.rewards.view', 'projects.rewards.manage']],
                    ['id' => 'rewards', 'label' => 'Oduller', 'permissions' => ['projects.rewards.view', 'projects.rewards.manage']],
                    ['id' => 'modules', 'label' => 'Moduller', 'permissions' => ['projects.rewards.view', 'projects.rewards.manage']],
                ],
            ],
            'zirve-kademe' => [
                'key' => 'zirve-kademe',
                'label' => 'Zirve Kademe',
                'project_types' => ['zirve_kademe'],
                'special_modules' => ['badges', 'reward_tiers', 'participants_by_module'],
                'permissions' => ['projects.rewards.view', 'projects.rewards.manage'],
                'tabs' => [
                    ['id' => 'overview', 'label' => 'Ozet', 'permissions' => ['projects.rewards.view', 'projects.rewards.manage']],
                    ['id' => 'badges', 'label' => 'Rozetler', 'permissions' => ['projects.rewards.view', 'projects.rewards.manage']],
                    ['id' => 'rewards', 'label' => 'Oduller', 'permissions' => ['projects.rewards.view', 'projects.rewards.manage']],
                    ['id' => 'modules', 'label' => 'Moduller', 'permissions' => ['projects.rewards.view', 'projects.rewards.manage']],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }
}
