<?php

namespace Tests\Feature;

use App\Models\FinancialTransaction;
use App\Models\RolePermissionScope;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    public function test_panel_activity_log_export_respects_requested_format(): void
    {
        Carbon::setTestNow('2026-06-07 12:00:00');
        $this->actingSuperAdmin();

        Activity::query()->create([
            'log_name' => 'admin_actions',
            'description' => 'admin_action.test',
            'event' => 'test',
            'properties' => [],
        ]);

        $response = $this->get('/api/panel/dashboard/activity-logs/export?format=xlsx');

        $response->assertOk();
        $this->assertStringContainsString(
            'islem_loglari_20260607_120000.xlsx',
            (string) $response->headers->get('content-disposition')
        );
    }

    public function test_custom_role_with_action_gets_the_same_result_from_admin_and_panel_aliases(): void
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
        $this->getJson('/api/admin/periods')->assertOk();
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

    public function test_panel_audit_log_sanitizes_sensitive_query_values(): void
    {
        $this->actingSuperAdmin();

        $this->getJson('/api/panel/periods?token=secret-token&search=donem')->assertOk();

        $log = Activity::query()
            ->where('log_name', 'admin_actions')
            ->where('description', 'like', 'admin_action.get.%periods%')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $properties = $log->properties->toArray();
        $this->assertSame('[redacted]', data_get($properties, 'query.token'));
        $this->assertSame('donem', data_get($properties, 'query.search'));
        $this->assertNotEmpty(data_get($properties, 'request_id'));
        $this->assertIsInt(data_get($properties, 'duration_ms'));
    }

    public function test_panel_activity_logs_are_paginated_and_filterable(): void
    {
        $this->actingSuperAdmin();

        Activity::query()->create([
            'log_name' => 'admin_actions',
            'description' => 'admin_action.filtered.success',
            'event' => 'updated',
            'properties' => ['outcome' => 'success', 'status_code' => 200, 'path' => 'api/panel/example'],
        ]);
        Activity::query()->create([
            'log_name' => 'admin_actions',
            'description' => 'admin_action.filtered.failed',
            'event' => 'deleted',
            'properties' => ['outcome' => 'denied_or_failed', 'status_code' => 403, 'path' => 'api/panel/example'],
        ]);

        $response = $this->getJson('/api/panel/dashboard/activity-logs?log_name=admin_actions&event=updated&outcome=success&per_page=5')
            ->assertOk();

        $response->assertJsonPath('logs.total', 1);
        $response->assertJsonPath('logs.data.0.description', 'admin_action.filtered.success');
        $response->assertJsonPath('summary.total', 1);
        $this->assertContains('admin_actions', $response->json('filters.log_names'));
        $this->assertContains('updated', $response->json('filters.events'));
    }
}
