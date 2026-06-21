<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\Faq;
use App\Models\Project;
use App\Services\PermissionResolver;
use App\Support\AdminExportResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ContentManagementController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {
    }

    private function abortUnlessGlobalContentPermission(Request $request, string $permission): void
    {
        $this->abortUnlessAllowed($request, $permission);

        abort_unless(
            $this->permissionResolver->hasGlobalScope($request->user(), $permission),
            403,
            'Bu global icerik islemi icin tum sistem kapsami gerekir.'
        );
    }

    private function abortUnlessContentPermission(Request $request, string $permission): void
    {
        $this->abortUnlessAllowed($request, $permission);

        $scope = $this->permissionResolver->scopeFor($request->user(), $permission);
        abort_if(($scope['scope_type'] ?? 'none') === 'none', 403, 'Bu icerik islemi icin kapsam verilmemis.');
    }

    private function hasGlobalContentPermission(Request $request, string $permission): bool
    {
        return $this->permissionResolver->hasGlobalScope($request->user(), $permission);
    }

    private function applyBlogScope($query, Request $request, string $permission): void
    {
        if ($this->hasGlobalContentPermission($request, $permission)) {
            return;
        }

        $projectIds = $this->permissionResolver->projectIdsForPermission($request->user(), $permission);
        $query->whereIn('project_id', $projectIds);
    }

    private function assertCanUseBlogProject(Request $request, string $permission, ?int $projectId): void
    {
        if ($projectId === null) {
            abort_unless(
                $this->hasGlobalContentPermission($request, $permission),
                422,
                'Global blog yazisi icin tum sistem kapsami gerekir.'
            );

            return;
        }

        Project::query()->findOrFail($projectId);

        abort_unless(
            $this->hasGlobalContentPermission($request, $permission)
                || $this->permissionResolver->canAccessProject($request->user(), $permission, $projectId),
            403,
            'Bu proje icin blog icerigi yonetme yetkiniz yok.'
        );
    }

    private function assertCanManageBlog(Request $request, string $permission, BlogPost $blog): void
    {
        $this->assertCanUseBlogProject($request, $permission, $blog->project_id);
    }

    public function index(Request $request): JsonResponse
    {
        $this->abortUnlessContentPermission($request, 'content.view');
        $blogQuery = BlogPost::query()->with(['category', 'project:id,name']);
        $this->applyBlogScope($blogQuery, $request, 'content.view');
        $canViewGlobalContent = $this->hasGlobalContentPermission($request, 'content.view');

        return response()->json([
            'blogs' => $blogQuery->orderByDesc('created_at')->get(),
            'categories' => BlogCategory::orderBy('name')->get(),
            'faqs' => $canViewGlobalContent ? Faq::orderBy('category')->orderBy('order')->get() : [],
            'content_scope' => [
                'global' => $canViewGlobalContent,
                'project_ids' => $canViewGlobalContent ? [] : $this->permissionResolver->projectIdsForPermission($request->user(), 'content.view'),
            ],
            'projects' => Project::query()
                ->when(
                    ! $canViewGlobalContent,
                    fn ($query) => $query->whereIn('id', $this->permissionResolver->projectIdsForPermission($request->user(), 'content.view'))
                )
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function exportBlogs(Request $request)
    {
        $this->abortUnlessContentPermission($request, 'content.blog.export');

        $query = BlogPost::query()->with(['category', 'project:id,name']);
        $this->applyBlogScope($query, $request, 'content.blog.export');
        $blogs = $query->orderByDesc('created_at')->get();

        $headings = ['ID', 'Proje', 'Baslik', 'Slug', 'Kategori', 'Durum', 'Yayin Tarihi', 'Olusturma Tarihi'];
        $rows = $blogs->map(fn (BlogPost $blog) => [
            $blog->id,
            $blog->project?->name ?? 'Global',
            $blog->title,
            $blog->slug,
            $blog->category?->name ?? '-',
            $blog->status,
            optional($blog->published_at)?->format('Y-m-d H:i:s') ?? '-',
            optional($blog->created_at)?->format('Y-m-d H:i:s') ?? '-',
        ])->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'blog_yazilari_' . now()->format('Ymd_His'),
            'Blog Yazilari',
            $headings,
            $rows,
        );
    }

    public function exportFaqs(Request $request)
    {
        $this->abortUnlessGlobalContentPermission($request, 'content.faq.export');

        $faqs = Faq::orderBy('category')
            ->orderBy('order')
            ->get();

        $headings = ['ID', 'Kategori', 'Soru', 'Cevap', 'Sira', 'Olusturma Tarihi'];
        $rows = $faqs->map(fn (Faq $faq) => [
            $faq->id,
            $faq->category,
            $faq->question,
            $faq->answer,
            $faq->order,
            optional($faq->created_at)?->format('Y-m-d H:i:s') ?? '-',
        ])->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'sss_listesi_' . now()->format('Ymd_His'),
            'SSS',
            $headings,
            $rows,
        );
    }

    public function storeBlog(Request $request): JsonResponse
    {
        $this->abortUnlessContentPermission($request, 'content.blog.create');

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:blog_posts,slug',
            'excerpt' => 'nullable|string|max:1000',
            'content' => 'required|string',
            'cover_image_path' => 'nullable|string|max:2048',
            'category_id' => 'nullable|exists:blog_categories,id',
            'project_id' => 'nullable|integer|exists:projects,id',
            'status' => ['required', Rule::in(['draft', 'published'])],
            'published_at' => 'nullable|date',
        ]);
        $this->assertCanUseBlogProject($request, 'content.blog.create', $validated['project_id'] ?? null);

        $blog = BlogPost::create([
            ...$validated,
            'slug' => $validated['slug'] ?: Str::slug($validated['title']) . '-' . Str::lower(Str::random(4)),
            'author_id' => $request->user()->id,
            'published_at' => $validated['status'] === 'published'
                ? ($validated['published_at'] ?? now())
                : null,
        ]);

        return response()->json([
            'message' => 'Blog yazisi olusturuldu.',
            'blog' => $blog->load('category'),
        ], 201);
    }

    public function updateBlog(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessContentPermission($request, 'content.blog.update');

        $blog = BlogPost::findOrFail($id);
        $this->assertCanManageBlog($request, 'content.blog.update', $blog);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => ['required', 'string', 'max:255', Rule::unique('blog_posts', 'slug')->ignore($blog->id)],
            'excerpt' => 'nullable|string|max:1000',
            'content' => 'required|string',
            'cover_image_path' => 'nullable|string|max:2048',
            'category_id' => 'nullable|exists:blog_categories,id',
            'project_id' => 'nullable|integer|exists:projects,id',
            'status' => ['required', Rule::in(['draft', 'published'])],
            'published_at' => 'nullable|date',
        ]);
        $this->assertCanUseBlogProject($request, 'content.blog.update', $validated['project_id'] ?? null);

        $blog->update([
            ...$validated,
            'published_at' => $validated['status'] === 'published'
                ? ($validated['published_at'] ?? $blog->published_at ?? now())
                : null,
        ]);

        return response()->json([
            'message' => 'Blog yazisi guncellendi.',
            'blog' => $blog->fresh('category'),
        ]);
    }

    public function deleteBlog(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessContentPermission($request, 'content.blog.delete');

        $blog = BlogPost::findOrFail($id);
        $this->assertCanManageBlog($request, 'content.blog.delete', $blog);
        $blog->delete();

        return response()->json([
            'message' => 'Blog yazisi silindi.',
        ]);
    }

    public function storeFaq(Request $request): JsonResponse
    {
        $this->abortUnlessGlobalContentPermission($request, 'content.faq.create');

        $validated = $request->validate([
            'question' => 'required|string|max:1000',
            'answer' => 'required|string',
            'category' => 'required|string|max:255',
            'order' => 'nullable|integer|min:0',
        ]);

        $faq = Faq::create([
            ...$validated,
            'order' => $validated['order'] ?? 0,
        ]);

        return response()->json([
            'message' => 'SSS maddesi olusturuldu.',
            'faq' => $faq,
        ], 201);
    }

    public function updateFaq(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessGlobalContentPermission($request, 'content.faq.update');

        $faq = Faq::findOrFail($id);

        $validated = $request->validate([
            'question' => 'required|string|max:1000',
            'answer' => 'required|string',
            'category' => 'required|string|max:255',
            'order' => 'nullable|integer|min:0',
        ]);

        $faq->update([
            ...$validated,
            'order' => $validated['order'] ?? 0,
        ]);

        return response()->json([
            'message' => 'SSS maddesi guncellendi.',
            'faq' => $faq,
        ]);
    }

    public function deleteFaq(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessGlobalContentPermission($request, 'content.faq.delete');

        Faq::findOrFail($id)->delete();

        return response()->json([
            'message' => 'SSS maddesi silindi.',
        ]);
    }
}
