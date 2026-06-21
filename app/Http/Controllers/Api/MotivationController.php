<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\MotivationList;
use App\Models\MotivationQuote;
use App\Services\PermissionResolver;
use App\Support\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MotivationController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {
    }

    public function current(): JsonResponse
    {
        $list = MotivationList::query()
            ->where('is_active', true)
            ->with(['quotes' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')->orderBy('id')])
            ->latest('updated_at')
            ->first();

        if (! $list || $list->quotes->isEmpty()) {
            return response()->json([
                'motivation' => [
                    'quote' => "Gelecek, bugunden ona hazirlananlara aittir. KADEME'deki her adim, seni daha guclu bir vizyona tasir.",
                    'speaker' => 'KADEME',
                    'image_url' => null,
                    'rotation_period' => 'monthly',
                    'list_name' => null,
                ],
            ]);
        }

        $quotes = $list->quotes->values();
        $index = $this->rotationIndex($list->rotation_period, $quotes->count());
        $quote = $quotes->get($index) ?? $quotes->first();

        return response()->json([
            'motivation' => [
                'id' => $quote->id,
                'quote' => $quote->quote,
                'speaker' => $quote->speaker,
                'image_url' => $quote->image_url,
                'rotation_period' => $list->rotation_period,
                'list_name' => $list->name,
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeGlobal($request, 'motivation.view');

        $lists = MotivationList::query()
            ->with(['quotes' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')])
            ->orderByDesc('is_active')
            ->orderByDesc('updated_at')
            ->get();

        return response()->json(['lists' => $lists]);
    }

    public function storeList(Request $request): JsonResponse
    {
        $this->authorizeGlobal($request, 'motivation.manage');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'rotation_period' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'is_active' => 'nullable|boolean',
        ]);

        if ((bool) ($validated['is_active'] ?? false)) {
            MotivationList::query()->update(['is_active' => false]);
        }

        $list = MotivationList::query()->create(array_merge($validated, [
            'is_active' => (bool) ($validated['is_active'] ?? false),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]));

        return response()->json(['list' => $list->load('quotes')], 201);
    }

    public function updateList(Request $request, int $id): JsonResponse
    {
        $this->authorizeGlobal($request, 'motivation.manage');

        $list = MotivationList::query()->findOrFail($id);
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'rotation_period' => ['sometimes', 'required', Rule::in(['daily', 'weekly', 'monthly'])],
            'is_active' => 'nullable|boolean',
        ]);

        if (array_key_exists('is_active', $validated) && (bool) $validated['is_active']) {
            MotivationList::query()->whereKeyNot($list->id)->update(['is_active' => false]);
        }

        $list->update(array_merge($validated, ['updated_by' => $request->user()->id]));

        return response()->json(['list' => $list->fresh('quotes')]);
    }

    public function destroyList(Request $request, int $id): JsonResponse
    {
        $this->authorizeGlobal($request, 'motivation.manage');

        MotivationList::query()->findOrFail($id)->delete();

        return response()->json(['message' => 'Motivasyon listesi silindi.']);
    }

    public function storeQuote(Request $request, int $listId): JsonResponse
    {
        $this->authorizeGlobal($request, 'motivation.manage');

        $list = MotivationList::query()->findOrFail($listId);
        $validated = $request->validate([
            'quote' => 'required|string|max:4000',
            'speaker' => 'nullable|string|max:255',
            'image' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:8192',
            'image_path' => 'nullable|string|max:2048',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        $imagePath = $validated['image_path'] ?? null;
        if ($request->hasFile('image')) {
            $imagePath = MediaStorage::putFile('motivation-images', $request->file('image'));
        }

        $quote = $list->quotes()->create([
            'quote' => $validated['quote'],
            'speaker' => $validated['speaker'] ?? null,
            'image_path' => $imagePath,
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return response()->json(['quote' => $quote], 201);
    }

    public function updateQuote(Request $request, int $quoteId): JsonResponse
    {
        $this->authorizeGlobal($request, 'motivation.manage');

        $quote = MotivationQuote::query()->findOrFail($quoteId);
        $validated = $request->validate([
            'quote' => 'sometimes|required|string|max:4000',
            'speaker' => 'nullable|string|max:255',
            'image' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:8192',
            'image_path' => 'nullable|string|max:2048',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        if ($request->hasFile('image')) {
            if ($quote->image_path) {
                MediaStorage::delete($quote->image_path);
            }
            $validated['image_path'] = MediaStorage::putFile('motivation-images', $request->file('image'));
        }

        unset($validated['image']);
        $quote->update(array_merge($validated, ['updated_by' => $request->user()->id]));

        return response()->json(['quote' => $quote->fresh()]);
    }

    public function destroyQuote(Request $request, int $quoteId): JsonResponse
    {
        $this->authorizeGlobal($request, 'motivation.manage');

        $quote = MotivationQuote::query()->findOrFail($quoteId);
        if ($quote->image_path) {
            MediaStorage::delete($quote->image_path);
        }
        $quote->delete();

        return response()->json(['message' => 'Motivasyon cumlesi silindi.']);
    }

    private function authorizeGlobal(Request $request, string $permission): void
    {
        $this->abortUnlessAllowed($request, $permission);
        abort_unless($this->permissionResolver->hasGlobalScope($request->user(), $permission), 403, 'Bu alan global yetki gerektirir.');
    }

    private function rotationIndex(string $period, int $count): int
    {
        if ($count <= 0) {
            return 0;
        }

        $seed = match ($period) {
            'daily' => now()->format('Ymd'),
            'weekly' => now()->format('oW'),
            default => now()->format('Ym'),
        };

        return ((int) $seed) % $count;
    }
}
