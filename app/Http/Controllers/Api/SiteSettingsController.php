<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use App\Models\Participant;
use App\Models\Program;
use App\Models\Project;
use App\Models\SystemSetting;
use App\Services\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteSettingsController extends Controller
{
    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {
    }

    private function canViewAdminSettings(Request $request): bool
    {
        $user = $request->user();

        return $this->permissionResolver->hasPermission($user, 'settings.view')
            || $this->permissionResolver->hasPermission($user, 'content.site_settings.update');
    }

    private function canUpdateAdminSettings(Request $request): bool
    {
        $user = $request->user();

        return $this->permissionResolver->hasPermission($user, 'settings.update')
            || $this->permissionResolver->hasPermission($user, 'content.site_settings.update');
    }

    private function defaults(): array
    {
        return [
            'general' => [
                'site_name' => 'KADEME',
                'site_tagline' => 'Gelecegin Liderlik Okulu',
            ],
            'contact' => [
                'contact_email' => 'info@kademe.org',
                'contact_phone' => '0212 XXX XX XX',
                'contact_address' => 'T3 Vakfi Genel Merkezi, Istanbul, Turkiye',
            ],
            'social_media' => [
                'instagram_url' => '',
                'twitter_url' => '',
                'youtube_url' => '',
                'linkedin_url' => '',
            ],
            'navigation' => [
                'header_links' => [
                    ['label' => 'Ana Sayfa', 'href' => '/'],
                    ['label' => 'Hakkimizda', 'href' => '/about'],
                    ['label' => 'Faaliyetler', 'href' => '/activities'],
                    ['label' => 'SSS', 'href' => '/faq'],
                    ['label' => 'Iletisim', 'href' => '/contact'],
                ],
                'header_login_label' => 'Giris Yap',
                'header_register_label' => 'Basvur',
                'footer_quick_links' => [
                    ['label' => 'Hakkimizda', 'href' => '/about'],
                    ['label' => 'Projelerimiz', 'href' => '/projects'],
                    ['label' => 'SSS', 'href' => '/faq'],
                    ['label' => 'Iletisim', 'href' => '/contact'],
                ],
                'footer_project_links' => [],
            ],
            'homepage' => [
                'block_order' => ['hero', 'intro', 'stats', 'projects', 'activities', 'about', 'blog', 'newsletter'],
                'block_visibility' => [
                    'hero' => true,
                    'intro' => true,
                    'stats' => true,
                    'projects' => true,
                    'activities' => true,
                    'about' => true,
                    'blog' => true,
                    'newsletter' => true,
                ],
                'hero_badge' => 'KADEME: Gelecegin Liderlik Okulu',
                'hero_title_line_1' => 'YETENEGINI',
                'hero_title_line_2' => 'KESFET',
                'hero_title_line_3' => 'GELECEGI',
                'hero_title_line_4' => 'YONET',
                'hero_description' => 'T3 Vakfi bunyesinde, Turkiye ekosisteminde kapsamli kariyer ve yetenek gelisim programlarina dahil olun.',
                'hero_background_image_url' => '',
                'hero_primary_label' => 'Hemen Basvur',
                'hero_primary_href' => '/auth/register',
                'hero_secondary_label' => 'Giris Yap',
                'hero_secondary_href' => '/auth/login',
                'intro_cards' => [
                    [
                        'title' => 'Kariyer ve Liderlik Gelisimi',
                        'description' => 'KADEME, ogrencilerin ve mezunlarin farkli proje akislari icinde yeteneklerini gelistirebildigi cok katmanli bir ekosistem sunar.',
                        'image_url' => '',
                        'cta_label' => 'Hakkimizda',
                        'cta_href' => '/about',
                    ],
                    [
                        'title' => 'Proje Bazli Yolculuk',
                        'description' => 'Diplomasi360, KADEME+, Pergel Fellowship, KPD ve Eurodesk gibi alan odakli projeler tek merkezden yonetilir.',
                        'image_url' => '',
                        'cta_label' => 'Projeleri Incele',
                        'cta_href' => '/projects',
                    ],
                    [
                        'title' => 'Etkinlik ve Basvuru Akisi',
                        'description' => 'Yaklasan faaliyetler, blog yazilari, duyurular ve basvuru surecleri ayni dijital deneyim icinde sunulur.',
                        'image_url' => '',
                        'cta_label' => 'Faaliyetlere Git',
                        'cta_href' => '/activities',
                    ],
                ],
                'projects_title' => 'PROJELERIMIZ',
                'projects_description' => 'KADEME catisi altinda farkli alanlara ozel gelisim programlari.',
                'activities_title' => 'FAALIYETLERIMIZ',
                'activities_description' => 'Yaklasan etkinlikler, programlar ve proje bazli faaliyet ozeti.',
                'featured_activity_ids' => [],
                'about_teaser_title' => 'KADEME VE PROJE EKOSISTEMI',
                'about_teaser_description' => 'Mentorluk, psikolojik danismanlik, rozet sistemi, dijital bohca ve proje bazli etkinlik akislariyla cok katmanli bir gelisim yapisi sunuyoruz.',
                'about_teaser_image_url' => '',
                'blog_title' => 'GUNCEL BLOG',
                'blog_description' => 'KADEME dunyasindan son haberler ve makaleler.',
                'stats_mode' => 'auto',
                'featured_project_slugs' => [],
                'featured_blog_slugs' => [],
                'newsletter_title' => 'KADEME E-Bultenine Katil',
                'newsletter_description' => 'Yeni faaliyetler, proje duyurulari ve blog icerikleri yayinlandiginda ilk sen haberdar ol.',
                'footer_description' => 'T3 Vakfi Kariyer Gelisim Merkezi. Gelecegin liderlerini bugunden yetistiriyoruz.',
                'footer_copyright' => '© 2026 KADEME YONETIM SISTEMI | T3 VAKFI. TUM HAKLARI SAKLIDIR.',
                'stats' => [
                    ['label' => 'Aktif Ogrenci', 'value' => '2,500+', 'icon' => 'users'],
                    ['label' => 'Tamamlanan Proje', 'value' => '450+', 'icon' => 'trophy'],
                    ['label' => 'Yillik Etkinlik', 'value' => '1,200+', 'icon' => 'calendar'],
                    ['label' => 'Sehir', 'value' => '81', 'icon' => 'globe'],
                ],
            ],
            'about' => [
                'hero_title' => 'Biz Kimiz?',
                'hero_description' => 'KADEME, T3 Vakfi bunyesinde yetenek, kariyer ve liderlik gelisimi odakli bir ekosistemdir. Ogrenciler, mezunlar ve profesyoneller icin surekli gelisim alanlari uretir.',
                'mission_title' => 'Misyonumuz',
                'mission_text' => 'Genc yeteneklerin potansiyelini ortaya cikarmak, onlara cagimizin gerektirdigi bilgi ve becerileri kazandirmak ve uzun vadeli bir gelisim yolculugu sunmak.',
                'vision_title' => 'Vizyonumuz',
                'vision_text' => 'Turkiye\'nin ihtiyac duydugu nitelikli insan kaynagini destekleyen, projeleriyle fark yaratan ve katilimcilarina gercek bir gelisim agi sunan oncu bir merkez olmak.',
                'ecosystem_title' => 'KADEME ve Proje Ekosistemi',
                'ecosystem_description' => 'Diplomasi, mentorluk, psikolojik danismanlik, rozet sistemi, dijital bohca ve proje bazli etkinlik akislariyla farkli alanlara dokunan cok katmanli bir yapi kuruyoruz.',
                'faq_teaser_title' => 'SSS ve Blog',
                'faq_teaser_text' => 'Sik sorulan sorular ve icerik akisi public tarafta erisilebilir.',
                'blog_teaser_title' => 'Blog Yazilari',
                'blog_teaser_text' => 'KADEME dunyasindan secili yazilar ve guncel icerikler burada yer alir.',
                'activities_teaser_title' => 'Faaliyetler',
                'activities_teaser_text' => 'Program ve etkinlik akislarimiz proje bazli ilerler.',
                'journey_title' => 'Gelisim Yolculugu',
                'journey_text' => 'Projeler, faaliyetler, blog, SSS ve iletisim akislari birlikte KADEME\'nin public katmanini olusturur.',
            ],
        ];
    }

    private function computedHomepageStats(): array
    {
        return [
            [
                'label' => 'Aktif Ogrenci',
                'value' => number_format(Participant::where('status', 'active')->count()),
                'icon' => 'users',
            ],
            [
                'label' => 'Aktif Proje',
                'value' => number_format(Project::where('status', 'active')->count()),
                'icon' => 'trophy',
            ],
            [
                'label' => 'Yaklasan Faaliyet',
                'value' => number_format(Program::whereIn('status', ['scheduled', 'active'])->count()),
                'icon' => 'calendar',
            ],
            [
                'label' => 'Yayinlanan Blog',
                'value' => number_format(
                    BlogPost::where('status', 'published')
                        ->where('published_at', '<=', now())
                        ->count()
                ),
                'icon' => 'globe',
            ],
        ];
    }

    private function groupedSettings(): array
    {
        $settings = $this->defaults();

        foreach (SystemSetting::all() as $setting) {
            $group = $setting->group ?: 'general';
            $value = $setting->value;
            $decoded = json_decode((string) $value, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = $decoded;
            }

            if (! isset($settings[$group])) {
                $settings[$group] = [];
            }

            $settings[$group][$setting->key] = $value;
        }

        return $settings;
    }

    public function public(): JsonResponse
    {
        return response()->json([
            'settings' => $this->groupedSettings(),
            'computed_homepage_stats' => $this->computedHomepageStats(),
        ]);
    }

    public function admin(Request $request): JsonResponse
    {
        abort_unless($this->canViewAdminSettings($request), 403, 'Bu islem icin yetkiniz bulunmuyor.');

        return response()->json([
            'settings' => $this->groupedSettings(),
            'computed_homepage_stats' => $this->computedHomepageStats(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless($this->canUpdateAdminSettings($request), 403, 'Bu islem icin yetkiniz bulunmuyor.');

        $validated = $request->validate([
            'settings' => 'required|array',
            'settings.general' => 'nullable|array',
            'settings.contact' => 'nullable|array',
            'settings.social_media' => 'nullable|array',
            'settings.navigation' => 'nullable|array',
            'settings.homepage' => 'nullable|array',
            'settings.about' => 'nullable|array',
        ]);

        foreach ($validated['settings'] as $group => $entries) {
            if (! is_array($entries)) {
                continue;
            }

            foreach ($entries as $key => $value) {
                SystemSetting::updateOrCreate(
                    ['key' => $key],
                    [
                        'group' => $group,
                        'value' => is_array($value)
                            ? json_encode($value, JSON_UNESCAPED_UNICODE)
                            : (string) ($value ?? ''),
                    ],
                );
            }
        }

        return response()->json([
            'message' => 'Site ayarlari guncellendi.',
            'settings' => $this->groupedSettings(),
            'computed_homepage_stats' => $this->computedHomepageStats(),
        ]);
    }
}
