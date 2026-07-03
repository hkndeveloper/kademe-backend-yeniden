<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Services\PermissionResolver;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * @group Social Sharing
 */
class SocialSharingController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {}

    private function canShareSocially(Request $request): bool
    {
        $user = $request->user();

        return $this->permissionResolver->hasGlobalScope($user, 'content.blog.update')
            || $this->permissionResolver->hasGlobalScope($user, 'announcements.create');
    }

    /**
     * Send content to the social sharing webhook.
     *
     * Requires global permission scope for `content.blog.update` or `announcements.create`. The backend posts the payload to the configured social media webhook URL.
     *
     * @group Social Sharing
     * @authenticated
     *
     * @bodyParam text string required Content text, max 2000 characters. Example: Yeni duyurumuz yayinda.
     * @bodyParam url string Optional target URL. Example: https://kademe.example.com/blog/yeni-duyuru
     * @bodyParam image_url string Optional image URL. Example: https://kademe.example.com/image.jpg
     * @bodyParam platforms string[] Optional platform list. Example: ["instagram","linkedin"]
     * @response 200 {"message":"Icerik sosyal medya platformlarina gonderildi.","shared":true,"http_code":200}
     * @response 403 {"message":"Bu islem icin yetkiniz bulunmuyor."}
     * @response 422 {"message":"Sosyal medya webhook URL tanimli degil. Admin > Site Ayarlari > Sosyal Medya bolumunden tanimlayabilirsiniz.","shared":false}
     * @response 502 {"message":"Webhook gonderilemedi: timeout","shared":false}
     */
    public function post(Request $request): JsonResponse
    {
        abort_unless($this->canShareSocially($request), 403, 'Bu islem icin yetkiniz bulunmuyor.');
        $request->attributes->set('audit.permission_checked', 'content.blog.update|announcements.create');

        $validated = $request->validate([
            'text'       => 'required|string|max:2000',
            'url'        => 'nullable|url|max:500',
            'image_url'  => 'nullable|url|max:500',
            'platforms'  => 'nullable|array',
            'platforms.*' => 'nullable|string|in:instagram,twitter,linkedin,facebook',
        ]);

        $webhookUrl = (string) SystemSetting::query()
            ->where('group', 'social_media')
            ->where('key', 'sharing_webhook_url')
            ->value('value');

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
