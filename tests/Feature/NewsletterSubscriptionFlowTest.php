<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\NewsletterController;
use App\Models\NewsletterSubscriber;
use App\Models\User;
use App\Services\NotificationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NewsletterSubscriptionFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_public_subscription_unsubscription_and_admin_list_export_follow_the_same_active_order(): void
    {
        $this->mock(NotificationService::class)
            ->shouldReceive('sendEmail')
            ->twice()
            ->andReturn(1);

        $first = $this->postJson('/api/newsletter/subscribe', [
            'email' => 'first@example.test', 'name' => 'First',
        ])->assertOk()->json('subscriber.id');
        $second = $this->postJson('/api/newsletter/subscribe', [
            'email' => 'second@example.test', 'name' => 'Second',
        ])->assertOk()->json('subscriber.id');
        $this->assertSame(2, NewsletterSubscriber::query()->count());
        NewsletterSubscriber::query()->whereIn('id', [$first, $second])
            ->update(['subscribed_at' => now()->startOfSecond()]);

        $admin = User::factory()->create(['role' => 'super_admin', 'surname' => 'Newsletter']);
        Role::findOrCreate('super_admin', 'web');
        $admin->assignRole('super_admin');
        Sanctum::actingAs($admin);

        $this->getJson('/api/panel/newsletter/subscribers')->assertOk()
            ->assertJsonPath('subscribers.data.0.id', $second)
            ->assertJsonPath('subscribers.data.1.id', $first);
        $csv = $this->get('/api/panel/newsletter/subscribers/export?format=csv')->assertOk()->streamedContent();
        $rows = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertSame((string) $second, str_getcsv($rows[1])[0]);
        $this->assertSame((string) $first, str_getcsv($rows[2])[0]);

        $parts = parse_url(NewsletterController::generateUnsubscribeUrl('first@example.test'));
        parse_str($parts['query'] ?? '', $query);
        $this->getJson('/api/newsletter/unsubscribe?'.http_build_query(array_merge($query, ['token' => 'wrong'])))
            ->assertForbidden();
        $this->getJson('/api/newsletter/unsubscribe?'.http_build_query($query))->assertOk();
        $this->assertNotNull(NewsletterSubscriber::findOrFail($first)->unsubscribed_at);
        $this->getJson('/api/panel/newsletter/subscribers')->assertOk()
            ->assertJsonCount(1, 'subscribers.data')
            ->assertJsonPath('subscribers.data.0.id', $second);
        $allCsv = $this->get('/api/panel/newsletter/subscribers/export?format=csv&only_active=false')
            ->assertOk()->streamedContent();
        $this->assertStringContainsString('first@example.test', $allCsv);
    }
}
