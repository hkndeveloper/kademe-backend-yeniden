<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\CommunicationLog;
use App\Models\Period;
use App\Models\Project;
use App\Models\RolePermissionScope;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OperationsStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_status_is_global_only_and_excludes_secret_or_personal_payloads(): void
    {
        $this->getJson('/api/panel/dashboard/operations-status')->assertUnauthorized();

        $role = Role::findOrCreate('scoped_operations_viewer', 'web');
        $role->givePermissionTo('logs.view');
        RolePermissionScope::query()->create([
            'role_name' => $role->name,
            'permission_name' => 'logs.view',
            'scope_type' => 'own_projects',
            'scope_payload' => [],
        ]);
        $scoped = User::factory()->create(['role' => 'coordinator', 'surname' => 'Scoped']);
        $scoped->assignRole($role);
        Sanctum::actingAs($scoped);
        $this->getJson('/api/panel/dashboard/operations-status')->assertForbidden();

        $admin = User::factory()->create(['role' => 'super_admin', 'surname' => 'Admin']);
        $admin->assignRole('super_admin');
        Sanctum::actingAs($admin);

        $project = Project::query()->create([
            'name' => 'Gözlem Projesi',
            'slug' => 'gozlem-projesi',
            'type' => 'other',
            'status' => 'active',
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Gözlem',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        SystemSetting::query()->create(['key' => 'google_calendar_refresh_token', 'value' => 'secret-refresh-token']);
        SystemSetting::query()->create(['key' => 'google_calendar_last_synced_at', 'value' => now()->toIso8601String()]);
        SystemSetting::query()->create(['key' => 'operations_scheduler_last_tick_at', 'value' => now()->toIso8601String()]);
        CommunicationLog::query()->create([
            'type' => 'email', 'sender_id' => $admin->id, 'recipients_count' => 1,
            'content' => 'private-message-body', 'status' => 'failed', 'project_id' => $project->id,
        ]);
        Application::query()->create([
            'user_id' => $scoped->id, 'project_id' => $project->id, 'period_id' => $period->id,
            'status' => 'waitlisted', 'waitlist_invited_at' => now(),
            'waitlist_invitation_delivery_status' => 'unknown',
        ]);
        config()->set('queue.default', 'database');
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => 'private-job-payload', 'attempts' => 0,
            'available_at' => now()->timestamp, 'created_at' => now()->timestamp,
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => 'failed-operations-test', 'connection' => 'database', 'queue' => 'default',
            'payload' => 'private-failed-payload', 'exception' => 'private-exception',
            'failed_at' => now(),
        ]);

        $response = $this->getJson('/api/panel/dashboard/operations-status')->assertOk()
            ->assertJsonPath('google_calendar.connected', true)
            ->assertJsonPath('email.failed_24h', 1)
            ->assertJsonPath('waitlist.unknown', 1)
            ->assertJsonPath('queue.pending', 1)
            ->assertJsonPath('queue.failed_total', 1)
            ->assertJsonPath('queue.last_success_at', null)
            ->assertJsonStructure(['scheduler' => ['last_tick_at']]);

        foreach (['secret-refresh-token', 'private-message-body', 'private-job-payload', 'private-failed-payload', 'private-exception'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }

        $this->getJson('/api/admin/dashboard/operations-status')->assertOk()
            ->assertJsonPath('queue.pending', 1);
        config()->set('queue.default', 'sync');
        config()->set('queue.failed.driver', 'null');
        $this->getJson('/api/panel/dashboard/operations-status')->assertOk()
            ->assertJsonPath('queue.pending', null)
            ->assertJsonPath('queue.failed_total', null);
    }
}
