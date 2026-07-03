<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicBlogResource;
use App\Http\Resources\PublicProgramResource;
use App\Models\BlogPost;
use App\Models\Faq;
use App\Models\Program;
use Illuminate\Http\Request;

/**
 * @group Public Content
 */
class PublicContentController extends Controller
{
    /**
     * List published blog posts.
     *
     * @group Public Content
     * @unauthenticated
     *
     * Returns only published blog posts whose `published_at` is not in the future. Response is paginated.
     *
     * @queryParam search string Optional search term. Example: liderlik
     * @queryParam category_id integer Optional blog category id. Example: 1
     * @queryParam per_page integer Optional page size, max 24. Example: 12
     * @response 200 {"blogs":{"current_page":1,"data":[{"id":1,"title":"KADEME Blog","slug":"kademe-blog","excerpt":"Kisa ozet"}],"per_page":12,"total":1}}
     * @response 422 {"message":"The per page field must not be greater than 24.","errors":{"per_page":["The per page field must not be greater than 24."]}}
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
                        ->where('title', 'like', '%'.$search.'%')
                        ->orWhere('excerpt', 'like', '%'.$search.'%')
                        ->orWhere('content', 'like', '%'.$search.'%');
                });
            })
            ->when(! empty($validated['category_id']), fn ($query) => $query->where('category_id', $validated['category_id']))
            ->orderBy('published_at', 'desc')
            ->paginate($validated['per_page'] ?? 12)
            ->withQueryString();
        $blogs->setCollection(PublicBlogResource::collection($blogs->getCollection())->collection);

        return response()->json([
            'blogs' => $blogs,
        ]);
    }

    /**
     * Get a published blog post detail.
     *
     * @group Public Content
     * @unauthenticated
     *
     * @urlParam slug string required Blog slug. Example: ornek-blog-yazisi
     * @response 200 {"blog":{"id":1,"title":"KADEME Blog","slug":"kademe-blog","content":"Blog icerigi"}}
     * @response 404 {"message":"No query results for model [App\\Models\\BlogPost]."}
     */
    public function blogDetail($slug)
    {
        $blog = BlogPost::where('slug', $slug)
            ->where('status', 'published')
            ->firstOrFail();

        return response()->json([
            'blog' => new PublicBlogResource($blog),
        ]);
    }

    /**
     * List frequently asked questions.
     *
     * @group Public Content
     * @unauthenticated
     *
     * Returns FAQ records grouped by category.
     *
     * @response 200 {"faqs":{"Genel":[{"id":1,"question":"KADEME nedir?","answer":"KADEME gelisim ekosistemidir.","category":"Genel"}]}}
     */
    public function faqs()
    {
        $faqs = Faq::orderBy('order', 'asc')->get()->groupBy('category');

        return response()->json(['faqs' => $faqs]);
    }

    /**
     * List public activities.
     *
     * @group Public Content
     * @unauthenticated
     *
     * Lists public programs/activities. Response is paginated.
     *
     * @queryParam search string Optional search term. Example: atelye
     * @queryParam project_id integer Optional project id. Example: 1
     * @queryParam status string Optional status: scheduled, active or completed. Example: scheduled
     * @queryParam from date Optional start date lower bound. Example: 2026-01-01
     * @queryParam to date Optional start date upper bound. Example: 2026-12-31
     * @queryParam per_page integer Optional page size, max 48. Example: 12
     * @response 200 {"programs":{"current_page":1,"data":[{"id":1,"title":"Liderlik Atolyesi","status":"scheduled","start_at":"2026-07-01T10:00:00+03:00","project":{"id":1,"name":"KADEME"}}],"per_page":12,"total":1}}
     * @response 422 {"message":"The to field must be a date after or equal to from.","errors":{"to":["The to field must be a date after or equal to from."]}}
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
            ->with(['project:id,name,slug', 'period:id,name'])
            ->where('is_public', true)
            ->whereIn('status', ! empty($validated['status']) ? [$validated['status']] : ['scheduled', 'active', 'completed'])
            ->when(empty($validated['from']) && empty($validated['to']), fn ($query) => $query->where('start_at', '>=', now()->subYear()))
            ->when(! empty($validated['from']), fn ($query) => $query->where('start_at', '>=', $validated['from']))
            ->when(! empty($validated['to']), fn ($query) => $query->where('start_at', '<=', $validated['to']))
            ->when(! empty($validated['project_id']), fn ($query) => $query->where('project_id', $validated['project_id']))
            ->when(! empty($validated['search']), function ($query) use ($validated) {
                $search = $validated['search'];
                $query->where(function ($builder) use ($search) {
                    $builder
                        ->where('title', 'like', '%'.$search.'%')
                        ->orWhere('description', 'like', '%'.$search.'%')
                        ->orWhere('location', 'like', '%'.$search.'%')
                        ->orWhereHas('project', fn ($projectQuery) => $projectQuery->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->orderBy('start_at', 'asc')
            ->paginate($validated['per_page'] ?? 12)
            ->withQueryString();
        $programs->setCollection(PublicProgramResource::collection($programs->getCollection())->collection);

        return response()->json([
            'programs' => $programs,
        ]);
    }

    /**
     * Get public activity detail.
     *
     * @group Public Content
     * @unauthenticated
     *
     * @urlParam id integer required Program id. Example: 1
     * @response 200 {"program":{"id":1,"title":"Liderlik Atolyesi","status":"scheduled","location":"Istanbul","photos":[]}}
     * @response 404 {"message":"No query results for model [App\\Models\\Program]."}
     */
    public function activityDetail($id)
    {
        $program = Program::query()
            ->with(['project:id,name,slug', 'period:id,name', 'photos'])
            ->where('is_public', true)
            ->whereIn('status', ['scheduled', 'active', 'completed'])
            ->findOrFail($id);

        return response()->json([
            'program' => new PublicProgramResource($program),
        ]);
    }
}
