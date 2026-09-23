<?php

namespace Tests\Feature;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolePermissionSeederBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_rerunning_seeder_preserves_existing_admin_role_decisions_by_default(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $staff = Role::findByName('staff', 'web');
        $staff->revokePermissionTo('programs.view');
        $staff->givePermissionTo('trainers.view');

        config()->set('permission_catalog.reset_default_role_permissions', false);
        $this->seed(RolePermissionSeeder::class);

        $staff = $staff->fresh();
        $this->assertFalse($staff->hasPermissionTo('programs.view'));
        $this->assertTrue($staff->hasPermissionTo('trainers.view'));
    }

    public function test_explicit_reset_restores_configured_role_defaults(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $staff = Role::findByName('staff', 'web');
        $staff->revokePermissionTo('programs.view');
        $staff->givePermissionTo(Permission::findByName('trainers.view', 'web'));

        config()->set('permission_catalog.reset_default_role_permissions', true);
        $this->seed(RolePermissionSeeder::class);

        $staff = $staff->fresh();
        $this->assertTrue($staff->hasPermissionTo('programs.view'));
        $this->assertFalse($staff->hasPermissionTo('trainers.view'));
    }
}
