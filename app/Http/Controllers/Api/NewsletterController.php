<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\NewsletterSubscriber;
use App\Services\NotificationService;
use App\Services\PermissionResolver;
use App\Support\AdminExportResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Newsletter
 */
class NewsletterController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver,
        private readonly NotificationService $notificationService,
    ) {
    }

    /**
     * List newsletter subscribers.
     *
     * Panel/admin endpoint exposed under `/admin/newsletter/subscribers` and `/panel/newsletter/subscribers`. Requires `newsletter.view` with global/tum sistem scope because subscriber emails are system-wide personal data. Returns active subscribers only and paginates by 50.
     *
     * @group Newsletter
     * @authenticated
     *
     * @queryParam search string Optional email/name search. Example: hakan@example.com
     * @response 200 {"subscribers":{"data":[{"id":1,"email":"hakan@example.com","name":"Hakan","unsubscribed_at":null}]}}
     * @response 403 {"message":"E-bulten aboneleri icin tum sistem kapsami gerekir."}
     */
    public function adminSubscribers(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'newsletter.view');
        abort_unless(
            $this->permissionResolver->hasGlobalScope($request->user(), 'newsletter.view'),
            403,
            'E-bulten aboneleri icin tum sistem kapsami gerekir.'
        );

        $query = NewsletterSubscriber::query()
            ->whereNull('unsubscribed_at')
            ->orderByDesc('subscribed_at');

        if ($request->filled('search')) {
            $s = $request->string('search')->toString();
            $query->where(function ($q) use ($s) {
                $q->where('email', 'like', '%' . $s . '%')
                    ->orWhere('name', 'like', '%' . $s . '%');
            });
        }

        return response()->json([
            'subscribers' => $query->paginate(50),
        ]);
    }

    /**
     * Export newsletter subscribers.
     *
     * Panel/admin endpoint exposed under `/admin/newsletter/subscribers/export` and `/panel/newsletter/subscribers/export`. Requires `newsletter.view` with global/tum sistem scope. By default exports only active subscribers; set `only_active=false` to include unsubscribed records. The shared export responder accepts `csv`, `xlsx`, or `pdf` when enabled.
     *
     * @group Newsletter
     * @authenticated
     *
     * @queryParam search string Optional email/name search. Example: hakan@example.com
     * @queryParam only_active boolean Optional. Defaults to true. Example: true
     * @queryParam format string Optional export format. Example: csv
     * @response 200 {"download":"Export file stream"}
     * @response 403 {"message":"E-bulten aboneleri icin tum sistem kapsami gerekir."}
     */
    public function exportSubscribers(Request $request)
    {
        $this->abortUnlessAllowed($request, 'newsletter.view');
        abort_unless(
            $this->permissionResolver->hasGlobalScope($request->user(), 'newsletter.view'),
            403,
            'E-bulten aboneleri icin tum sistem kapsami gerekir.'
        );

        $query = NewsletterSubscriber::query()->orderByDesc('subscribed_at');

        if ($request->filled('search')) {
            $s = $request->string('search')->toString();
            $query->where(function ($q) use ($s) {
                $q->where('email', 'like', '%' . $s . '%')
                    ->orWhere('name', 'like', '%' . $s . '%');
            });
        }

        if ($request->boolean('only_active', true)) {
            $query->whereNull('unsubscribed_at');
        }

        $subscribers = $query->get();

        $headings = ['ID', 'E-posta', 'Isim', 'Abonelik Tarihi', 'Ayrilma Tarihi', 'Durum'];
        $rows = $subscribers->map(fn (NewsletterSubscriber $subscriber) => [
            $subscriber->id,
            $subscriber->email,
            $subscriber->name ?? '-',
            $subscriber->subscribed_at?->format('d.m.Y H:i') ?? '-',
            $subscriber->unsubscribed_at?->format('d.m.Y H:i') ?? '-',
            $subscriber->unsubscribed_at ? 'Ayrildi' : 'Aktif',
        ])->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'newsletter_subscribers_' . now()->format('Ymd_His'),
            'Newsletter Subscribers',
            $headings,
            $rows,
        );
    }

    /**
     * Subscribe to the newsletter.
     *
     * Public endpoint. No bearer token is required and the route is throttled. Existing subscribers are reactivated by clearing `unsubscribed_at`. A confirmation email is sent with a signed unsubscribe link.
     *
     * @group Newsletter
     * @unauthenticated
     *
     * @bodyParam email string required Subscriber email. Example: hakan@example.com
     * @bodyParam name string Optional subscriber name. Example: Hakan Kekec
     * @response 200 {"message":"E-bulten aboneliginiz kaydedildi.","subscriber":{"id":1,"email":"hakan@example.com","name":"Hakan Kekec"}}
     * @response 422 {"message":"The email field is required.","errors":{"email":["The email field is required."]}}
     */
    public function subscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'name' => 'nullable|string|max:255',
        ]);

        $subscriber = NewsletterSubscriber::updateOrCreate(
            ['email' => $validated['email']],
            [
                'name' => $validated['name'] ?? null,
                'subscribed_at' => now(),
                'unsubscribed_at' => null,
            ],
        );

        $this->notificationService->sendEmail(
            [$subscriber->email],
            'E-bulten aboneligi basarili',
            "Merhaba " . ($subscriber->name ?: 'degerli kullanici') . ",\nKADEME e-bulten aboneliginiz aktif edildi.\n\n"
                . 'Abonelikten cikmak icin: ' . NewsletterController::generateUnsubscribeUrl($subscriber->email),
            null,
            null
        );

        return response()->json([
            'message' => 'E-bulten aboneliginiz kaydedildi.',
            'subscriber' => $subscriber,
        ]);
    }

    /**
     * Unsubscribe from the newsletter.
     *
     * Public endpoint. No bearer token is required and the route is throttled. The `token` must match the SHA-256 hash generated from the email and application key by `generateUnsubscribeUrl`.
     *
     * @group Newsletter
     * @unauthenticated
     *
     * @queryParam email string required Subscriber email. Example: hakan@example.com
     * @queryParam token string required Unsubscribe token from email link. Example: abc123
     * @response 200 {"message":"E-bulten aboneliginiz basariyla iptal edildi."}
     * @response 403 {"message":"Gecersiz abonelik cikarma baglantisi."}
     * @response 404 {"message":"E-posta adresi e-bulten listesinde bulunamadi."}
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
        ]);

        // Token doğrulama: email hash ile eşleşme
        $expectedToken = hash('sha256', $validated['email'] . config('app.key'));
        if (! hash_equals($expectedToken, $validated['token'])) {
            return response()->json([
                'message' => 'Gecersiz abonelik cikarma baglantisi.',
            ], 403);
        }

        $subscriber = NewsletterSubscriber::where('email', $validated['email'])->first();
        if (! $subscriber) {
            return response()->json([
                'message' => 'E-posta adresi e-bulten listesinde bulunamadi.',
            ], 404);
        }

        if ($subscriber->unsubscribed_at) {
            return response()->json([
                'message' => 'Aboneliginiz zaten iptal edilmis.',
            ]);
        }

        $subscriber->update(['unsubscribed_at' => now()]);

        return response()->json([
            'message' => 'E-bulten aboneliginiz basariyla iptal edildi.',
        ]);
    }

    /**
     * Unsubscribe linki oluştur (mail şablonlarında kullanılacak).
     */
    public static function generateUnsubscribeUrl(string $email): string
    {
        $token = hash('sha256', $email . config('app.key'));
        $baseUrl = config('app.frontend_url', config('cors.allowed_origins.0', 'https://hakankekec.me'));

        return $baseUrl . '/newsletter/unsubscribe?email=' . urlencode($email) . '&token=' . $token;
    }
}
