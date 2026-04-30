<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\RolePermissionScope;
use App\Models\FinancialTransaction;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
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

    public function test_custom_role_with_action_can_use_unified_panel_without_system_role(): void
    {
        Permission::findOrCreate('periods.view', 'web');
        $role = Role::findOrCreate('period_viewer', 'web');
        $role->givePermissionTo('periods.view');

        RolePermissionScope::query()->create([
            'role_name' => 'period_viewer',
            'permission_name' => 'periods.view',
            'scope_type' => 'all',
            'scope_payload' => [],
        ]);

        $user = User::factory()->create([
            'name' => 'Custom',
            'surname' => 'Role',
            'email' => 'custom-panel@test.local',
            'role' => 'visitor',
        ]);
        $user->assignRole('period_viewer');
        Sanctum::actingAs($user);

        $this->getJson('/api/panel/periods')->assertOk();
        $this->getJson('/api/admin/periods')->assertForbidden();
    }

    public function test_global_scope_is_not_tied_to_super_admin_role(): void
    {
        Permission::findOrCreate('financial.view', 'web');
        $role = Role::findOrCreate('finance_global_viewer', 'web');
        $role->givePermissionTo('financial.view');

        RolePermissionScope::query()->create([
            'role_name' => 'finance_global_viewer',
            'permission_name' => 'financial.view',
            'scope_type' => 'all',
            'scope_payload' => [],
        ]);

        $submitter = User::factory()->create([
            'surname' => 'Submitter',
            'role' => 'staff',
        ]);
        FinancialTransaction::query()->create([
            'project_id' => null,
            'period_id' => null,
            'type' => 'expense',
            'category' => 'other',
            'payee_name' => 'Global Null Project Vendor',
            'amount' => 125,
            'status' => 'pending',
            'submitted_by' => $submitter->id,
            'submitted_at' => now(),
        ]);

        $viewer = User::factory()->create([
            'name' => 'Global',
            'surname' => 'Viewer',
            'email' => 'global-finance@test.local',
            'role' => 'visitor',
        ]);
        $viewer->assignRole('finance_global_viewer');
        Sanctum::actingAs($viewer);

        $this->getJson('/api/panel/financials')
            ->assertOk()
            ->assertJsonPath('transactions.data.0.payee_name', 'Global Null Project Vendor');
    }
}
