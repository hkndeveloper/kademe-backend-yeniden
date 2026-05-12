<?php

namespace Tests\Feature;

use App\Models\CommunicationLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AnnouncementDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_email_dispatch_fails_gracefully_when_provider_is_not_configured(): void
    {
        config()->set('services.resend.key', '');

        $admin = User::factory()->create([
            'role' => 'super_admin',
            'surname' => 'Admin',
            'email' => 'announcement-admin@test.local',
        ]);
        Role::findOrCreate('super_admin', 'web');
        $admin->assignRole('super_admin');

        $recipient = User::factory()->create([
            'role' => 'student',
            'surname' => 'Recipient',
            'status' => 'active',
            'email' => 'announcement-recipient@test.local',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/announcements/send-email', [
            'subject' => 'Test duyurusu',
            'body' => 'Gonderim konfigurasyon yokken patlamamali.',
            'user_ids' => [$recipient->id],
        ]);

        $response->assertOk()
            ->assertJson([
                'sent_to' => 0,
            ]);

        $this->assertDatabaseHas('communication_logs', [
            'type' => 'email',
            'sender_id' => $admin->id,
            'recipients_count' => 0,
            'subject' => 'Test duyurusu',
            'status' => 'failed',
        ]);

        $this->assertSame(1, CommunicationLog::query()->where('type', 'email')->count());
    }
}
