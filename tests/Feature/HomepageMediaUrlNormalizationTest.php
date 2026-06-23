<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HomepageMediaUrlNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_rewrites_stored_r2_dev_urls_to_stable_custom_domain(): void
    {
        config()->set('filesystems.media_disk', 'r2');
        config()->set('filesystems.disks.r2.url', 'https://pub-589f223e143c4507ac7880af5db1dbc8.r2.dev');
        config()->set('filesystems.disks.r2.stable_url', 'https://img.hakankekec.me');
        config()->set('filesystems.disks.r2.legacy_urls', []);
        Cache::flush();

        SystemSetting::query()->create([
            'group' => 'homepage',
            'key' => 'intro_cards',
            'value' => json_encode([
                [
                    'title' => 'Kart',
                    'description' => 'Aciklama',
                    'image_url' => 'https://pub-589f223e143c4507ac7880af5db1dbc8.r2.dev/kademe-media/homepage/card.jpg',
                    'cta_label' => 'Git',
                    'cta_href' => '/',
                ],
            ]),
        ]);

        $this->getJson('/api/homepage')
            ->assertOk()
            ->assertJsonPath('settings.homepage.intro_cards.0.image_url', 'https://img.hakankekec.me/kademe-media/homepage/card.jpg');
    }
}