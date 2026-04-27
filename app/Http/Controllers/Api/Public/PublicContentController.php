<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use App\Models\Faq;
use App\Models\Program;

class PublicContentController extends Controller
{
    /**
     * Yayinlanmis blog yazilarini getir.
     */
    public function blogs()
    {
        $blogs = BlogPost::where('status', 'published')
            ->where('published_at', '<=', now())
            ->with('category')
            ->orderBy('published_at', 'desc')
            ->paginate(12);

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
    public function activities()
    {
        $programs = Program::query()
            ->with(['project:id,name,slug'])
            ->whereIn('status', ['scheduled', 'active'])
            ->where('start_at', '>=', now()->subDays(30))
            ->orderBy('start_at', 'asc')
            ->limit(24)
            ->get();

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
