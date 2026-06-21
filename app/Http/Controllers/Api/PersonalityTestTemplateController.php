<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PersonalityTestTemplate;
use App\Services\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PersonalityTestTemplateController extends Controller
{
    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->abortUnlessCanView($request);

        return response()->json([
            'templates' => PersonalityTestTemplate::query()
                ->with(['questions', 'resultRanges'])
                ->orderByDesc('is_active')
                ->latest('updated_at')
                ->latest('id')
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->abortUnlessCanManage($request);

        $validated = $this->validatedPayload($request);

        $template = DB::transaction(function () use ($validated, $request) {
            if ($validated['is_active'] ?? false) {
                PersonalityTestTemplate::query()->update(['is_active' => false]);
            }

            $template = PersonalityTestTemplate::query()->create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'is_active' => (bool) ($validated['is_active'] ?? false),
                'created_by' => $request->user()->id,
            ]);

            $this->syncChildren($template, $validated);

            return $template->fresh(['questions', 'resultRanges']);
        });

        return response()->json([
            'message' => 'Kisilik analizi sablonu olusturuldu.',
            'template' => $template,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessCanManage($request);

        $validated = $this->validatedPayload($request);

        $template = DB::transaction(function () use ($validated, $id) {
            $template = PersonalityTestTemplate::query()->findOrFail($id);

            if ($validated['is_active'] ?? false) {
                PersonalityTestTemplate::query()
                    ->whereKeyNot($template->id)
                    ->update(['is_active' => false]);
            }

            $template->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'is_active' => (bool) ($validated['is_active'] ?? false),
            ]);

            $this->syncChildren($template, $validated);

            return $template->fresh(['questions', 'resultRanges']);
        });

        return response()->json([
            'message' => 'Kisilik analizi sablonu guncellendi.',
            'template' => $template,
        ]);
    }

    public function activate(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessCanManage($request);

        $template = DB::transaction(function () use ($id) {
            $template = PersonalityTestTemplate::query()->findOrFail($id);

            abort_if($template->questions()->count() === 0, 422, 'Aktif etmek icin en az bir soru gerekir.');

            PersonalityTestTemplate::query()->update(['is_active' => false]);
            $template->update(['is_active' => true]);

            return $template->fresh(['questions', 'resultRanges']);
        });

        return response()->json([
            'message' => 'Kisilik analizi sablonu aktif edildi.',
            'template' => $template,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessCanManage($request);

        $template = PersonalityTestTemplate::query()->findOrFail($id);
        abort_if($template->is_active, 422, 'Aktif kisilik analizi sablonu silinemez.');

        $template->delete();

        return response()->json([
            'message' => 'Kisilik analizi sablonu silindi.',
        ]);
    }

    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'sometimes|boolean',
            'questions' => 'required|array|min:1|max:40',
            'questions.*.question_key' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9_]+$/'],
            'questions.*.category' => 'required|string|max:80',
            'questions.*.text' => 'required|string|max:500',
            'questions.*.sort_order' => 'nullable|integer|min:0|max:1000',
            'result_ranges' => 'nullable|array|max:20',
            'result_ranges.*.category' => 'required|string|max:80',
            'result_ranges.*.summary' => 'required|string|max:1000',
        ]);
    }

    private function syncChildren(PersonalityTestTemplate $template, array $validated): void
    {
        $template->questions()->delete();
        $template->resultRanges()->delete();

        foreach (array_values($validated['questions']) as $index => $question) {
            $template->questions()->create([
                'question_key' => $question['question_key'],
                'category' => $question['category'],
                'text' => $question['text'],
                'sort_order' => $question['sort_order'] ?? $index + 1,
            ]);
        }

        foreach (array_values($validated['result_ranges'] ?? []) as $range) {
            $template->resultRanges()->create([
                'category' => $range['category'],
                'summary' => $range['summary'],
            ]);
        }
    }

    private function abortUnlessCanView(Request $request): void
    {
        $request->attributes->set('audit.permission_checked', 'content.personality.view|content.view|settings.view');
        abort_unless($this->canView($request), 403, 'Bu islem icin yetkiniz bulunmuyor.');
    }

    private function abortUnlessCanManage(Request $request): void
    {
        $request->attributes->set('audit.permission_checked', 'content.personality.manage|content.site_settings.update|settings.update');
        abort_unless($this->canManage($request), 403, 'Bu islem icin yetkiniz bulunmuyor.');
    }

    private function canView(Request $request): bool
    {
        $user = $request->user();

        return $this->permissionResolver->hasGlobalScope($user, 'content.personality.view')
            || $this->permissionResolver->hasGlobalScope($user, 'content.view')
            || $this->permissionResolver->hasGlobalScope($user, 'settings.view');
    }

    private function canManage(Request $request): bool
    {
        $user = $request->user();

        return $this->permissionResolver->hasGlobalScope($user, 'content.personality.manage')
            || $this->permissionResolver->hasGlobalScope($user, 'content.site_settings.update')
            || $this->permissionResolver->hasGlobalScope($user, 'settings.update');
    }
}
