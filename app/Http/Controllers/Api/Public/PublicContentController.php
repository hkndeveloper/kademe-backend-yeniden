<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use App\Models\Faq;
use App\Models\Program;
use Illuminate\Http\Request;

class PublicContentController extends Controller
{
    /**
     * Yayinlanmis blog yazilarini getir.
     */
    public function blogs(Request $request)
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:100',
            'category_id' => 'nullable|integer|exists:blog_categories,id',
            'per_page' => 'nullable|integer|min:1|max:24',
        ]);

        $blogs = BlogPost::where('status', 'published')
            ->where('published_at', '<=', now())
            ->with('category')
            ->when(! empty($validated['search']), function ($query) use ($validated) {
                $search = $validated['search'];
                $query->where(function ($builder) use ($search) {
                    $builder
                        ->where('title', 'like', '%' . $search . '%')
                        ->orWhere('summary', 'like', '%' . $search . '%')
                        ->orWhere('content', 'like', '%' . $search . '%');
                });
            })
            ->when(! empty($validated['category_id']), fn ($query) => $query->where('category_id', $validated['category_id']))
            ->orderBy('published_at', 'desc')
            ->paginate($validated['per_page'] ?? 12)
            ->withQueryString();

        return response()->json(['blogs' => $blogs]);
    }

    /**
     * Blog detayi.
     */
    public function blogDetail($slug)
    {
        $blog = BlogPost::where('slug', $slug)
            ->where('status', 'published')
            ->firstOrFail();

        return response()->json(['blog' => $blog]);
    }

    /**
     * Sik sorulan sorulari getir.
     */
    public function faqs()
    {
        $faqs = Faq::orderBy('order', 'asc')->get()->groupBy('category');

        return response()->json(['faqs' => $faqs]);
    }

    /**
     * Public faaliyet ozeti ve liste akisi.
     */
    public function activities(Request $request)
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:100',
            'project_id' => 'nullable|integer|exists:projects,id',
            'status' => 'nullable|in:scheduled,active,completed',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'per_page' => 'nullable|integer|min:1|max:48',
        ]);

        $programs = Program::query()
            ->with(['project:id,name,slug'])
            ->whereIn('status', ! empty($validated['status']) ? [$validated['status']] : ['scheduled', 'active', 'completed'])
            ->when(empty($validated['from']) && empty($validated['to']), fn ($query) => $query->where('start_at', '>=', now()->subDays(30)))
            ->when(! empty($validated['from']), fn ($query) => $query->where('start_at', '>=', $validated['from']))
            ->when(! empty($validated['to']), fn ($query) => $query->where('start_at', '<=', $validated['to']))
            ->when(! empty($validated['project_id']), fn ($query) => $query->where('project_id', $validated['project_id']))
            ->when(! empty($validated['search']), function ($query) use ($validated) {
                $search = $validated['search'];
                $query->where(function ($builder) use ($search) {
                    $builder
                        ->where('title', 'like', '%' . $search . '%')
                        ->orWhere('description', 'like', '%' . $search . '%')
                        ->orWhere('location', 'like', '%' . $search . '%')
                        ->orWhereHas('project', fn ($projectQuery) => $projectQuery->where('name', 'like', '%' . $search . '%'));
                });
            })
            ->orderBy('start_at', 'asc')
            ->paginate($validated['per_page'] ?? 12)
            ->withQueryString();

        return response()->json([
            'programs' => $programs,
        ]);
    }

    /**
     * Public faaliyet detayi.
     */
    public function activityDetail($id)
    {
        $program = Program::query()
            ->with(['project:id,name,slug'])
            ->findOrFail($id);

        return response()->json([
            'program' => $program,
        ]);
    }
}
