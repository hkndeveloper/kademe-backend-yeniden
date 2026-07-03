<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PersonalityTestTemplate;
use App\Services\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @group Personality Tests
 */
class PersonalityTestTemplateController extends Controller
{
    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {
    }

    /**
     * List personality test templates.
     *
     * Panel/admin endpoint exposed under `/admin/personality-test-templates` and `/panel/personality-test-templates`. Requires global scope for at least one of `content.personality.view`, `content.view`, or `settings.view`. Returns templates with questions and result ranges, active template first.
     *
     * @group Personality Tests
     * @authenticated
     *
     * @response 200 {"templates":[{"id":1,"name":"Varsayilan test","is_active":true,"questions":[],"result_ranges":[]}]}
     * @response 403 {"message":"Bu islem icin yetkiniz bulunmuyor."}
     */
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

    /**
     * Create a personality test template.
     *
     * Panel/admin endpoint exposed under `/admin/personality-test-templates` and `/panel/personality-test-templates`. Requires global scope for at least one of `content.personality.manage`, `content.site_settings.update`, or `settings.update`. Questions and result ranges are synced transactionally; setting `is_active=true` deactivates all other templates.
     *
     * @group Personality Tests
     * @authenticated
     *
     * @bodyParam name string required Template name. Example: Varsayilan test
     * @bodyParam description string Optional description, max 1000 chars. Example: Guncel kisilik analizi
     * @bodyParam is_active boolean Optional. Activates this template and deactivates others. Example: true
     * @bodyParam questions object[] required 1-40 question rows.
     * @bodyParam questions[].question_key string required Lowercase key with letters, numbers, underscores. Example: leadership_1
     * @bodyParam questions[].category string required Result category. Example: leadership
     * @bodyParam questions[].text string required Question text. Example: Ekip calismasinda sorumluluk alirim.
     * @bodyParam questions[].sort_order integer Optional order value. Example: 1
     * @bodyParam result_ranges object[] Optional result range rows, max 20.
     * @bodyParam result_ranges[].category string required Result category. Example: leadership
     * @bodyParam result_ranges[].summary string required Result summary. Example: Liderlik yonu guclu.
     * @response 201 {"message":"Kisilik analizi sablonu olusturuldu.","template":{"id":1,"name":"Varsayilan test","is_active":true}}
     * @response 403 {"message":"Bu islem icin yetkiniz bulunmuyor."}
     * @response 422 {"message":"The questions field is required."}
     */
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

    /**
     * Update a personality test template.
     *
     * Panel/admin endpoint exposed under `/admin/personality-test-templates/{id}` and `/panel/personality-test-templates/{id}`. Requires global manage scope through `content.personality.manage`, `content.site_settings.update`, or `settings.update`. Child questions and result ranges are replaced transactionally; setting `is_active=true` deactivates all other templates.
     *
     * @group Personality Tests
     * @authenticated
     *
     * @urlParam id integer required Template id. Example: 1
     * @bodyParam name string required Template name. Example: Varsayilan test
     * @bodyParam description string Optional description, max 1000 chars. Example: Guncel kisilik analizi
     * @bodyParam is_active boolean Optional. Activates this template and deactivates others. Example: false
     * @bodyParam questions object[] required 1-40 question rows.
     * @bodyParam questions[].question_key string required Lowercase key with letters, numbers, underscores. Example: leadership_1
     * @bodyParam questions[].category string required Result category. Example: leadership
     * @bodyParam questions[].text string required Question text. Example: Ekip calismasinda sorumluluk alirim.
     * @bodyParam questions[].sort_order integer Optional order value. Example: 1
     * @bodyParam result_ranges object[] Optional result range rows, max 20.
     * @bodyParam result_ranges[].category string required Result category. Example: leadership
     * @bodyParam result_ranges[].summary string required Result summary. Example: Liderlik yonu guclu.
     * @response 200 {"message":"Kisilik analizi sablonu guncellendi.","template":{"id":1,"name":"Varsayilan test"}}
     * @response 403 {"message":"Bu islem icin yetkiniz bulunmuyor."}
     */
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

    /**
     * Activate a personality test template.
     *
     * Panel/admin endpoint exposed under `/admin/personality-test-templates/{id}/activate` and `/panel/personality-test-templates/{id}/activate`. Requires global manage scope through `content.personality.manage`, `content.site_settings.update`, or `settings.update`. The template must have at least one question; activating it deactivates all others.
     *
     * @group Personality Tests
     * @authenticated
     *
     * @urlParam id integer required Template id. Example: 1
     * @response 200 {"message":"Kisilik analizi sablonu aktif edildi.","template":{"id":1,"is_active":true}}
     * @response 403 {"message":"Bu islem icin yetkiniz bulunmuyor."}
     * @response 422 {"message":"Aktif etmek icin en az bir soru gerekir."}
     */
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

    /**
     * Delete a personality test template.
     *
     * Panel/admin endpoint exposed under `/admin/personality-test-templates/{id}` and `/panel/personality-test-templates/{id}`. Requires global manage scope through `content.personality.manage`, `content.site_settings.update`, or `settings.update`. Active templates cannot be deleted.
     *
     * @group Personality Tests
     * @authenticated
     *
     * @urlParam id integer required Template id. Example: 1
     * @response 200 {"message":"Kisilik analizi sablonu silindi."}
     * @response 403 {"message":"Bu islem icin yetkiniz bulunmuyor."}
     * @response 422 {"message":"Aktif kisilik analizi sablonu silinemez."}
     */
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
