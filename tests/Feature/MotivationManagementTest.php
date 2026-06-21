<?php

namespace Tests\Feature;

use App\Models\MotivationList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MotivationManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_motivation_quote_is_served_publicly(): void
    {
        $list = MotivationList::query()->create([
            'name' => 'Haftalik Liste',
            'rotation_period' => 'weekly',
            'is_active' => true,
        ]);
        $list->quotes()->create([
            'quote' => 'Bugun ileriye bir adim daha.',
            'speaker' => 'KADEME',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->getJson('/api/motivation/current')
            ->assertOk()
            ->assertJsonPath('motivation.quote', 'Bugun ileriye bir adim daha.')
            ->assertJsonPath('motivation.rotation_period', 'weekly');
    }

    public function test_global_motivation_manager_can_create_list(): void
    {
        Permission::findOrCreate('motivation.view', 'web');
        Permission::findOrCreate('motivation.manage', 'web');
        $role = Role::findOrCreate('super_admin', 'web');
        $role->givePermissionTo(['motivation.view', 'motivation.manage']);

        $admin = User::factory()->create([
            'surname' => 'Admin',
            'role' => 'super_admin',
            'email' => 'motivation-admin@test.local',
        ]);
        $admin->assignRole($role);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/panel/motivation/lists', [
                'name' => 'Gunluk Enerji',
                'rotation_period' => 'daily',
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('list.name', 'Gunluk Enerji');

        $this->assertDatabaseHas('motivation_lists', [
            'name' => 'Gunluk Enerji',
            'rotation_period' => 'daily',
            'is_active' => true,
        ]);
    }
}
