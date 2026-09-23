<?php

namespace Tests\Unit;

use App\Models\CoordinationUnitMembership;
use App\Models\Project;
use App\Support\ProjectUnitPermissionTemplateCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProjectUnitPermissionTemplateCatalogTest extends TestCase
{
    public static function projectFamilies(): array
    {
        return [
            'Diplomasi360' => ['diplomasi360', ['projects.internships.view'], ['projects.mentors.view', 'assignments.view', 'kpd.appointments.view']],
            'KADEME+' => ['kademe_plus', ['projects.rewards.view'], ['projects.internships.view', 'assignments.view', 'kpd.appointments.view']],
            'Eurodesk' => ['eurodesk', ['projects.eurodesk.view'], ['projects.rewards.view', 'assignments.view', 'kpd.appointments.view']],
            'Pergel' => ['pergel_fellowship', ['projects.mentors.view', 'assignments.view'], ['projects.internships.view', 'projects.rewards.view', 'kpd.appointments.view']],
            'KPD' => ['kpd', ['kpd.appointments.view', 'kpd.reports.view'], ['projects.internships.view', 'assignments.view', 'projects.rewards.view']],
            'Zirve' => ['zirve_kademe', ['projects.rewards.view'], ['projects.mentors.view', 'assignments.view', 'kpd.appointments.view']],
        ];
    }

    #[DataProvider('projectFamilies')]
    public function test_each_project_gets_only_its_own_family_permissions(
        string $type,
        array $expected,
        array $forbidden
    ): void {
        $project = new Project([
            'type' => $type,
            'name' => $type,
            'slug' => $type,
        ]);

        foreach ([
            CoordinationUnitMembership::POSITION_COORDINATOR,
            CoordinationUnitMembership::POSITION_STAFF,
        ] as $position) {
            $permissions = ProjectUnitPermissionTemplateCatalog::permissionsFor($project, $position);

            foreach ($expected as $permission) {
                $this->assertContains($permission, $permissions, $type.' '.$position.' '.$permission);
            }
            foreach ($forbidden as $permission) {
                $this->assertNotContains($permission, $permissions, $type.' '.$position.' '.$permission);
            }
        }
    }

    public function test_metadata_can_enable_assignments_for_a_future_project_without_code_change(): void
    {
        $project = new Project([
            'type' => 'other',
            'name' => 'Future Project',
            'slug' => 'future-project',
            'special_modules' => ['assignments'],
        ]);

        $coordinator = ProjectUnitPermissionTemplateCatalog::permissionsFor(
            $project,
            CoordinationUnitMembership::POSITION_COORDINATOR
        );

        $this->assertContains('assignments.view', $coordinator);
        $this->assertContains('assignments.submissions.review', $coordinator);
        $this->assertNotContains('projects.mentors.view', $coordinator);
    }
}
