<?php

namespace Tests\Unit;

use App\Models\Project;
use App\Support\ProjectSpecialModuleCatalog;
use PHPUnit\Framework\TestCase;

class ProjectSpecialModuleCatalogTest extends TestCase
{
    public function test_zirve_kademe_type_gets_gamification_modules(): void
    {
        $project = new Project([
            'type' => 'zirve_kademe',
            'name' => 'Zirve',
            'slug' => 'zirve-2026',
        ]);

        $keys = ProjectSpecialModuleCatalog::forProject($project);

        $this->assertContains('participants_by_module', $keys);
        $this->assertContains('badges', $keys);
        $this->assertTrue(ProjectSpecialModuleCatalog::supportsKademeModuleWorkflow($project));
    }

    public function test_diplomasi360_keys(): void
    {
        $keys = ProjectSpecialModuleCatalog::moduleKeys('diplomasi360', 'Diplomasi360', 'diplomasi360');

        $this->assertSame(['digital_bohca', 'internships', 'uploaded_files'], $keys);
    }

    public function test_explicit_metadata_overrides_type_defaults_and_empty_list_disables_special_modules(): void
    {
        $custom = new Project([
            'type' => 'diplomasi360',
            'name' => 'Custom',
            'slug' => 'custom',
            'special_modules' => ['digital_bohca', 'assignments', 'unknown'],
        ]);
        $disabled = new Project([
            'type' => 'pergel_fellowship',
            'name' => 'Disabled',
            'slug' => 'disabled',
            'special_modules' => [],
        ]);

        $this->assertSame(['digital_bohca', 'assignments'], ProjectSpecialModuleCatalog::forProject($custom));
        $this->assertSame([], ProjectSpecialModuleCatalog::forProject($disabled));
    }

    public function test_unknown_other_project_does_not_inherit_every_project_family(): void
    {
        $this->assertSame(
            ['digital_bohca'],
            ProjectSpecialModuleCatalog::moduleKeys('other', 'Yeni Proje', 'yeni-proje')
        );
    }
}
