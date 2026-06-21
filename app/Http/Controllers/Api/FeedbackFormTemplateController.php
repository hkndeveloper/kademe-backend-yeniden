<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeedbackFormTemplate;
use App\Services\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FeedbackFormTemplateController extends Controller
{
    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {
    }

    private function authorizeTemplate(Request $request, string $permission, ?int $projectId): void
    {
        $user = $request->user();

        if ($projectId === null) {
            abort_unless($this->permissionResolver->hasGlobalScope($user, $permission), 403, 'Global form sablonu icin tum sistem yetkisi gerekir.');

            return;
        }

        abort_unless($this->permissionResolver->canAccessProject($user, $permission, $projectId), 403, 'Bu proje icin form sablonu yetkiniz yok.');
    }

    private function serialize(FeedbackFormTemplate $template): array
    {
        return [
            'id' => $template->id,
            'project_id' => $template->project_id,
            'project' => $template->project ? [
                'id' => $template->project->id,
                'name' => $template->project->name,
                'slug' => $template->project->slug,
            ] : null,
            'name' => $template->name,
            'description' => $template->description,
            'is_default' => (bool) $template->is_default,
            'is_active' => (bool) $template->is_active,
            'questions' => $template->questions->map(fn ($question) => [
                'id' => $question->id,
                'question_key' => $question->question_key,
                'label' => $question->label,
                'type' => $question->type,
                'options' => $question->options ?? [],
                'min_value' => $question->min_value,
                'max_value' => $question->max_value,
                'is_required' => (bool) $question->is_required,
                'sort_order' => $question->sort_order,
            ])->values(),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($this->permissionResolver->hasPermission($request->user(), 'programs.view'), 403, 'Form sablonlarini goruntuleme yetkiniz yok.');

        $projectId = $request->integer('project_id') ?: null;
        if ($projectId !== null) {
            $this->authorizeTemplate($request, 'programs.view', $projectId);
        }

        $templates = FeedbackFormTemplate::query()
            ->with(['project:id,name,slug', 'questions'])
            ->when($projectId, fn ($query) => $query->where(function ($inner) use ($projectId) {
                $inner->where('project_id', $projectId)->orWhereNull('project_id');
            }))
            ->when(! $projectId && ! $this->permissionResolver->hasGlobalScope($request->user(), 'programs.view'), function ($query) use ($request) {
                $projectIds = $request->user()->coordinatedProjects()->pluck('projects.id')
                    ->merge($request->user()->assignedProjects()->pluck('projects.id'))
                    ->unique()
                    ->values();

                $query->whereIn('project_id', $projectIds);
            })
            ->orderByRaw('project_id is not null desc')
            ->orderBy('name')
            ->get()
            ->map(fn (FeedbackFormTemplate $template) => $this->serialize($template))
            ->values();

        return response()->json(['templates' => $templates]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => 'nullable|exists:projects,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'questions' => 'required|array|min:1|max:20',
            'questions.*.question_key' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9_]+$/'],
            'questions.*.label' => 'required|string|max:255',
            'questions.*.type' => ['required', Rule::in(['rating', 'text', 'choice'])],
            'questions.*.options' => 'nullable|array',
            'questions.*.options.*' => 'nullable|string|max:255',
            'questions.*.min_value' => 'nullable|integer|min:1|max:10',
            'questions.*.max_value' => 'nullable|integer|min:1|max:10',
            'questions.*.is_required' => 'boolean',
        ]);

        $this->authorizeTemplate($request, 'programs.create', $validated['project_id'] ?? null);

        $template = FeedbackFormTemplate::query()->create([
            'project_id' => $validated['project_id'] ?? null,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_default' => (bool) ($validated['is_default'] ?? false),
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'created_by' => $request->user()->id,
        ]);

        $this->syncQuestions($template, $validated['questions']);

        return response()->json([
            'message' => 'Degerlendirme form sablonu olusturuldu.',
            'template' => $this->serialize($template->fresh(['project:id,name,slug', 'questions'])),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $template = FeedbackFormTemplate::query()->findOrFail($id);
        $this->authorizeTemplate($request, 'programs.update', $template->project_id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'questions' => 'sometimes|required|array|min:1|max:20',
            'questions.*.question_key' => ['required_with:questions', 'string', 'max:80', 'regex:/^[a-z0-9_]+$/'],
            'questions.*.label' => 'required_with:questions|string|max:255',
            'questions.*.type' => ['required_with:questions', Rule::in(['rating', 'text', 'choice'])],
            'questions.*.options' => 'nullable|array',
            'questions.*.options.*' => 'nullable|string|max:255',
            'questions.*.min_value' => 'nullable|integer|min:1|max:10',
            'questions.*.max_value' => 'nullable|integer|min:1|max:10',
            'questions.*.is_required' => 'boolean',
        ]);

        $template->update(collect($validated)->except('questions')->all());

        if (array_key_exists('questions', $validated)) {
            $this->syncQuestions($template, $validated['questions']);
        }

        return response()->json([
            'message' => 'Degerlendirme form sablonu guncellendi.',
            'template' => $this->serialize($template->fresh(['project:id,name,slug', 'questions'])),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $template = FeedbackFormTemplate::query()->findOrFail($id);
        $this->authorizeTemplate($request, 'programs.update', $template->project_id);

        $template->delete();

        return response()->json(['message' => 'Degerlendirme form sablonu silindi.']);
    }

    private function syncQuestions(FeedbackFormTemplate $template, array $questions): void
    {
        $template->questions()->delete();

        foreach (array_values($questions) as $index => $question) {
            $type = in_array($question['type'], ['text', 'choice'], true) ? $question['type'] : 'rating';
            $options = $type === 'choice'
                ? array_values(array_filter($question['options'] ?? [], fn ($option) => filled($option)))
                : null;

            if ($type === 'choice' && count($options) < 2) {
                abort(422, 'Seçenekli sorular için en az iki seçenek gerekir.');
            }

            $template->questions()->create([
                'question_key' => $question['question_key'],
                'label' => $question['label'],
                'type' => $type,
                'options' => $options,
                'min_value' => $type === 'rating' ? ($question['min_value'] ?? 1) : null,
                'max_value' => $type === 'rating' ? ($question['max_value'] ?? 5) : null,
                'is_required' => (bool) ($question['is_required'] ?? true),
                'sort_order' => $index + 1,
            ]);
        }
    }
}
