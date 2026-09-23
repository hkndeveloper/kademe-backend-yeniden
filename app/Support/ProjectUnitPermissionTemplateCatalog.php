<?php

namespace App\Support;

use App\Models\CoordinationUnitMembership;
use App\Models\Project;

/**
 * Proje birimi izinlerini proje metadata'sindaki ozel modullerle eslestirir.
 * Ortak proje cekirdegi bu katalogda degil, ana birim sablonunda tutulur.
 */
final class ProjectUnitPermissionTemplateCatalog
{
    /**
     * @return array<string, array{coordinator: list<string>, staff: list<string>}>
     */
    public static function modulePermissionMap(): array
    {
        return [
            'digital_bohca' => [
                'coordinator' => ['digital_bohca.view', 'digital_bohca.create', 'digital_bohca.delete'],
                'staff' => ['digital_bohca.view', 'digital_bohca.create'],
            ],
            'internships' => [
                'coordinator' => ['projects.internships.view', 'projects.internships.manage'],
                'staff' => ['projects.internships.view'],
            ],
            'uploaded_files' => [
                'coordinator' => ['projects.internships.view', 'projects.internships.manage'],
                'staff' => ['projects.internships.view'],
            ],
            'mentors' => [
                'coordinator' => ['projects.mentors.view', 'projects.mentors.manage'],
                'staff' => ['projects.mentors.view'],
            ],
            'assignments' => [
                'coordinator' => [
                    'assignments.view', 'assignments.create', 'assignments.update', 'assignments.delete',
                    'assignments.submissions.view', 'assignments.submissions.review',
                ],
                'staff' => [
                    'assignments.view', 'assignments.create', 'assignments.update',
                    'assignments.submissions.view',
                ],
            ],
            'eurodesk_projects' => [
                'coordinator' => ['projects.eurodesk.view', 'projects.eurodesk.manage'],
                'staff' => ['projects.eurodesk.view'],
            ],
            'badges' => [
                'coordinator' => ['projects.rewards.view', 'projects.rewards.manage'],
                'staff' => ['projects.rewards.view'],
            ],
            'reward_tiers' => [
                'coordinator' => ['projects.rewards.view', 'projects.rewards.manage'],
                'staff' => ['projects.rewards.view'],
            ],
            'participants_by_module' => [
                'coordinator' => ['projects.rewards.view', 'projects.rewards.manage'],
                'staff' => ['projects.rewards.view'],
            ],
            'kpd_appointments' => [
                'coordinator' => ['kpd.appointments.view', 'kpd.appointments.manage'],
                'staff' => ['kpd.appointments.view'],
            ],
            'kpd_reports' => [
                'coordinator' => ['kpd.reports.view', 'kpd.reports.create', 'kpd.reports.delete'],
                'staff' => ['kpd.reports.view'],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function permissionsFor(Project $project, string $position): array
    {
        if (! in_array($position, [
            CoordinationUnitMembership::POSITION_COORDINATOR,
            CoordinationUnitMembership::POSITION_STAFF,
        ], true)) {
            return [];
        }

        return collect(ProjectSpecialModuleCatalog::forProject($project))
            ->flatMap(fn (string $module) => self::modulePermissionMap()[$module][$position] ?? [])
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public static function permissionUniverse(): array
    {
        return collect(self::modulePermissionMap())
            ->flatMap(fn (array $positions) => collect($positions)->flatten())
            ->unique()
            ->values()
            ->all();
    }
}
