<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\Faq;
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

    public function index(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'content.view');

        return response()->json([
            'blogs' => BlogPost::with('category')->orderByDesc('created_at')->get(),
            'categories' => BlogCategory::orderBy('name')->get(),
            'faqs' => Faq::orderBy('category')->orderBy('order')->get(),
        ]);
    }

    public function exportBlogs(Request $request)
    {
        $this->abortUnlessAllowed($request, 'content.blog.export');

        $blogs = BlogPost::with('category')
            ->orderByDesc('created_at')
            ->get();

        $headings = ['ID', 'Baslik', 'Slug', 'Kategori', 'Durum', 'Yayin Tarihi', 'Olusturma Tarihi'];
        $rows = $blogs->map(fn (BlogPost $blog) => [
            $blog->id,
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
        $this->abortUnlessAllowed($request, 'content.faq.export');

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
        $this->abortUnlessAllowed($request, 'content.blog.create');

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:blog_posts,slug',
            'excerpt' => 'nullable|string|max:1000',
            'content' => 'required|string',
            'cover_image_path' => 'nullable|string|max:2048',
            'category_id' => 'nullable|exists:blog_categories,id',
            'status' => ['required', Rule::in(['draft', 'published'])],
            'published_at' => 'nullable|date',
        ]);

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
        $this->abortUnlessAllowed($request, 'content.blog.update');

        $blog = BlogPost::findOrFail($id);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => ['required', 'string', 'max:255', Rule::unique('blog_posts', 'slug')->ignore($blog->id)],
            'excerpt' => 'nullable|string|max:1000',
            'content' => 'required|string',
            'cover_image_path' => 'nullable|string|max:2048',
            'category_id' => 'nullable|exists:blog_categories,id',
            'status' => ['required', Rule::in(['draft', 'published'])],
            'published_at' => 'nullable|date',
        ]);

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
        $this->abortUnlessAllowed($request, 'content.blog.delete');

        BlogPost::findOrFail($id)->delete();

        return response()->json([
            'message' => 'Blog yazisi silindi.',
        ]);
    }

    public function storeFaq(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'content.faq.create');

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
        $this->abortUnlessAllowed($request, 'content.faq.update');

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
        $this->abortUnlessAllowed($request, 'content.faq.delete');

        Faq::findOrFail($id)->delete();

        return response()->json([
            'message' => 'SSS maddesi silindi.',
        ]);
    }
}
