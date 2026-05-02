<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Participant;
use App\Models\Period;
use App\Services\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssignmentController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {
    }

    /**
     * Öğrencinin aktif ödevlerini ve teslim durumlarını listeler
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Aktif katılım sağladığı projelerin/dönemlerin ID'lerini bul
        $participations = Participant::where('user_id', $user->id)
            ->where('status', 'active')
            ->get();

        $projectIds = $participations->pluck('project_id');
        $periodIds = $participations->pluck('period_id');

        // Bu dönemlere ait ödevleri çek
        $assignments = Assignment::whereIn('project_id', $projectIds)
            ->whereIn('period_id', $periodIds)
            // Öğrencinin teslim durumunu (submission) relation olarak dahil et (eğer varsa)
            ->with(['submissions' => function($query) use ($user) {
                $query->where('user_id', $user->id);
            }])
            ->orderBy('due_date', 'asc')
            ->get();

        return response()->json([
            'assignments' => $assignments
        ]);
    }

    /**
     * Ödev Teslimi (Gönderme)
     */
    public function submit(Request $request, $id)
    {
        $validated = $request->validate([
            'title' => 'nullable|string',
            'description' => 'required|string',
            'file_path' => 'nullable|string', // AWS S3 veya R2 URL'si olabilir
        ]);

        $assignment = Assignment::findOrFail($id);
        $user = $request->user();

        // Daha önce teslim edilmiş mi kontrolü
        $existing = AssignmentSubmission::where('assignment_id', $assignment->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            // İstersek güncelleyebiliriz, şu anlık üzerine yazalım
            $existing->update([
                'title' => $validated['title'] ?? $existing->title,
                'description' => $validated['description'],
                'file_path' => $validated['file_path'] ?? $existing->file_path,
                'status' => 'submitted'
            ]);
            
            return response()->json(['message' => 'Ödev tesliminiz güncellendi.', 'submission' => $existing]);
        }

        // Yeni teslim
        $submission = AssignmentSubmission::create([
            'assignment_id' => $assignment->id,
            'user_id' => $user->id,
            'title' => $validated['title'],
            'description' => $validated['description'],
            'file_path' => $validated['file_path'],
            'status' => 'submitted'
        ]);

        return response()->json([
            'message' => 'Ödeviniz başarıyla sisteme yüklendi.',
            'submission' => $submission
        ], 201);
    }

    public function panelIndex(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'assignments.view');
        $user = $request->user();
        $projectIds = $this->permissionResolver->projectIdsForPermission($user, 'assignments.view');

        $query = Assignment::query()
            ->with([
                'project:id,name',
                'period:id,name',
                'program:id,title,start_at',
                'creator:id,name,surname',
                'submissions.user:id,name,surname,email',
                'submissions.reviewer:id,name,surname',
            ])
            ->withCount('submissions')
            ->orderByDesc('created_at');

        if (! $this->permissionResolver->hasGlobalScope($user, 'assignments.view')) {
            $query->whereIn('project_id', $projectIds);
        }

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->integer('project_id'));
        }

        return response()->json([
            'assignments' => $query->paginate(20),
        ]);
    }

    public function panelStore(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'assignments.create');
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'period_id' => 'required|exists:periods,id',
            'program_id' => 'nullable|exists:programs,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:3000',
            'due_date' => 'nullable|date',
        ]);

        $this->abortUnlessProjectAllowed($request, 'assignments.create', (int) $validated['project_id']);

        abort_unless(
            Period::query()
                ->where('id', $validated['period_id'])
                ->where('project_id', $validated['project_id'])
                ->exists(),
            422,
            'Secilen donem bu projeye ait degil.'
        );

        $assignment = Assignment::query()->create([
            'project_id' => $validated['project_id'],
            'period_id' => $validated['period_id'],
            'program_id' => $validated['program_id'] ?? null,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Odev olusturuldu.',
            'assignment' => $assignment->load(['project:id,name', 'period:id,name', 'program:id,title,start_at', 'creator:id,name,surname']),
        ], 201);
    }

    public function panelDestroy(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'assignments.delete');
        $assignment = Assignment::query()->findOrFail($id);
        $this->abortUnlessProjectAllowed($request, 'assignments.delete', (int) $assignment->project_id);
        $assignment->delete();

        return response()->json(['message' => 'Odev silindi.']);
    }

    public function panelReviewSubmission(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'assignments.submissions.review');
        $submission = AssignmentSubmission::query()
            ->with('assignment:id,project_id,title')
            ->findOrFail($id);

        $this->abortUnlessProjectAllowed($request, 'assignments.submissions.review', (int) $submission->assignment->project_id);

        $validated = $request->validate([
            'status' => 'required|in:reviewed,approved,rejected',
            'reviewer_note' => 'nullable|string|max:3000',
        ]);

        $submission->update([
            'status' => $validated['status'],
            'reviewer_note' => $validated['reviewer_note'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return response()->json([
            'message' => 'Odev teslimi guncellendi.',
            'submission' => $submission->fresh(['user:id,name,surname,email', 'reviewer:id,name,surname']),
        ]);
    }
}
