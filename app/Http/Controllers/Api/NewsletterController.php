<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\NewsletterSubscriber;
use App\Services\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NewsletterController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {
    }

    /**
     * GET /admin/newsletter/subscribers
     * Aktif e-bulten aboneleri (yalnizca newsletter.view).
     */
    public function adminSubscribers(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'newsletter.view');

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

        return response()->json([
            'message' => 'E-bulten aboneliginiz kaydedildi.',
            'subscriber' => $subscriber,
        ]);
    }
}
