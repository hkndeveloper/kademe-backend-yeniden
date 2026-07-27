<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\PublicBlogResource;
use App\Http\Resources\PublicProgramResource;
use App\Models\BlogPost;
use App\Models\Participant;
use App\Models\Program;
use App\Models\Project;
use App\Models\SystemSetting;
use App\Services\PermissionResolver;
use App\Support\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * @group Site Settings
 */
class SiteSettingsController extends Controller
{
    private const PUBLIC_HOMEPAGE_CACHE_KEY = 'public.homepage.v1';

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {
    }

    private function canViewAdminSettings(Request $request): bool
    {
        $user = $request->user();

        return $this->permissionResolver->hasGlobalScope($user, 'settings.view')
            || $this->permissionResolver->hasGlobalScope($user, 'content.site_settings.update');
    }

    private function canUpdateAdminSettings(Request $request): bool
    {
        $user = $request->user();

        return $this->permissionResolver->hasGlobalScope($user, 'settings.update')
            || $this->permissionResolver->hasGlobalScope($user, 'content.site_settings.update');
    }

    private function defaults(): array
    {
        return [
            'general' => [
                'site_name' => 'KADEME',
                'site_tagline' => 'Geleceğin Liderlik Okulu',
            ],
            'contact' => [
                'contact_email' => 'info@kademe.org',
                'contact_phone' => '0212 XXX XX XX',
                'contact_address' => 'KADEME Iletisim Merkezi, Istanbul, Turkiye',
            ],
            'social_media' => [
                'instagram_url' => '',
                'twitter_url' => '',
                'youtube_url' => '',
                'linkedin_url' => '',
                // Sosyal medya otomasyon webhook (Buffer / Make.com / Zapier)
                'sharing_webhook_url' => '',
            ],
            'navigation' => [
                'header_links' => [
                    ['label' => 'Ana Sayfa', 'href' => '/'],
                    ['label' => 'Hakkımızda', 'href' => '/about'],
                    ['label' => 'Faaliyetler', 'href' => '/activities'],
                    ['label' => 'Blog', 'href' => '/blog'],
                    ['label' => 'SSS', 'href' => '/faq'],
                    ['label' => 'İletişim', 'href' => '/contact'],
                    ['label' => 'Sertifika Sorgula', 'href' => '/certificates/verify'],
                ],
                'header_login_label' => 'Giriş Yap',
                'header_register_label' => 'Başvur',
                'footer_quick_links' => [
                    ['label' => 'Hakkımızda', 'href' => '/about'],
                    ['label' => 'Projelerimiz', 'href' => '/projects'],
                    ['label' => 'SSS', 'href' => '/faq'],
                    ['label' => 'İletişim', 'href' => '/contact'],
                ],
                'footer_project_links' => [],
            ],
            'homepage' => [
                'block_order' => ['hero', 'intro', 'stats', 'projects', 'activities', 'about', 'blog', 'newsletter', 'certificate_verify', 'marquee'],
                'block_visibility' => [
                    'hero' => true,
                    'intro' => true,
                    'stats' => true,
                    'projects' => true,
                    'activities' => true,
                    'about' => true,
                    'blog' => true,
                    'newsletter' => true,
                    'certificate_verify' => true,
                    'marquee' => true,
                ],
                'hero_badge' => 'KADEME: Geleceğin Liderlik Okulu',
                'hero_title_line_1' => 'YETENEĞİNİ',
                'hero_title_line_2' => 'KEŞFET',
                'hero_title_line_3' => 'GELECEĞİ',
                'hero_title_line_4' => 'YÖNET',
                'hero_description' => 'KADEME ekosisteminde kapsamlı kariyer ve yetenek gelişim programlarına dahil olun.',
                'hero_background_image_url' => '',
                'hero_primary_label' => 'Hemen Başvur',
                'hero_primary_href' => '/auth/register',
                'hero_secondary_label' => 'Giriş Yap',
                'hero_secondary_href' => '/auth/login',
                'intro_cards' => [
                    [
                        'title' => 'Kariyer ve Liderlik Gelişimi',
                        'description' => 'KADEME, öğrencilerin ve mezunların farklı proje akışları içinde yeteneklerini geliştirebildiği çok katmanlı bir ekosistem sunar.',
                        'image_url' => '',
                        'cta_label' => 'Hakkımızda',
                        'cta_href' => '/about',
                    ],
                    [
                        'title' => 'Proje Bazlı Yolculuk',
                        'description' => 'Diplomasi360, KADEME+, Pergel Fellowship, KPD ve Eurodesk gibi alan odaklı projeler tek merkezden yönetilir.',
                        'image_url' => '',
                        'cta_label' => 'Projeleri İncele',
                        'cta_href' => '/projects',
                    ],
                    [
                        'title' => 'Etkinlik ve Başvuru Akisi',
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
                'marquee_items' => ['KADEME', 'Projeler', 'Faaliyetler', 'Mentorluk', 'Gelişim', 'Başvuru', 'Sertifika'],
                'marquee_speed_seconds' => 45,
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
                'certificate_verify_title' => 'Sertifika Dogrula',
                'certificate_verify_description' => 'KADEME tarafindan verilen sertifikalari dogrulama kodu ile kamusal olarak sorgulayabilirsiniz.',
                'certificate_verify_cta_label' => 'Dogrulama Ekranina Git',
                'certificate_verify_cta_href' => '/certificates/verify',
                'footer_description' => 'KADEME Kariyer Gelişim Merkezi. Gelecegin liderlerini bugunden yetistiriyoruz.',
                'footer_copyright' => '© 2026 KADEME YÖNETİM SİSTEMİ. TÜM HAKLARI SAKLIDIR.',
                'stats' => [
                    ['label' => 'Aktif Ogrenci', 'value' => '2,500+', 'icon' => 'users'],
                    ['label' => 'Tamamlanan Proje', 'value' => '450+', 'icon' => 'trophy'],
                    ['label' => 'Yillik Etkinlik', 'value' => '1,200+', 'icon' => 'calendar'],
                    ['label' => 'Sehir', 'value' => '81', 'icon' => 'globe'],
                ],
                'monthly_motivation_message' => 'Gelecek, bugunden ona hazirlananlara aittir. KADEME\'deki her adim, seni daha guclu bir vizyona tasir.',
            ],
            'about' => [
                'hero_title' => 'Biz Kimiz?',
                'hero_description' => 'KADEME, yetenek, kariyer ve liderlik gelisimi odakli bir ekosistemdir. Ogrenciler, mezunlar ve profesyoneller icin surekli gelisim alanlari uretir.',
                'mission_title' => 'Misyonumuz',
                'mission_text' => 'Genc yeteneklerin potansiyelini ortaya cikarmak, onlara cagimizin gerektirdigi bilgi ve becerileri kazandirmak ve uzun vadeli bir gelisim yolculugu sunmak.',
                'vision_title' => 'Vizyonumuz',
                'vision_text' => 'Turkiye\'nin ihtiyac duydugu nitelikli insan kaynagini destekleyen, projeleriyle fark yaratan ve katilimcilarina gercek bir gelisim agi sunan oncu bir merkez olmak.',
                'ecosystem_title' => 'KADEME ve Proje Ekosistemi',
                'ecosystem_description' => 'Diplomasi, mentorluk, psikolojik danismanlik, rozet sistemi, dijital bohca ve proje bazli etkinlik akislariyla farkli alanlara dokunan cok katmanli bir yapi kuruyoruz.',
                'faq_teaser_title' => 'SSS ve Blog',
                'faq_teaser_text' => 'Sık sorulan sorular ve içerik akışı public tarafta erişilebilir.',
                'blog_teaser_title' => 'Blog Yazilari',
                'blog_teaser_text' => 'KADEME dünyasından seçili yazılar ve güncel içerikler burada yer alır.',
                'activities_teaser_title' => 'Faaliyetler',
                'activities_teaser_text' => 'Program ve etkinlik akislarimiz proje bazli ilerler.',
                'journey_title' => 'Gelişim Yolculugu',
                'journey_text' => 'Projeler, faaliyetler, blog, SSS ve iletişim akışları birlikte KADEME\'nin public katmanını oluşturur.',
            ],
            'blog_page' => [
                'badge_label' => 'KADEME Rehberi',
                'title' => 'Blog & Haberler',
                'description' => 'Geleceğin yetenekleri için hazırladığımız makaleleri ve KADEME dünyasındaki son gelişmeleri takip edin.',
                'search_placeholder' => 'Blog yazısı ara...',
                'empty_text' => 'Seçili arama kriterleriyle blog yazısı bulunamadı.',
                'read_more_label' => 'Devamını Oku',
                'detail_badge_label' => 'Blog Detayı',
                'detail_back_label' => 'Tüm blog yazılarına dön',
                'detail_empty_content' => 'Bu yazı için henüz içerik eklenmemiş.',
            ],
            'faq_page' => [
                'title' => 'Sıkça Sorulan Sorular',
                'description' => 'KADEME süreçleri hakkında merak ettiğiniz temel konular burada toplanır.',
                'empty_text' => 'Henüz soru-cevap eklenmemiş.',
                'contact_title' => 'Başka bir sorunuz mu var?',
                'contact_description' => 'Aradığınız cevabı bulamadıysanız iletişim veya destek kanalına geçebilirsiniz.',
                'contact_cta_label' => 'İletişime Geç',
                'contact_cta_href' => '/contact',
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

    private function normalizeLinks(mixed $value, array $fallback): array
    {
        if (! is_array($value)) {
            return $fallback;
        }

        return array_values(array_filter(array_map(function ($item) {
            if (! is_array($item)) {
                return null;
            }

            return [
                'label' => (string) ($item['label'] ?? ''),
                'href' => (string) ($item['href'] ?? '/'),
            ];
        }, $value)));
    }

    private function normalizeIntroCards(mixed $value, array $fallback): array
    {
        if (! is_array($value)) {
            return $fallback;
        }

        return array_values(array_filter(array_map(function ($item) {
            if (! is_array($item)) {
                return null;
            }

            return [
                'title' => (string) ($item['title'] ?? ''),
                'description' => (string) ($item['description'] ?? ''),
                'image_url' => (string) ($item['image_url'] ?? ''),
                'cta_label' => (string) ($item['cta_label'] ?? ''),
                'cta_href' => (string) ($item['cta_href'] ?? '/'),
            ];
        }, $value)));
    }

    private function normalizeStats(mixed $value, array $fallback): array
    {
        if (! is_array($value)) {
            return $fallback;
        }

        return array_values(array_filter(array_map(function ($item) {
            if (! is_array($item)) {
                return null;
            }

            return [
                'label' => (string) ($item['label'] ?? 'Yeni Alan'),
                'value' => (string) ($item['value'] ?? '0'),
                'icon' => (string) ($item['icon'] ?? 'users'),
            ];
        }, $value)));
    }

    private function normalizeStringList(mixed $value, array $fallback): array
    {
        if (! is_array($value)) {
            return $fallback;
        }

        return array_values(array_filter($value, fn ($item) => is_string($item)));
    }

    private function normalizeNumberList(mixed $value, array $fallback): array
    {
        if (! is_array($value)) {
            return $fallback;
        }

        return array_values(array_filter(array_map(
            fn ($item) => is_numeric($item) ? (int) $item : null,
            $value
        ), fn ($item) => $item !== null));
    }

    private function normalizeSettings(array $settings): array
    {
        $defaults = $this->defaults();

        foreach (['general', 'contact', 'social_media', 'about', 'blog_page', 'faq_page'] as $group) {
            $settings[$group] = array_replace(
                $defaults[$group],
                isset($settings[$group]) && is_array($settings[$group]) ? $settings[$group] : []
            );
        }

        $navigation = array_replace(
            $defaults['navigation'],
            isset($settings['navigation']) && is_array($settings['navigation']) ? $settings['navigation'] : []
        );
        $navigation['header_links'] = $this->normalizeLinks($navigation['header_links'] ?? null, $defaults['navigation']['header_links']);
        $navigation['footer_quick_links'] = $this->normalizeLinks($navigation['footer_quick_links'] ?? null, $defaults['navigation']['footer_quick_links']);
        $navigation['footer_project_links'] = $this->normalizeLinks($navigation['footer_project_links'] ?? null, $defaults['navigation']['footer_project_links']);
        $settings['navigation'] = $navigation;

        $homepage = array_replace(
            $defaults['homepage'],
            isset($settings['homepage']) && is_array($settings['homepage']) ? $settings['homepage'] : []
        );
        $allowedBlocks = array_keys($defaults['homepage']['block_visibility']);
        $blockOrder = is_array($homepage['block_order'] ?? null)
            ? array_values(array_filter($homepage['block_order'], fn ($key) => is_string($key) && in_array($key, $allowedBlocks, true)))
            : $defaults['homepage']['block_order'];
        $blockOrder = count($blockOrder) > 0 ? $blockOrder : $defaults['homepage']['block_order'];
        $homepage['block_order'] = array_values(array_unique(array_merge($blockOrder, array_diff($allowedBlocks, $blockOrder))));

        $incomingVisibility = is_array($homepage['block_visibility'] ?? null) ? $homepage['block_visibility'] : [];
        $homepage['block_visibility'] = array_map(
            fn ($defaultValue, $key) => is_bool($incomingVisibility[$key] ?? null) ? $incomingVisibility[$key] : $defaultValue,
            $defaults['homepage']['block_visibility'],
            array_keys($defaults['homepage']['block_visibility'])
        );
        $homepage['block_visibility'] = array_combine($allowedBlocks, $homepage['block_visibility']);
        $homepage['stats_mode'] = ($homepage['stats_mode'] ?? 'auto') === 'manual' ? 'manual' : 'auto';
        $homepage['intro_cards'] = $this->normalizeIntroCards($homepage['intro_cards'] ?? null, $defaults['homepage']['intro_cards']);
        $homepage['featured_project_slugs'] = $this->normalizeStringList($homepage['featured_project_slugs'] ?? null, $defaults['homepage']['featured_project_slugs']);
        $homepage['featured_blog_slugs'] = $this->normalizeStringList($homepage['featured_blog_slugs'] ?? null, $defaults['homepage']['featured_blog_slugs']);
        $homepage['featured_activity_ids'] = $this->normalizeNumberList($homepage['featured_activity_ids'] ?? null, $defaults['homepage']['featured_activity_ids']);
        $homepage['marquee_items'] = $this->normalizeStringList($homepage['marquee_items'] ?? null, $defaults['homepage']['marquee_items']);
        $marqueeSpeed = is_numeric($homepage['marquee_speed_seconds'] ?? null) ? (int) $homepage['marquee_speed_seconds'] : $defaults['homepage']['marquee_speed_seconds'];
        $homepage['marquee_speed_seconds'] = max(8, min(90, $marqueeSpeed));
        $homepage['stats'] = $this->normalizeStats($homepage['stats'] ?? null, $defaults['homepage']['stats']);
        $settings['homepage'] = $homepage;

        return $settings;
    }

    private function normalizeStoredMediaUrls(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($item) => $this->normalizeStoredMediaUrls($item), $value);
        }

        if (is_string($value) && MediaStorage::isUrl($value)) {
            return MediaStorage::url($value) ?? $value;
        }

        return $value;
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

        return $this->normalizeStoredMediaUrls($this->normalizeSettings($settings));
    }

    /**
     * Get public site configuration.
     *
     * Public endpoint used by web/mobile shells to render shared navigation, footer, contact, social, homepage/about/blog/FAQ labels, and computed counters. No bearer token is required. Sensitive Google Calendar/OAuth settings are explicitly removed from this response.
     *
     * @group Public Content
     * @unauthenticated
     *
     * @response 200 {"settings":{"general":{"site_name":"KADEME","site_tagline":"Gelecegin Liderlik Okulu"},"navigation":{"header_links":[]},"homepage":{"block_order":["hero","projects"]}},"computed_homepage_stats":[{"label":"Aktif Ogrenci","value":"0","icon":"users"}]}
     */
    public function public(): JsonResponse
    {
        $settings = $this->groupedSettings();
        // OAuth / takvim gizli alanlari herkese acik endpoint'te donulmez.
        unset($settings['google_calendar']);

        return response()->json([
            'settings' => $settings,
            'computed_homepage_stats' => $this->computedHomepageStats(),
        ]);
    }

    /**
     * Get public homepage payload.
     *
     * Public endpoint used by the homepage. No bearer token is required. Returns normalized public settings, computed counters, active projects, published blogs, and public programs/activities. The backend caches the assembled payload briefly and removes sensitive Google Calendar/OAuth settings before returning it.
     *
     * @group Public Content
     * @unauthenticated
     *
     * @response 200 {"settings":{"homepage":{"hero_title_line_1":"YETENEGINI","stats_mode":"auto"}},"computed_homepage_stats":[],"projects":[],"blogs":[],"programs":[]}
     */
    public function homepage(Request $request): JsonResponse
    {
        $payload = Cache::remember(self::PUBLIC_HOMEPAGE_CACHE_KEY, now()->addSeconds(30), function () use ($request) {
            $settings = $this->groupedSettings();
            unset($settings['google_calendar']);

            $projects = Project::query()
                ->where('status', 'active')
                ->with(['periods' => fn ($query) => $query->where('status', 'active')])
                ->orderBy('name')
                ->take(12)
                ->get();

            $blogs = BlogPost::query()
                ->where('status', 'published')
                ->where('published_at', '<=', now())
                ->with('category')
                ->orderByDesc('published_at')
                ->take(6)
                ->get();

            $activities = Program::query()
                ->with(['project:id,name,slug', 'period:id,name'])
                ->where('is_public', true)
                ->whereIn('status', ['scheduled', 'active', 'completed'])
                ->where('start_at', '>=', now()->subYear())
                ->orderByDesc('is_featured')
                ->orderBy('start_at')
                ->take(8)
                ->get();

            return [
                'settings' => $settings,
                'computed_homepage_stats' => $this->computedHomepageStats(),
                'projects' => ProjectResource::collection($projects)->resolve($request),
                'blogs' => PublicBlogResource::collection($blogs)->resolve($request),
                'programs' => PublicProgramResource::collection($activities)->resolve($request),
            ];
        });

        return response()->json($payload);
    }

    /**
     * Get panel site settings.
     *
     * Panel/admin endpoint exposed under `/admin/site-settings` and `/panel/site-settings`. Requires global scope for either `settings.view` or `content.site_settings.update`. Returns normalized settings with defaults merged, stored media URLs normalized through the media storage layer, and computed homepage counters for preview screens.
     *
     * @group Site Settings
     * @authenticated
     *
     * @response 200 {"settings":{"general":{"site_name":"KADEME"},"contact":{"contact_email":"info@kademe.org"},"homepage":{"block_order":["hero","projects"]}},"computed_homepage_stats":[{"label":"Aktif Ogrenci","value":"0","icon":"users"}]}
     * @response 403 {"message":"Bu islem icin yetkiniz bulunmuyor."}
     */
    public function admin(Request $request): JsonResponse
    {
        abort_unless($this->canViewAdminSettings($request), 403, 'Bu islem icin yetkiniz bulunmuyor.');

        return response()->json([
            'settings' => $this->groupedSettings(),
            'computed_homepage_stats' => $this->computedHomepageStats(),
        ]);
    }

    /**
     * Update panel site settings.
     *
     * Panel/admin endpoint exposed under `/admin/site-settings` and `/panel/site-settings`. Requires global scope for either `settings.update` or `content.site_settings.update`. Accepts grouped settings for public site configuration, stores scalar values as strings and arrays as JSON, normalizes the response with defaults, and clears the short-lived public homepage cache.
     *
     * Supported top-level groups are `general`, `contact`, `social_media`, `navigation`, `homepage`, `about`, `blog_page`, and `faq_page`. Unknown nested keys inside accepted groups are stored as provided, so mobile/web clients should send the full intended group shape when replacing complex arrays such as navigation links or homepage blocks.
     *
     * @group Site Settings
     * @authenticated
     *
     * @bodyParam settings object required Grouped site settings payload.
     * @bodyParam settings.general object Optional general labels. Example: {"site_name":"KADEME","site_tagline":"Gelecegin Liderlik Okulu"}
     * @bodyParam settings.contact object Optional contact settings. Example: {"contact_email":"info@kademe.org"}
     * @bodyParam settings.social_media object Optional social/media/webhook settings. Example: {"instagram_url":"https://instagram.com/kademe"}
     * @bodyParam settings.navigation object Optional header/footer navigation arrays. Example: {"header_links":[{"label":"Ana Sayfa","href":"/"}]}
     * @bodyParam settings.homepage object Optional homepage configuration. Example: {"stats_mode":"auto","block_order":["hero","projects"]}
     * @bodyParam settings.about object Optional about page copy.
     * @bodyParam settings.blog_page object Optional blog page copy.
     * @bodyParam settings.faq_page object Optional FAQ page copy.
     * @response 200 {"message":"Site ayarlari guncellendi.","settings":{"general":{"site_name":"KADEME"}},"computed_homepage_stats":[]}
     * @response 403 {"message":"Bu islem icin yetkiniz bulunmuyor."}
     * @response 422 {"message":"The settings field is required."}
     */
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
            'settings.blog_page' => 'nullable|array',
            'settings.faq_page' => 'nullable|array',
        ]);

        foreach ($validated['settings'] as $group => $entries) {
            if (! is_array($entries)) {
                continue;
            }

            foreach ($entries as $key => $value) {
                SystemSetting::updateOrCreate(
                    [
                        'group' => $group,
                        'key' => $key,
                    ],
                    [
                        'value' => is_array($value)
                            ? json_encode($value, JSON_UNESCAPED_UNICODE)
                            : (string) ($value ?? ''),
                    ],
                );
            }
        }

        Cache::forget(self::PUBLIC_HOMEPAGE_CACHE_KEY);

        return response()->json([
            'message' => 'Site ayarlari guncellendi.',
            'settings' => $this->groupedSettings(),
            'computed_homepage_stats' => $this->computedHomepageStats(),
        ]);
    }
}
