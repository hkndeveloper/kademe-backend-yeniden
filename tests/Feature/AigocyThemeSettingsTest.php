<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AigocyThemeSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function theme(): array
    {
        return json_decode(file_get_contents(resource_path('theme/aigocy.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function admin(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['surname' => 'Test', 'role' => 'super_admin', 'status' => 'active']);
        $admin->assignRole('super_admin');
        Sanctum::actingAs($admin);
    }

    public function test_public_theme_has_all_sections_without_fabricated_people_or_awards(): void
    {
        $response = $this->getJson('/api/site-config')->assertOk();
        $sections = collect($response->json('settings.theme.sections'));
        $this->assertCount(10, $sections);
        foreach (['team', 'partners', 'testimonials', 'awards'] as $id) {
            $this->assertSame([], $sections->firstWhere('id', $id)['items']);
        }
        $response->assertJsonPath('settings.theme.home_variant', '1');
    }

    public function test_admin_can_save_empty_sections_and_dynamic_cards_and_invalidate_homepage_cache(): void
    {
        $this->admin();
        $this->getJson('/api/homepage')->assertOk();
        $theme = $this->theme();
        $theme['home_variant'] = '2';
        $theme['home_block_order'] = ['projects', 'hero'];
        $theme['sections'][6]['items'] = [[
            'id' => 'member-1', 'title' => 'Yayın İzinli Kişi', 'subtitle' => 'Mentor',
            'description' => 'Türkçe karakterler: ğ, ş, ı, İ, ö, ü, ç.',
            'image_url' => '/images/portrait.jpg', 'href' => '/about',
        ]];
        $this->putJson('/api/panel/site-settings', ['settings' => ['theme' => $theme]])
            ->assertOk()->assertJsonPath('settings.theme.home_variant', '2');
        $response = $this->getJson('/api/homepage')->assertOk();
        $this->assertSame('Yayın İzinli Kişi', collect($response->json('settings.theme.sections'))->firstWhere('id', 'team')['items'][0]['title']);
        $this->assertSame(['projects', 'hero'], array_slice($response->json('settings.theme.home_block_order'), 0, 2));
        $this->assertCount(22, $response->json('settings.theme.home_block_order'));
    }

    public function test_executable_links_and_unknown_item_fields_are_rejected(): void
    {
        $this->admin();
        $theme = $this->theme();
        $theme['sections'][0]['items'] = [['id' => 'unsafe', 'title' => 'Unsafe', 'href' => 'javascript:alert(1)']];
        $this->putJson('/api/panel/site-settings', ['settings' => ['theme' => $theme]])
            ->assertUnprocessable()->assertJsonValidationErrors('settings.theme.sections.0.items.0.href');
        $theme['sections'][0]['items'][0] = ['id' => 'unknown', 'title' => 'Unknown', 'private_email' => 'private@example.test'];
        $this->putJson('/api/panel/site-settings', ['settings' => ['theme' => $theme]])->assertUnprocessable();
    }

    public function test_theme_update_requires_existing_global_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $student = User::factory()->create(['surname' => 'Test', 'role' => 'student', 'status' => 'active']);
        $student->assignRole('student');
        Sanctum::actingAs($student);
        $this->putJson('/api/panel/site-settings', ['settings' => ['theme' => $this->theme()]])->assertForbidden();
    }

    public function test_empty_optional_copy_is_normalized_and_duplicate_card_ids_are_rejected(): void
    {
        $this->admin();
        $theme = $this->theme();
        $theme['hero_background_url'] = null;
        $theme['video_url'] = null;
        $theme['sections'][0]['description'] = null;
        $this->putJson('/api/panel/site-settings', ['settings' => ['theme' => $theme]])
            ->assertOk()->assertJsonPath('settings.theme.hero_background_url', '')
            ->assertJsonPath('settings.theme.video_url', '')
            ->assertJsonPath('settings.theme.sections.0.description', '');
        $theme['sections'][0]['items'] = [
            ['id' => 'same', 'title' => 'Birinci kart'],
            ['id' => 'same', 'title' => 'İkinci kart'],
        ];
        $this->putJson('/api/panel/site-settings', ['settings' => ['theme' => $theme]])
            ->assertUnprocessable()->assertJsonValidationErrors('settings.theme.sections.0.items');
    }

    public function test_malformed_legacy_settings_fall_back_without_breaking_public_pages(): void
    {
        SystemSetting::query()->create(['group' => 'theme', 'key' => 'sections', 'value' => json_encode([['id' => 'team', 'enabled' => 'false', 'items' => 'invalid']])]);
        $response = $this->getJson('/api/site-config')->assertOk();
        $team = collect($response->json('settings.theme.sections'))->firstWhere('id', 'team');
        $this->assertFalse($team['enabled']);
        $this->assertSame([], $team['items']);
    }
}
