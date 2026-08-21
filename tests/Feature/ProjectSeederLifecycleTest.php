<?php

namespace Tests\Feature;

use Database\Seeders\ProjectSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectSeederLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_seeder_builds_idempotent_lifecycle_consistent_demo_projects(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(UserSeeder::class);
        $this->seed(ProjectSeeder::class);

        $this->assertDatabaseCount('projects', 6);
        $this->assertDatabaseCount('periods', 6);
        $this->assertDatabaseCount('application_windows', 6);
        $this->assertDatabaseCount('period_lifecycle_events', 12);
        $this->assertSame(0, DB::table('projects')->whereNull('current_period_id')->count());
        $this->assertSame(0, DB::table('periods')->where('status', '!=', 'active')->count());
        $this->assertSame(0, DB::table('application_windows')->where('is_open', false)->count());

        $this->seed(ProjectSeeder::class);

        $this->assertDatabaseCount('projects', 6);
        $this->assertDatabaseCount('periods', 6);
        $this->assertDatabaseCount('application_windows', 6);
        $this->assertDatabaseCount('period_lifecycle_events', 12);
    }
}
