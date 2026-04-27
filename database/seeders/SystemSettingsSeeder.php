<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SystemSetting;
use App\Models\KpdRoom;

class SystemSettingsSeeder extends Seeder
{
    public function run(): void
    {
        // Temel ayarlar
        $settings = [
            ['key' => 'site_name', 'value' => 'KADEME', 'group' => 'general'],
            ['key' => 'contact_email', 'value' => 'info@kademe.org', 'group' => 'contact'],
            ['key' => 'facebook_url', 'value' => 'https://facebook.com/kademe', 'group' => 'social_media'],
            ['key' => 'instagram_url', 'value' => 'https://instagram.com/kademe', 'group' => 'social_media'],
            ['key' => 'twitter_url', 'value' => 'https://twitter.com/kademe', 'group' => 'social_media'],
        ];

        foreach ($settings as $setting) {
            SystemSetting::firstOrCreate(['key' => $setting['key']], $setting);
        }

        // KPD Odaları
        KpdRoom::firstOrCreate(['name' => 'room_1'], ['description' => 'Ana Danışmanlık Odası']);
        KpdRoom::firstOrCreate(['name' => 'room_2'], ['description' => 'Özel Test ve Görüşme Odası']);
    }
}
