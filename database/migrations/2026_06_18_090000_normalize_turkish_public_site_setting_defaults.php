<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<int, array{group: string, key: string, old: string, new: string}> */
    private array $replacements = [
        [
            'group' => 'about',
            'key' => 'faq_teaser_text',
            'old' => 'Sik sorulan sorular ve icerik akisi public tarafta erisilebilir.',
            'new' => 'Sık sorulan sorular ve içerik akışı public tarafta erişilebilir.',
        ],
        [
            'group' => 'navigation',
            'key' => 'header_login_label',
            'old' => 'Giris Yap',
            'new' => 'Giriş Yap',
        ],
        [
            'group' => 'general',
            'key' => 'site_tagline',
            'old' => 'Gelecegin Liderlik Okulu',
            'new' => 'Geleceğin Liderlik Okulu',
        ],
        [
            'group' => 'navigation',
            'key' => 'header_register_label',
            'old' => 'Basvur',
            'new' => 'Başvur',
        ],
        [
            'group' => 'homepage',
            'key' => 'hero_badge',
            'old' => 'KADEME: Gelecegin Liderlik Okulu',
            'new' => 'KADEME: Geleceğin Liderlik Okulu',
        ],
        [
            'group' => 'homepage',
            'key' => 'footer_copyright',
            'old' => '© 2026 KADEME YONETIM SISTEMI | T3 VAKFI. TUM HAKLARI SAKLIDIR.',
            'new' => '© 2026 KADEME YÖNETİM SİSTEMİ | T3 VAKFI. TÜM HAKLARI SAKLIDIR.',
        ],
        [
            'group' => 'homepage',
            'key' => 'hero_title_line_1',
            'old' => 'YETENEGINI',
            'new' => 'YETENEĞİNİ',
        ],
        [
            'group' => 'homepage',
            'key' => 'hero_title_line_2',
            'old' => 'KESFET',
            'new' => 'KEŞFET',
        ],
        [
            'group' => 'homepage',
            'key' => 'hero_title_line_3',
            'old' => 'GELECEGI',
            'new' => 'GELECEĞİ',
        ],
        [
            'group' => 'homepage',
            'key' => 'hero_title_line_4',
            'old' => 'YONET',
            'new' => 'YÖNET',
        ],
        [
            'group' => 'homepage',
            'key' => 'hero_description',
            'old' => 'T3 Vakfi bunyesinde, Turkiye ekosisteminde kapsamli kariyer ve yetenek gelisim programlarina dahil olun.',
            'new' => 'T3 Vakfı bünyesinde, Türkiye ekosisteminde kapsamlı kariyer ve yetenek gelişim programlarına dahil olun.',
        ],
        [
            'group' => 'homepage',
            'key' => 'hero_primary_label',
            'old' => 'Hemen Basvur',
            'new' => 'Hemen Başvur',
        ],
        [
            'group' => 'homepage',
            'key' => 'hero_secondary_label',
            'old' => 'Giris Yap',
            'new' => 'Giriş Yap',
        ],
        [
            'group' => 'about',
            'key' => 'blog_teaser_text',
            'old' => 'KADEME dunyasindan secili yazilar ve guncel icerikler burada yer alir.',
            'new' => 'KADEME dünyasından seçili yazılar ve güncel içerikler burada yer alır.',
        ],
        [
            'group' => 'about',
            'key' => 'journey_text',
            'old' => 'Projeler, faaliyetler, blog, SSS ve iletisim akislari birlikte KADEME\'nin public katmanini olusturur.',
            'new' => 'Projeler, faaliyetler, blog, SSS ve iletişim akışları birlikte KADEME\'nin public katmanını oluşturur.',
        ],
        [
            'group' => 'blog_page',
            'key' => 'description',
            'old' => 'Gelecegin yetenekleri icin hazirladigimiz makaleleri ve KADEME dunyasindaki son gelismeleri takip edin.',
            'new' => 'Geleceğin yetenekleri için hazırladığımız makaleleri ve KADEME dünyasındaki son gelişmeleri takip edin.',
        ],
        [
            'group' => 'blog_page',
            'key' => 'search_placeholder',
            'old' => 'Blog yazisi ara...',
            'new' => 'Blog yazısı ara...',
        ],
        [
            'group' => 'blog_page',
            'key' => 'empty_text',
            'old' => 'Secili arama kriterleriyle blog yazisi bulunamadi.',
            'new' => 'Seçili arama kriterleriyle blog yazısı bulunamadı.',
        ],
        [
            'group' => 'blog_page',
            'key' => 'read_more_label',
            'old' => 'Devamini Oku',
            'new' => 'Devamını Oku',
        ],
        [
            'group' => 'blog_page',
            'key' => 'detail_badge_label',
            'old' => 'Blog Detayi',
            'new' => 'Blog Detayı',
        ],
        [
            'group' => 'blog_page',
            'key' => 'detail_back_label',
            'old' => 'Tum blog yazilarina don',
            'new' => 'Tüm blog yazılarına dön',
        ],
        [
            'group' => 'blog_page',
            'key' => 'detail_empty_content',
            'old' => 'Bu yazi icin henuz icerik eklenmemis.',
            'new' => 'Bu yazı için henüz içerik eklenmemiş.',
        ],
        [
            'group' => 'faq_page',
            'key' => 'title',
            'old' => 'Sik Sorulan Sorular',
            'new' => 'Sıkça Sorulan Sorular',
        ],
        [
            'group' => 'faq_page',
            'key' => 'description',
            'old' => 'KADEME surecleri hakkinda merak ettiginiz temel konular burada toplanir.',
            'new' => 'KADEME süreçleri hakkında merak ettiğiniz temel konular burada toplanır.',
        ],
        [
            'group' => 'faq_page',
            'key' => 'empty_text',
            'old' => 'Henuz soru-cevap eklenmemis.',
            'new' => 'Henüz soru-cevap eklenmemiş.',
        ],
        [
            'group' => 'faq_page',
            'key' => 'contact_title',
            'old' => 'Baska bir sorunuz mu var?',
            'new' => 'Başka bir sorunuz mu var?',
        ],
        [
            'group' => 'faq_page',
            'key' => 'contact_description',
            'old' => 'Aradiginiz cevabi bulamadiysaniz iletisim veya destek kanalina gecebilirsiniz.',
            'new' => 'Aradığınız cevabı bulamadıysanız iletişim veya destek kanalına geçebilirsiniz.',
        ],
        [
            'group' => 'faq_page',
            'key' => 'contact_cta_label',
            'old' => 'Iletisime Gec',
            'new' => 'İletişime Geç',
        ],
    ];

    public function up(): void
    {
        foreach ($this->replacements as $replacement) {
            DB::table('system_settings')
                ->where('group', $replacement['group'])
                ->where('key', $replacement['key'])
                ->where('value', $replacement['old'])
                ->update([
                    'value' => $replacement['new'],
                    'updated_at' => now(),
                ]);
        }

        Cache::forget('public.homepage.v1');
    }

    public function down(): void
    {
        foreach ($this->replacements as $replacement) {
            DB::table('system_settings')
                ->where('group', $replacement['group'])
                ->where('key', $replacement['key'])
                ->where('value', $replacement['new'])
                ->update([
                    'value' => $replacement['old'],
                    'updated_at' => now(),
                ]);
        }

        Cache::forget('public.homepage.v1');
    }
};
