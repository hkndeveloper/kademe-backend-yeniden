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

/**
 * @group Content Management
 */
class ContentManagementController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {}

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

    private function canUseBlogProject(Request $request, string $permission, ?int $projectId): bool
    {
        if (! $this->permissionResolver->hasPermission($request->user(), $permission)) {
            return false;
        }

        return $projectId === null
            ? $this->hasGlobalContentPermission($request, $permission)
            : $this->permissionResolver->canAccessProject($request->user(), $permission, $projectId);
    }

    private function blogCapabilities(Request $request, BlogPost $blog): array
    {
        $canPublish = $this->canUseBlogProject($request, 'content.blog.publish', $blog->project_id);
        $canUpdate = $this->canUseBlogProject($request, 'content.blog.update', $blog->project_id)
            && ($blog->status !== 'published' || $canPublish);

        return [
            'update' => $canUpdate,
            'publish' => $canPublish,
            'delete' => $this->canUseBlogProject($request, 'content.blog.delete', $blog->project_id),
        ];
    }

    /**
     * List panel content resources.
     *
     * Panel/admin endpoint exposed under `/admin/content` and `/panel/content`. Requires `content.view` with a non-empty scope. Global scope returns all blogs, FAQs, categories, and projects; project-scoped users only see blog posts and projects inside their `content.view` project scope and receive an empty FAQ list because FAQs are global content.
     *
     * @group Content Management
     *
     * @authenticated
     *
     * @response 200 {"blogs":[{"id":1,"title":"Haber","status":"published","project":{"id":1,"name":"KADEME"}}],"categories":[{"id":1,"name":"Duyurular"}],"faqs":[],"content_scope":{"global":false,"project_ids":[1]},"projects":[{"id":1,"name":"KADEME"}]}
     * @response 403 {"message":"Bu icerik islemi icin kapsam verilmemis."}
     */
    public function index(Request $request): JsonResponse
    {
        $this->abortUnlessContentPermission($request, 'content.view');
        $blogQuery = BlogPost::query()->with(['category', 'project:id,name']);
        $this->applyBlogScope($blogQuery, $request, 'content.view');
        $canViewGlobalContent = $this->hasGlobalContentPermission($request, 'content.view');

        $blogs = $blogQuery->orderByDesc('created_at')->get();

        return response()->json([
            'blogs' => $blogs->map(fn (BlogPost $blog) => [
                ...$blog->toArray(),
                'capabilities' => $this->blogCapabilities($request, $blog),
            ])->values(),
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

    /**
     * Export panel blog posts.
     *
     * Panel/admin endpoint exposed under `/admin/content/blogs/export` and `/panel/content/blogs/export`. Requires `content.blog.export` with a non-empty scope. Global scope exports all blogs; scoped users export only blogs tied to projects in their blog export scope. The shared export responder accepts `csv`, `xlsx`, or `pdf` when enabled.
     *
     * @group Content Management
     *
     * @authenticated
     *
     * @queryParam format string Optional export format. Example: xlsx
     *
     * @response 200 {"download":"Export file stream"}
     * @response 403 {"message":"Bu icerik islemi icin kapsam verilmemis."}
     */
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
            'blog_yazilari_'.now()->format('Ymd_His'),
            'Blog Yazilari',
            $headings,
            $rows,
        );
    }

    /**
     * Export FAQs.
     *
     * Panel/admin endpoint exposed under `/admin/content/faqs/export` and `/panel/content/faqs/export`. Requires `content.faq.export` with global/tum sistem scope because FAQ content is not project-scoped. The shared export responder accepts `csv`, `xlsx`, or `pdf` when enabled.
     *
     * @group Content Management
     *
     * @authenticated
     *
     * @queryParam format string Optional export format. Example: csv
     *
     * @response 200 {"download":"Export file stream"}
     * @response 403 {"message":"Bu global icerik islemi icin tum sistem kapsami gerekir."}
     */
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
            'sss_listesi_'.now()->format('Ymd_His'),
            'SSS',
            $headings,
            $rows,
        );
    }

    /**
     * Create a blog post.
     *
     * Panel/admin endpoint exposed under `/admin/content/blogs` and `/panel/content/blogs`. Requires `content.blog.create` with a non-empty scope. Project blogs require create access to the selected project; global blog posts with `project_id=null` require global/tum sistem scope. If `slug` is omitted, the backend generates one from the title.
     *
     * @group Content Management
     *
     * @authenticated
     *
     * @bodyParam title string required Blog title. Example: Yeni haber
     * @bodyParam slug string Optional unique slug. Example: yeni-haber
     * @bodyParam excerpt string Optional short summary, max 1000 chars. Example: Kisa ozet
     * @bodyParam content string required Blog body/content. Example: Icerik metni
     * @bodyParam cover_image_path string Optional media path or URL. Example: uploads/blogs/cover.jpg
     * @bodyParam category_id integer Optional blog category id. Example: 1
     * @bodyParam project_id integer Optional project id; scoped by `content.blog.create`. Null requires global scope. Example: 1
     * @bodyParam status string required One of draft or published. Example: published
     * @bodyParam published_at datetime Optional publish date; defaults to now when status is published. Example: 2026-06-30 10:00:00
     *
     * @response 201 {"message":"Blog yazisi olusturuldu.","blog":{"id":1,"title":"Yeni haber","status":"published"}}
     * @response 403 {"message":"Bu proje icin blog icerigi yonetme yetkiniz yok."}
     * @response 422 {"message":"Global blog yazisi icin tum sistem kapsami gerekir."}
     */
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
        if ($validated['status'] === 'published') {
            $this->assertCanUseBlogProject($request, 'content.blog.publish', $validated['project_id'] ?? null);
        }

        $blog = BlogPost::create([
            ...$validated,
            'slug' => $validated['slug'] ?: Str::slug($validated['title']).'-'.Str::lower(Str::random(4)),
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

    /**
     * Update a blog post.
     *
     * Panel/admin endpoint exposed under `/admin/content/blogs/{id}` and `/panel/content/blogs/{id}`. Requires `content.blog.update` with access to the blog's current project scope and access to the new `project_id` when moving a blog. Moving a blog to global content requires global/tum sistem scope.
     *
     * @group Content Management
     *
     * @authenticated
     *
     * @urlParam id integer required Blog post id. Example: 1
     *
     * @bodyParam title string required Blog title. Example: Guncel haber
     * @bodyParam slug string required Unique slug. Example: guncel-haber
     * @bodyParam excerpt string Optional short summary, max 1000 chars. Example: Guncel ozet
     * @bodyParam content string required Blog body/content. Example: Guncel icerik
     * @bodyParam cover_image_path string Optional media path or URL. Example: uploads/blogs/cover.jpg
     * @bodyParam category_id integer Optional blog category id. Example: 1
     * @bodyParam project_id integer Optional project id; scoped by `content.blog.update`. Null requires global scope. Example: 1
     * @bodyParam status string required One of draft or published. Example: draft
     * @bodyParam published_at datetime Optional publish date. Example: 2026-06-30 10:00:00
     *
     * @response 200 {"message":"Blog yazisi guncellendi.","blog":{"id":1,"title":"Guncel haber","status":"draft"}}
     * @response 403 {"message":"Bu proje icin blog icerigi yonetme yetkiniz yok."}
     * @response 422 {"message":"The slug has already been taken."}
     */
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
        if ($blog->status === 'published' || $validated['status'] === 'published') {
            $this->assertCanManageBlog($request, 'content.blog.publish', $blog);
            $this->assertCanUseBlogProject($request, 'content.blog.publish', $validated['project_id'] ?? null);
        }

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

    /**
     * Delete a blog post.
     *
     * Panel/admin endpoint exposed under `/admin/content/blogs/{id}` and `/panel/content/blogs/{id}`. Requires `content.blog.delete` with access to the blog project. Global blog posts require global/tum sistem scope.
     *
     * @group Content Management
     *
     * @authenticated
     *
     * @urlParam id integer required Blog post id. Example: 1
     *
     * @response 200 {"message":"Blog yazisi silindi."}
     * @response 403 {"message":"Bu proje icin blog icerigi yonetme yetkiniz yok."}
     */
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

    /**
     * Create an FAQ item.
     *
     * Panel/admin endpoint exposed under `/admin/content/faqs` and `/panel/content/faqs`. Requires `content.faq.create` with global/tum sistem scope because FAQs are global public content.
     *
     * @group Content Management
     *
     * @authenticated
     *
     * @bodyParam question string required FAQ question, max 1000 chars. Example: Basvuru nasil yapilir?
     * @bodyParam answer string required FAQ answer. Example: Basvuru formunu doldurabilirsiniz.
     * @bodyParam category string required FAQ category. Example: Basvuru
     * @bodyParam order integer Optional display order. Example: 10
     *
     * @response 201 {"message":"SSS maddesi olusturuldu.","faq":{"id":1,"question":"Basvuru nasil yapilir?"}}
     * @response 403 {"message":"Bu global icerik islemi icin tum sistem kapsami gerekir."}
     */
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

    /**
     * Update an FAQ item.
     *
     * Panel/admin endpoint exposed under `/admin/content/faqs/{id}` and `/panel/content/faqs/{id}`. Requires `content.faq.update` with global/tum sistem scope because FAQs are global public content.
     *
     * @group Content Management
     *
     * @authenticated
     *
     * @urlParam id integer required FAQ id. Example: 1
     *
     * @bodyParam question string required FAQ question, max 1000 chars. Example: Basvuru nasil yapilir?
     * @bodyParam answer string required FAQ answer. Example: Basvuru formunu doldurabilirsiniz.
     * @bodyParam category string required FAQ category. Example: Basvuru
     * @bodyParam order integer Optional display order. Example: 10
     *
     * @response 200 {"message":"SSS maddesi guncellendi.","faq":{"id":1,"question":"Basvuru nasil yapilir?"}}
     * @response 403 {"message":"Bu global icerik islemi icin tum sistem kapsami gerekir."}
     */
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

    /**
     * Delete an FAQ item.
     *
     * Panel/admin endpoint exposed under `/admin/content/faqs/{id}` and `/panel/content/faqs/{id}`. Requires `content.faq.delete` with global/tum sistem scope.
     *
     * @group Content Management
     *
     * @authenticated
     *
     * @urlParam id integer required FAQ id. Example: 1
     *
     * @response 200 {"message":"SSS maddesi silindi."}
     * @response 403 {"message":"Bu global icerik islemi icin tum sistem kapsami gerekir."}
     */
    public function deleteFaq(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessGlobalContentPermission($request, 'content.faq.delete');

        Faq::findOrFail($id)->delete();

        return response()->json([
            'message' => 'SSS maddesi silindi.',
        ]);
    }
}
