<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UnifiedPanelAliasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function actingSuperAdmin(): User
    {
        $user = User::factory()->create([
            'name' => 'Panel',
            'surname' => 'Admin',
            'email' => 'panel-super@test.local',
            'role' => 'super_admin',
        ]);

        Role::findOrCreate('super_admin', 'web');
        $user->assignRole('super_admin');
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_panel_alias_periods_index_is_available(): void
    {
        $this->actingSuperAdmin();

        $response = $this->getJson('/api/panel/periods');

        $response->assertOk();
        $response->assertJsonStructure(['periods']);
    }

    public function test_admin_and_panel_periods_endpoints_are_both_kept_for_compatibility(): void
    {
        $this->actingSuperAdmin();

        $this->getJson('/api/admin/periods')->assertOk();
        $this->getJson('/api/panel/periods')->assertOk();
    }

    public function test_panel_alias_actions_are_written_to_admin_actions_audit_log(): void
    {
        $this->actingSuperAdmin();

        $response = $this->getJson('/api/panel/periods');
        $response->assertOk();

        $log = Activity::query()
            ->where('log_name', 'admin_actions')
            ->where('description', 'like', 'admin_action.get.%')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('api/panel/periods', $log->properties['path'] ?? null);
        $this->assertSame('periods.view', $log->properties['permission_checked'] ?? null);
    }
}
