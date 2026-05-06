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

class NewsletterController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver,
        private readonly NotificationService $notificationService,
    ) {
    }

    /**
     * GET /admin/newsletter/subscribers
     * Aktif e-bulten aboneleri (yalnizca newsletter.view).
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
            "Merhaba " . ($subscriber->name ?: 'degerli kullanici') . ",\nKADEME e-bulten aboneliginiz aktif edildi.",
            null,
            null
        );

        return response()->json([
            'message' => 'E-bulten aboneliginiz kaydedildi.',
            'subscriber' => $subscriber,
        ]);
    }
}
