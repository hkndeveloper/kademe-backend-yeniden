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
}
