<?php

namespace Tests\Feature;

use App\Models\RolePermissionScope;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardQuickAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_quick_announcement_requires_channel_permissions_for_email_and_sms(): void
    {
        $this->actingAnnouncementCreatorWithoutChannelPermissions();

        $this->postJson('/api/panel/announcements', [
            'title' => 'Hizli Duyuru',
            'content' => 'Dashboard hizli duyuru metni',
            'category' => 'Duyuru',
            'send_email' => false,
            'send_sms' => false,
        ])
            ->assertCreated()
            ->assertJsonPath('announcement.title', 'Hizli Duyuru');

        $this->postJson('/api/panel/announcements', [
            'title' => 'Yetkisiz E-posta',
            'content' => 'Bu kanal icin yetki gerekir',
            'send_email' => true,
        ])->assertForbidden();

        $this->postJson('/api/panel/announcements', [
            'title' => 'Yetkisiz SMS',
            'content' => 'Bu kanal icin yetki gerekir',
            'send_sms' => true,
        ])->assertForbidden();
    }

    private function actingAnnouncementCreatorWithoutChannelPermissions(): User
    {
        Permission::findOrCreate('announcements.create', 'web');
        $role = Role::findOrCreate('dashboard_quick_announcement_creator', 'web');
        $role->givePermissionTo('announcements.create');

        RolePermissionScope::query()->create([
            'role_name' => 'dashboard_quick_announcement_creator',
            'permission_name' => 'announcements.create',
            'scope_type' => 'all',
            'scope_payload' => [],
        ]);

        $actor = User::factory()->create([
            'name' => 'Quick',
            'surname' => 'Announcer',
            'role' => 'coordinator',
        ]);
        $actor->assignRole($role);
        Sanctum::actingAs($actor);

        return $actor;
    }
}
