<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Services\PermissionResolver;
use App\Models\SiteSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SocialSharingController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {}

    /**
     * POST /admin/social-sharing/post
     *
     * Sosyal medya otomasyon webhook'una (Buffer / Make.com / Zapier vb.)
     * belirlenmiş içeriği gönderir.
     * Admin/koordinatör izni gerektirir.
     */
    public function post(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'announcements.create');

        $validated = $request->validate([
            'text'       => 'required|string|max:2000',
            'url'        => 'nullable|url|max:500',
            'image_url'  => 'nullable|url|max:500',
            'platforms'  => 'nullable|array',
            'platforms.*' => 'nullable|string|in:instagram,twitter,linkedin,facebook',
        ]);

        $settings = SiteSettings::first();
        $webhookUrl = $settings?->getSettingValue('social_media.sharing_webhook_url') ?? '';

        if (empty($webhookUrl)) {
            return response()->json([
                'message' => 'Sosyal medya webhook URL tanimli degil. Admin > Site Ayarlari > Sosyal Medya bolumunden tanimlayabilirsiniz.',
                'shared'  => false,
            ], 422);
        }

        $payload = [
            'text'      => $validated['text'],
            'url'       => $validated['url'] ?? null,
            'image_url' => $validated['image_url'] ?? null,
            'platforms' => $validated['platforms'] ?? ['instagram', 'twitter', 'linkedin'],
            'source'    => 'kademe_panel',
            'sent_by'   => $request->user()->id,
        ];

        try {
            $response = Http::timeout(10)->post($webhookUrl, $payload);

            Log::info('social_sharing.webhook_sent', [
                'status'  => $response->status(),
                'payload' => $payload,
            ]);

            return response()->json([
                'message' => $response->successful()
                    ? 'Icerik sosyal medya platformlarina gonderildi.'
                    : 'Webhook yanit vermedi: HTTP '.$response->status(),
                'shared'    => $response->successful(),
                'http_code' => $response->status(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('social_sharing.webhook_failed', [
                'error'   => $exception->getMessage(),
                'payload' => $payload,
            ]);

            return response()->json([
                'message' => 'Webhook gonderilemedi: '.$exception->getMessage(),
                'shared'  => false,
            ], 502);
        }
    }
}
