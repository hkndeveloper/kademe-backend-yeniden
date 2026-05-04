<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Participant;
use App\Models\Period;
use App\Services\PermissionResolver;
use App\Support\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssignmentController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {
    }

    private function submissionPayload(AssignmentSubmission $submission, string $basePath): array
    {
        return [
            'id' => $submission->id,
            'assignment_id' => $submission->assignment_id,
            'user_id' => $submission->user_id,
            'title' => $submission->title,
            'description' => $submission->description,
            'file_path' => $submission->file_path,
            'download_url' => $submission->file_path ? "{$basePath}/{$submission->id}/download" : null,
            'status' => $submission->status,
            'reviewer_note' => $submission->reviewer_note,
            'reviewed_by' => $submission->reviewed_by,
            'reviewed_at' => $submission->reviewed_at,
            'created_at' => $submission->created_at,
            'updated_at' => $submission->updated_at,
            'user' => $submission->relationLoaded('user') ? $submission->user : null,
            'reviewer' => $submission->relationLoaded('reviewer') ? $submission->reviewer : null,
        ];
    }

    private function assignmentPayload(Assignment $assignment, string $submissionBasePath): array
    {
        return [
            'id' => $assignment->id,
            'project_id' => $assignment->project_id,
            'period_id' => $assignment->period_id,
            'program_id' => $assignment->program_id,
            'title' => $assignment->title,
            'description' => $assignment->description,
            'due_date' => $assignment->due_date,
            'created_by' => $assignment->created_by,
            'created_at' => $assignment->created_at,
            'updated_at' => $assignment->updated_at,
            'project' => $assignment->relationLoaded('project') ? $assignment->project : null,
            'period' => $assignment->relationLoaded('period') ? $assignment->period : null,
            'program' => $assignment->relationLoaded('program') ? $assignment->program : null,
            'creator' => $assignment->relationLoaded('creator') ? $assignment->creator : null,
            'submissions_count' => $assignment->submissions_count ?? (
                $assignment->relationLoaded('submissions') ? $assignment->submissions->count() : null
            ),
            'submissions' => $assignment->relationLoaded('submissions')
                ? $assignment->submissions->map(fn (AssignmentSubmission $submission) => $this->submissionPayload($submission, $submissionBasePath))->values()
                : [],
        ];
    }

    private function streamSubmissionFile(AssignmentSubmission $submission): JsonResponse|StreamedResponse
    {
        if (! $submission->file_path) {
            return response()->json(['message' => 'Teslim dosyasi bulunamadi.'], 404);
        }

        if ($this->isUrl($submission->file_path) || (MediaStorage::directDownloadsEnabled() && MediaStorage::publicUrlConfigured())) {
            return response()->json([
                'download_url' => MediaStorage::url($submission->file_path),
            ]);
        }

        if (! MediaStorage::exists($submission->file_path)) {
            return response()->json(['message' => 'Teslim dosyasi storage uzerinde bulunamadi.'], 404);
        }

        $extension = pathinfo($submission->file_path, PATHINFO_EXTENSION);
        $filename = 'odev_teslimi_' . $submission->id;

        return MediaStorage::disk()->download(
            $submission->file_path,
            $filename . ($extension ? ".{$extension}" : '')
        );
    }

    private function isUrl(string $path): bool
    {
        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://');
    }

    /**
     * Öğrencinin aktif ödevlerini ve teslim durumlarını listeler
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Aktif katılım sağladığı projelerin/dönemlerin ID'lerini bul
        $participations = Participant::where('user_id', $user->id)
            ->where(function ($query) use ($user) {
                $query->where('status', 'active');

                if ($user->role === 'alumni') {
                    $query->orWhere('graduation_status', 'graduated')
                        ->orWhereNotNull('graduated_at');
                }
            })
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
                ->map(fn (Assignment $assignment) => $this->assignmentPayload($assignment, '/assignment-submissions'))
                ->values(),
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
            'file_path' => 'nullable|string',
            'file' => 'nullable|file|max:20480',
        ]);

        $assignment = Assignment::findOrFail($id);
        $user = $request->user();

        $canSubmit = Participant::query()
            ->where('user_id', $user->id)
            ->where('project_id', $assignment->project_id)
            ->where('period_id', $assignment->period_id)
            ->where(function ($query) use ($user) {
                $query->where('status', 'active');

                if ($user->role === 'alumni') {
                    $query->orWhere('graduation_status', 'graduated')
                        ->orWhereNotNull('graduated_at');
                }
            })
            ->exists();

        abort_unless($canSubmit, 403, 'Bu odev icin teslim yetkiniz bulunmuyor.');

        $filePath = $validated['file_path'] ?? null;
        if ($request->hasFile('file')) {
            $filePath = MediaStorage::putFile('assignment-submissions', $request->file('file'));
        }

        $existing = AssignmentSubmission::where('assignment_id', $assignment->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            if ($request->hasFile('file') && $existing->file_path) {
                MediaStorage::delete($existing->file_path);
            }

            $existing->update([
                'title' => $validated['title'] ?? $existing->title,
                'description' => $validated['description'],
                'file_path' => $filePath ?? $existing->file_path,
                'status' => 'submitted',
            ]);

            return response()->json([
                'message' => 'Odev tesliminiz guncellendi.',
                'submission' => $this->submissionPayload($existing->fresh(), '/assignment-submissions'),
            ]);
        }

        $submission = AssignmentSubmission::create([
            'assignment_id' => $assignment->id,
            'user_id' => $user->id,
            'title' => $validated['title'] ?? null,
            'description' => $validated['description'],
            'file_path' => $filePath,
            'status' => 'submitted',
        ]);

        return response()->json([
            'message' => 'Odeviniz basariyla sisteme yuklendi.',
            'submission' => $this->submissionPayload($submission, '/assignment-submissions'),
        ], 201);
    }

    public function downloadSubmission(Request $request, int $id): JsonResponse|StreamedResponse
    {
        $submission = AssignmentSubmission::query()
            ->with('assignment:id,project_id,period_id')
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);

        return $this->streamSubmissionFile($submission);
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
            $projectId = $request->integer('project_id');
            abort_unless(
                $this->permissionResolver->canAccessProject($user, 'assignments.view', $projectId),
                403,
                'Bu proje icin odev goruntuleme yetkiniz yok.'
            );
            $query->where('project_id', $projectId);
        }

        return response()->json([
            'assignments' => $query->paginate(20)->through(
                fn (Assignment $assignment) => $this->assignmentPayload($assignment, '/panel/assignment-submissions')
            ),
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
        $assignment = Assignment::query()->with('submissions:id,assignment_id,file_path')->findOrFail($id);
        $this->abortUnlessProjectAllowed($request, 'assignments.delete', (int) $assignment->project_id);
        $assignment->submissions->each(fn (AssignmentSubmission $submission) => MediaStorage::delete($submission->file_path));
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
            'submission' => $this->submissionPayload(
                $submission->fresh(['user:id,name,surname,email', 'reviewer:id,name,surname']),
                '/panel/assignment-submissions'
            ),
        ]);
    }

    public function panelDownloadSubmission(Request $request, int $id): JsonResponse|StreamedResponse
    {
        $this->abortUnlessAllowed($request, 'assignments.submissions.review');

        $submission = AssignmentSubmission::query()
            ->with('assignment:id,project_id')
            ->findOrFail($id);

        $this->abortUnlessProjectAllowed($request, 'assignments.submissions.review', (int) $submission->assignment->project_id);

        return $this->streamSubmissionFile($submission);
    }
}
