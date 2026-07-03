<?php

namespace Tests\Feature;

use App\Models\Trainer;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use App\Models\RolePermissionScope;
use Tests\TestCase;

class TrainerPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['services.resend.key' => null]);
    }

    public function test_trainer_module_requires_usable_scope(): void
    {
        $role = Role::findOrCreate('trainer_view_without_scope', 'web');
        $role->givePermissionTo('trainers.view');

        $user = User::factory()->create(['role' => 'visitor', 'surname' => 'NoScope', 'email' => 'trainer-no-scope@test.local']);
        $user->assignRole($role);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/panel/modules')->assertOk();

        $this->assertFalse(collect($response->json('modules'))->contains(fn (array $module) => $module['id'] === 'trainers'));
    }

    public function test_trainer_module_and_endpoints_work_with_action_scopes(): void
    {
        $user = $this->actingTrainerManager([
            'trainers.view',
            'trainers.create',
            'trainers.update',
            'trainers.comment',
            'trainers.email',
            'trainers.delete',
            'trainers.export',
        ]);

        $modules = $this->getJson('/api/panel/modules')->assertOk()->json('modules');
        $module = collect($modules)->firstWhere('id', 'trainers');

        $this->assertNotNull($module);
        $this->assertSame('people', $module['section']);
        $this->assertContains('trainers.comment', $module['enabled_actions']);

        $created = $this->postJson('/api/panel/trainers', [
            'first_name' => 'Ayse',
            'last_name' => 'Demir',
            'email' => 'ayse.demir@test.local',
            'phone' => '+90 555 000 00 00',
            'title' => 'Egitmen',
            'organization' => 'KADEME',
            'expertise' => 'Liderlik ve mentorluk',
            'status' => 'candidate',
            'bio' => 'Uzman egitmen profili.',
            'notes' => 'Ilk gorusme olumlu.',
        ])->assertCreated()->json('trainer');

        $this->assertSame('Ayse Demir', $created['full_name']);
        $this->assertDatabaseHas('trainers', [
            'id' => $created['id'],
            'created_by' => $user->id,
            'status' => 'candidate',
        ]);

        $this->putJson("/api/panel/trainers/{$created['id']}", [
            'first_name' => 'Ayse',
            'last_name' => 'Demir',
            'status' => 'active',
            'expertise' => 'Liderlik, mentorluk ve atolyeler',
        ])->assertOk();

        $this->patchJson("/api/panel/trainers/{$created['id']}/comment", [
            'kademe_comment' => 'Programlarda guvenilir ve katilimci iletisiminde guclu.',
        ])->assertOk();

        $this->postJson("/api/panel/trainers/{$created['id']}/email", [
            'subject' => 'Yeni egitim daveti',
            'body' => 'Merhaba, yeni egitim planlamasi icin sizinle iletisime gecmek isteriz.',
        ])->assertOk()->assertJsonPath('sent_count', 0);

        $this->assertDatabaseHas('communication_logs', [
            'type' => 'email',
            'sender_id' => $user->id,
            'subject' => 'Yeni egitim daveti',
            'status' => 'failed',
        ]);

        $this->getJson('/api/panel/trainers?search=Ayse')->assertOk()->assertJsonPath('trainers.data.0.email', 'ayse.demir@test.local');
        $this->deleteJson("/api/panel/trainers/{$created['id']}")->assertOk();
        $this->assertSoftDeleted('trainers', ['id' => $created['id']]);

        $auditDescriptions = Activity::query()
            ->where('log_name', 'admin_actions')
            ->where('subject_type', Trainer::class)
            ->where('subject_id', $created['id'])
            ->pluck('description')
            ->all();

        $this->assertContains('trainers.trainer_created', $auditDescriptions);
        $this->assertContains('trainers.trainer_updated', $auditDescriptions);
        $this->assertContains('trainers.trainer_comment_updated', $auditDescriptions);
        $this->assertContains('trainers.trainer_email_sent', $auditDescriptions);
        $this->assertContains('trainers.trainer_deleted', $auditDescriptions);

        $emailAudit = Activity::query()
            ->where('description', 'trainers.trainer_email_sent')
            ->latest()
            ->firstOrFail();
        $emailProperties = $emailAudit->properties->toArray();

        $this->assertSame('trainer_email_sent', data_get($emailProperties, 'domain.operation'));
        $this->assertSame('Yeni egitim daveti', data_get($emailProperties, 'domain.subject'));
        $this->assertArrayHasKey('request_id', $emailProperties);
        $this->assertArrayHasKey('duration_ms', $emailProperties);
    }

    public function test_trainer_comment_requires_comment_action(): void
    {
        $this->actingTrainerManager(['trainers.view', 'trainers.update']);
        $trainer = Trainer::query()->create(['first_name' => 'Hasan', 'email' => 'hasan@test.local']);

        $this->patchJson("/api/panel/trainers/{$trainer->id}/comment", [
            'kademe_comment' => 'Yetkisiz yorum.',
        ])->assertForbidden();
    }

    private function actingTrainerManager(array $permissions): User
    {
        $role = Role::findOrCreate('trainer_manager_' . count($permissions), 'web');
        $role->givePermissionTo($permissions);

        foreach ($permissions as $permission) {
            RolePermissionScope::query()->create([
                'role_name' => $role->name,
                'permission_name' => $permission,
                'scope_type' => 'all',
                'scope_payload' => [],
            ]);
        }

        $user = User::factory()->create(['role' => 'visitor', 'surname' => 'TrainerManager', 'email' => uniqid('trainer-manager-', true) . '@test.local']);
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }
}