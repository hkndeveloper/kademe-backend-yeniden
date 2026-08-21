<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Concerns\ResolvesProjectPeriodContext;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentAttachment;
use App\Models\AssignmentSubmission;
use App\Models\Participant;
use App\Models\Period;
use App\Services\NotificationService;
use App\Services\PermissionResolver;
use App\Support\AdminExportResponder;
use App\Support\MediaStorage;
use App\Support\IstanbulDateTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @group Assignments
 */
class AssignmentController extends Controller
{
    use AuthorizesGranularPermissions;
    use ResolvesProjectPeriodContext;

    public function __construct(
        private readonly PermissionResolver $permissionResolver,
        private readonly NotificationService $notificationService,
    ) {}

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

    private function attachmentPayload(AssignmentAttachment $attachment, string $basePath): array
    {
        return [
            'id' => $attachment->id,
            'assignment_id' => $attachment->assignment_id,
            'original_name' => $attachment->original_name,
            'file_path' => $attachment->file_path,
            'file_type' => $attachment->file_type,
            'file_size' => $attachment->file_size,
            'download_url' => "{$basePath}/{$attachment->id}/download",
            'created_at' => $attachment->created_at,
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
            'attachments' => $assignment->relationLoaded('attachments')
                ? $assignment->attachments->map(fn (AssignmentAttachment $attachment) => $this->attachmentPayload($attachment, str_replace('assignment-submissions', 'assignment-attachments', $submissionBasePath)))->values()
                : [],
            'submissions' => $assignment->relationLoaded('submissions')
                ? $assignment->submissions->map(fn (AssignmentSubmission $submission) => $this->submissionPayload($submission, $submissionBasePath))->values()
                : [],
        ];
    }

    private function streamAttachmentFile(AssignmentAttachment $attachment): JsonResponse|StreamedResponse
    {
        if (! $attachment->file_path) {
            return response()->json(['message' => 'Odev dosyasi bulunamadi.'], 404);
        }

        if ($this->isUrl($attachment->file_path) || (MediaStorage::directDownloadsEnabled() && MediaStorage::publicUrlConfigured())) {
            return response()->json([
                'download_url' => MediaStorage::url($attachment->file_path),
            ]);
        }

        if (! MediaStorage::exists($attachment->file_path)) {
            return response()->json(['message' => 'Odev dosyasi storage uzerinde bulunamadi.'], 404);
        }

        $extension = pathinfo($attachment->file_path, PATHINFO_EXTENSION);
        $baseName = $attachment->original_name ? pathinfo($attachment->original_name, PATHINFO_FILENAME) : 'odev_eki_'.$attachment->id;
        $filename = str($baseName)->slug()->toString() ?: 'odev_eki_'.$attachment->id;

        return MediaStorage::disk()->download(
            $attachment->file_path,
            $filename.($extension ? ".{$extension}" : '')
        );
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
        $filename = 'odev_teslimi_'.$submission->id;

        return MediaStorage::disk()->download(
            $submission->file_path,
            $filename.($extension ? ".{$extension}" : '')
        );
    }

    private function isUrl(string $path): bool
    {
        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://');
    }

    /**
     * List participant assignments and submissions.
     *
     * Requires permission: `participant.assignments.view`. Returns assignments from projects/periods where the current user is an active or alumni participant, including the current user submissions.
     *
     * @group Assignments
     * @authenticated
     *
     * @response 200 {"assignments":[{"id":1,"title":"Hafta 1 Odevi","due_date":"2026-07-01","submissions":[{"id":1,"status":"submitted","download_url":"/assignment-submissions/1/download"}]}]}
     * @response 401 {"message":"Unauthenticated."}
     * @response 403 {"message":"This action is unauthorized."}
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
            ->with(['attachments', 'submissions' => function ($query) use ($user) {
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
     * Submit or update an assignment submission.
     *
     * Requires permission: `participant.assignments.submit`. The current user must participate in the assignment project and period. Send as `multipart/form-data` when uploading `file`; existing submissions are updated.
     *
     * @group Assignments
     * @authenticated
     *
     * @urlParam id integer required Assignment id. Example: 1
     * @bodyParam title string Optional submission title. Example: Odevi tamamladim
     * @bodyParam description string required Submission description. Example: Calismam ekte yer almaktadir.
     * @bodyParam file_path string Optional external file path. Example: https://example.com/submission.pdf
     * @bodyParam file file Optional uploaded file, max 20MB.
     * @response 201 {"message":"Odeviniz basariyla sisteme yuklendi.","submission":{"id":1,"status":"submitted"}}
     * @response 200 {"message":"Odev tesliminiz guncellendi.","submission":{"id":1,"status":"submitted"}}
     * @response 403 {"message":"Bu odev icin teslim yetkiniz bulunmuyor."}
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
        $this->assertPeriodResolvable($request, $assignment->period_id);

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

            $assignment->loadMissing('creator:id,email,name,surname');
            $creatorEmail = $assignment->creator?->email;
            if ($creatorEmail) {
                $this->notificationService->sendEmail(
                    [$creatorEmail],
                    'Odev teslimi guncellendi',
                    "Odev: {$assignment->title}\nOgrenci: {$user->name} {$user->surname}\nTeslim guncellendi.",
                    $assignment->project_id,
                    $user->id
                );
            }

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

        $assignment->loadMissing('creator:id,email,name,surname');
        $creatorEmail = $assignment->creator?->email;
        if ($creatorEmail) {
            $this->notificationService->sendEmail(
                [$creatorEmail],
                'Yeni odev teslimi',
                "Odev: {$assignment->title}\nOgrenci: {$user->name} {$user->surname}\nYeni teslim eklendi.",
                $assignment->project_id,
                $user->id
            );
        }

        return response()->json([
            'message' => 'Odeviniz basariyla sisteme yuklendi.',
            'submission' => $this->submissionPayload($submission, '/assignment-submissions'),
        ], 201);
    }

    /**
     * Download my assignment submission file.
     *
     * Requires permission: `participant.assignments.view`. The submission must belong to the authenticated user. Returns a direct download URL when configured, otherwise streams the file.
     *
     * @group Assignments
     * @authenticated
     *
     * @urlParam id integer required Submission id. Example: 1
     * @response 200 {"download_url":"https://storage.example.com/assignment-submissions/file.pdf"}
     * @response 200 {"download":"Binary submission file stream"}
     * @response 404 {"message":"Teslim dosyasi bulunamadi."}
     */
    public function downloadAttachment(Request $request, int $id): JsonResponse|StreamedResponse
    {
        $attachment = AssignmentAttachment::query()->with('assignment:id,project_id,period_id')->findOrFail($id);
        $user = $request->user();

        $canView = Participant::query()
            ->where('user_id', $user->id)
            ->where('project_id', $attachment->assignment->project_id)
            ->where('period_id', $attachment->assignment->period_id)
            ->where(function ($query) use ($user) {
                $query->where('status', 'active');

                if ($user->role === 'alumni') {
                    $query->orWhere('graduation_status', 'graduated')
                        ->orWhereNotNull('graduated_at');
                }
            })
            ->exists();

        abort_unless($canView, 403, 'Bu odev dosyasi icin erisim yetkiniz bulunmuyor.');

        return $this->streamAttachmentFile($attachment);
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
        $validated = $request->validate([
            'project_id' => 'nullable|exists:projects,id',
            'period_id' => 'nullable|exists:periods,id',
        ]);
        $context = $this->resolveProjectPeriodContext(
            $request,
            'assignments.view',
            ! empty($validated['project_id']) ? (int) $validated['project_id'] : null,
            ! empty($validated['period_id']) ? (int) $validated['period_id'] : null,
        );

        $query = Assignment::query()
            ->with([
                'project:id,name',
                'period:id,name',
                'program:id,title,start_at',
                'creator:id,name,surname',
                'attachments',
                'submissions.user:id,name,surname,email',
                'submissions.reviewer:id,name,surname',
            ])
            ->withCount('submissions')
            ->orderByDesc('created_at');
        $this->applyProjectPeriodContext($query, $context);

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
            'attachment' => 'nullable|file|max:20480',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|max:20480',
        ]);
        $validated = IstanbulDateTime::normalizeFields($validated, ['due_date']);

        $this->abortUnlessProjectAllowed($request, 'assignments.create', (int) $validated['project_id']);

        abort_unless(
            Period::query()
                ->where('id', $validated['period_id'])
                ->where('project_id', $validated['project_id'])
                ->exists(),
            422,
            'Secilen donem bu projeye ait degil.'
        );
        $this->assertPeriodWritable($request, (int) $validated['period_id']);

        $assignment = Assignment::query()->create([
            'project_id' => $validated['project_id'],
            'period_id' => $validated['period_id'],
            'program_id' => $validated['program_id'] ?? null,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        $files = collect($request->file('attachments', []));
        if ($request->hasFile('attachment')) {
            $files->push($request->file('attachment'));
        }

        $files->each(function ($file) use ($assignment, $request) {
            $path = MediaStorage::putFile('assignment-attachments', $file);
            AssignmentAttachment::query()->create([
                'assignment_id' => $assignment->id,
                'original_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'file_type' => $file->getClientOriginalExtension(),
                'file_size' => $file->getSize(),
                'uploaded_by' => $request->user()->id,
            ]);
        });

        $participantEmails = Participant::query()
            ->where('project_id', (int) $validated['project_id'])
            ->where('period_id', (int) $validated['period_id'])
            ->where('status', 'active')
            ->with('user:id,email')
            ->get()
            ->pluck('user.email')
            ->filter()
            ->values()
            ->all();
        if ($participantEmails !== []) {
            $this->notificationService->sendEmail(
                $participantEmails,
                'Yeni odev tanimlandi',
                "Odev: {$assignment->title}\nSon teslim: ".($assignment->due_date ?? 'belirtilmedi')."\nLutfen panelden detaylari inceleyin.",
                (int) $validated['project_id'],
                $request->user()->id
            );
        }

        return response()->json([
            'message' => 'Odev olusturuldu.',
            'assignment' => $this->assignmentPayload($assignment->load(['project:id,name', 'period:id,name', 'program:id,title,start_at', 'creator:id,name,surname', 'attachments']), '/panel/assignment-submissions'),
        ], 201);
    }

    public function panelUpdate(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'assignments.update');

        $assignment = Assignment::query()
            ->withCount('submissions')
            ->with(['project:id,name', 'period:id,name', 'program:id,title,start_at', 'creator:id,name,surname', 'attachments'])
            ->findOrFail($id);

        $this->abortUnlessProjectAllowed($request, 'assignments.update', (int) $assignment->project_id);
        $this->assertPeriodWritable($request, $assignment->period_id);

        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'period_id' => 'required|exists:periods,id',
            'program_id' => 'nullable|exists:programs,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:3000',
            'due_date' => 'nullable|date',
            'attachment' => 'nullable|file|max:20480',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|max:20480',
        ]);
        $validated = IstanbulDateTime::normalizeFields($validated, ['due_date']);

        $nextProjectId = (int) $validated['project_id'];
        $nextPeriodId = (int) $validated['period_id'];
        $projectOrPeriodChanged = $nextProjectId !== (int) $assignment->project_id
            || $nextPeriodId !== (int) $assignment->period_id;

        if ($projectOrPeriodChanged && (int) $assignment->submissions_count > 0) {
            return response()->json([
                'message' => 'Teslimi olan odevin proje veya donemi degistirilemez.',
            ], 422);
        }

        $this->abortUnlessProjectAllowed($request, 'assignments.update', $nextProjectId);

        abort_unless(
            Period::query()
                ->where('id', $nextPeriodId)
                ->where('project_id', $nextProjectId)
                ->exists(),
            422,
            'Secilen donem bu projeye ait degil.'
        );
        $this->assertPeriodWritable($request, $nextPeriodId);

        $assignment->update([
            'project_id' => $nextProjectId,
            'period_id' => $nextPeriodId,
            'program_id' => $validated['program_id'] ?? null,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
        ]);

        $files = collect($request->file('attachments', []));
        if ($request->hasFile('attachment')) {
            $files->push($request->file('attachment'));
        }

        $files->each(function ($file) use ($assignment, $request) {
            $path = MediaStorage::putFile('assignment-attachments', $file);
            AssignmentAttachment::query()->create([
                'assignment_id' => $assignment->id,
                'original_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'file_type' => $file->getClientOriginalExtension(),
                'file_size' => $file->getSize(),
                'uploaded_by' => $request->user()->id,
            ]);
        });

        return response()->json([
            'message' => 'Odev guncellendi.',
            'assignment' => $this->assignmentPayload(
                $assignment->fresh([
                    'project:id,name',
                    'period:id,name',
                    'program:id,title,start_at',
                    'creator:id,name,surname',
                    'attachments',
                    'submissions.user:id,name,surname,email',
                    'submissions.reviewer:id,name,surname',
                ])->loadCount('submissions'),
                '/panel/assignment-submissions'
            ),
        ]);
    }

    public function panelExport(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'nullable|exists:projects,id',
            'period_id' => 'nullable|exists:periods,id',
            'format' => 'nullable|string|max:20',
        ]);
        $context = $this->resolveProjectPeriodContext(
            $request,
            'assignments.view',
            ! empty($validated['project_id']) ? (int) $validated['project_id'] : null,
            ! empty($validated['period_id']) ? (int) $validated['period_id'] : null,
        );

        $query = Assignment::query()
            ->with([
                'project:id,name',
                'period:id,name',
                'program:id,title,start_at',
                'creator:id,name,surname',
                'submissions.user:id,name,surname',
            ])
            ->withCount('submissions')
            ->orderByDesc('created_at');
        $this->applyProjectPeriodContext($query, $context);

        $assignments = $query->get();

        $headings = [
            'Odev ID', 'Baslik', 'Proje', 'Donem', 'Program', 'Teslim Tarihi',
            'Teslim Sayisi', 'Olusturan', 'Olusturma Tarihi', 'Teslimler',
        ];
        $rows = $assignments->map(function (Assignment $assignment) {
            $submissionSummary = $assignment->submissions
                ->map(fn (AssignmentSubmission $submission) => trim(($submission->user?->name ?? '-').' '.($submission->user?->surname ?? ''))." ({$submission->status})")
                ->implode('; ');

            return [
                $assignment->id,
                $assignment->title,
                $assignment->project?->name ?? '-',
                $assignment->period?->name ?? '-',
                $assignment->program?->title ?? '-',
                $assignment->due_date?->format('d.m.Y H:i') ?? '-',
                $assignment->submissions_count,
                $assignment->creator ? trim($assignment->creator->name.' '.$assignment->creator->surname) : '-',
                $assignment->created_at?->format('d.m.Y H:i') ?? '-',
                $submissionSummary !== '' ? $submissionSummary : '-',
            ];
        })->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'odevler_'.now()->format('Ymd_His'),
            'Odevler',
            $headings,
            $rows,
        );
    }

    public function panelDestroy(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'assignments.delete');
        $assignment = Assignment::query()->with(['submissions:id,assignment_id,file_path', 'attachments:id,assignment_id,file_path'])->findOrFail($id);
        $this->abortUnlessProjectAllowed($request, 'assignments.delete', (int) $assignment->project_id);
        $this->assertPeriodWritable($request, $assignment->period_id);
        $assignment->submissions->each(fn (AssignmentSubmission $submission) => MediaStorage::delete($submission->file_path));
        $assignment->attachments->each(fn (AssignmentAttachment $attachment) => MediaStorage::delete($attachment->file_path));
        $assignment->delete();

        return response()->json(['message' => 'Odev silindi.']);
    }

    public function panelReviewSubmission(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'assignments.submissions.review');
        $submission = AssignmentSubmission::query()
            ->with('assignment:id,project_id,period_id,title')
            ->findOrFail($id);

        $this->abortUnlessProjectAllowed($request, 'assignments.submissions.review', (int) $submission->assignment->project_id);
        $this->assertPeriodResolvable($request, $submission->assignment->period_id);

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

        $submission->loadMissing(['user:id,email,name,surname', 'assignment:id,title,project_id']);
        $studentEmail = $submission->user?->email;
        if ($studentEmail) {
            $this->notificationService->sendEmail(
                [$studentEmail],
                'Odev tesliminiz degerlendirildi',
                "Odev: {$submission->assignment?->title}\nYeni durum: {$validated['status']}\nNot: ".($validated['reviewer_note'] ?? '-'),
                $submission->assignment?->project_id,
                $request->user()->id
            );
        }

        return response()->json([
            'message' => 'Odev teslimi guncellendi.',
            'submission' => $this->submissionPayload(
                $submission->fresh(['user:id,name,surname,email', 'reviewer:id,name,surname']),
                '/panel/assignment-submissions'
            ),
        ]);
    }

    public function panelDownloadAttachment(Request $request, int $id): JsonResponse|StreamedResponse
    {
        $this->abortUnlessAllowed($request, 'assignments.view');

        $attachment = AssignmentAttachment::query()
            ->with('assignment:id,project_id,period_id,title')
            ->findOrFail($id);

        $this->abortUnlessProjectAllowed($request, 'assignments.view', (int) $attachment->assignment->project_id);

        return $this->streamAttachmentFile($attachment);
    }

    public function panelDownloadSubmission(Request $request, int $id): JsonResponse|StreamedResponse
    {
        $this->abortUnlessAllowed($request, 'assignments.submissions.review');

        $submission = AssignmentSubmission::query()
            ->with('assignment:id,project_id')
            ->findOrFail($id);

        $this->abortUnlessProjectAllowed($request, 'assignments.submissions.review', (int) $submission->assignment->project_id);
        $request->attributes->set('audit.subject', $submission);
        $request->attributes->set('audit.event', 'assignments.submission.downloaded');
        $request->attributes->set('audit.description', 'assignments.submission.downloaded');
        $request->attributes->set('audit.properties', [
            'operation' => 'assignment_submission_download',
            'submission_id' => $submission->id,
            'assignment_id' => $submission->assignment_id,
            'project_id' => $submission->assignment->project_id,
            'status' => $submission->status,
            'submitted_by' => $submission->user_id,
        ]);

        return $this->streamSubmissionFile($submission);
    }
}
