<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Services\PermissionResolver;
use App\Support\AdminExportResponder;
use App\Support\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class CoordinatorParticipantController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {}

    private function mediaUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return MediaStorage::url($path);
    }

    /** @return int[] */
    private function projectIdsForAnyPermission(Request $request, array $permissions): array
    {
        return collect($permissions)
            ->filter(fn (string $permission) => $this->permissionResolver->hasPermission($request->user(), $permission))
            ->flatMap(fn (string $permission) => $this->permissionResolver->projectIdsForPermission($request->user(), $permission))
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function canAccessProjectWithAnyPermission(Request $request, array $permissions, int $projectId): bool
    {
        foreach ($permissions as $permission) {
            if ($this->permissionResolver->canAccessProject($request->user(), $permission, $projectId)) {
                return true;
            }
        }

        return false;
    }

    private function uniqueCertificateCode(): string
    {
        do {
            $code = strtoupper(Str::random(10));
        } while (Certificate::query()->where('verification_code', $code)->exists());

        return $code;
    }

    public function index(Request $request): JsonResponse
    {
        $viewPermissions = [
            'projects.participants.view',
            'projects.alumni.view',
            'projects.student_cv.view',
        ];
        $this->abortUnlessAnyPermission($request, $viewPermissions);
        $validated = $request->validate([
            'project_id' => 'nullable|exists:projects,id',
            'status' => 'nullable|string|max:50',
            'graduation_status' => 'nullable|string|max:50',
            'search' => 'nullable|string|max:255',
        ]);
        $coordinator = $request->user();
        $manageableProjectIds = $this->projectIdsForAnyPermission($request, $viewPermissions);
        $canViewParticipants = $this->permissionResolver->hasPermission($coordinator, 'projects.participants.view');
        $canViewAlumni = $this->permissionResolver->hasPermission($coordinator, 'projects.alumni.view');
        $canViewCv = $this->permissionResolver->hasPermission($coordinator, 'projects.student_cv.view');

        $query = Participant::with([
            'project:id,name',
            'period:id,name',
            'user:id,name,surname,email,phone,university,department,class_year,hometown,profile_photo_path,status',
            'user.profile:id,user_id,digital_cv_data,linkedin_url,github_url',
        ])->whereIn('project_id', $manageableProjectIds);

        if (! empty($validated['project_id'])) {
            abort_unless(
                $this->canAccessProjectWithAnyPermission($request, $viewPermissions, (int) $validated['project_id']),
                403,
                'Bu proje icin yetkiniz bulunmuyor.'
            );
            $query->where('project_id', (int) $validated['project_id']);
        }

        if (! empty($validated['status']) && in_array($validated['status'], ['graduated', 'completed'], true)) {
            $validated['graduation_status'] = $validated['status'];
        } elseif (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['graduation_status'])) {
            $query->where('graduation_status', $validated['graduation_status']);
        }

        if (! $canViewParticipants && $canViewAlumni) {
            $query->where(function ($builder) {
                $builder
                    ->where('graduation_status', 'graduated')
                    ->orWhereNotNull('graduated_at');
            });
        }

        if (! $canViewParticipants && ! $canViewAlumni && $canViewCv) {
            $query->whereHas('user.profile', function ($builder) {
                $builder->whereNotNull('digital_cv_data');
            });
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->whereHas('user', function ($builder) use ($search) {
                $builder->where(function ($userQuery) use ($search) {
                    $userQuery->where('name', 'like', "%$search%")
                        ->orWhere('surname', 'like', "%$search%")
                        ->orWhere('email', 'like', "%$search%")
                        ->orWhere('university', 'like', "%$search%")
                        ->orWhere('department', 'like', "%$search%");
                });
            });
        }

        $participants = $query->orderByDesc('created_at')->get();

        return response()->json([
            'projects' => Project::query()
                ->whereIn('id', $manageableProjectIds)
                ->orderBy('name')
                ->get(['id', 'name']),
            'summary' => [
                'total' => $participants->count(),
                'active' => $participants->where('status', 'active')->count(),
                'graduates' => $participants->filter(fn ($participant) => ! is_null($participant->graduated_at) || $participant->graduation_status === 'graduated'
                )->count(),
                'average_credit' => $participants->count() > 0
                    ? round($participants->avg('credit') ?? 0, 1)
                    : 0,
            ],
            'participants' => $participants->map(function ($participant) use ($coordinator, $canViewCv, $canViewParticipants, $canViewAlumni) {
                $user = $participant->user;
                $profileAllowed = (
                    $canViewParticipants
                    && $this->permissionResolver->canAccessProject($coordinator, 'projects.participants.view', (int) $participant->project_id)
                ) || (
                    $canViewAlumni
                    && $this->permissionResolver->canAccessProject($coordinator, 'projects.alumni.view', (int) $participant->project_id)
                    && (! is_null($participant->graduated_at) || $participant->graduation_status === 'graduated')
                );
                $cvAllowed = $canViewCv
                    && $this->permissionResolver->canAccessProject($coordinator, 'projects.student_cv.view', (int) $participant->project_id);

                return [
                    'id' => $participant->id,
                    'status' => $participant->status,
                    'graduation_status' => $participant->graduation_status,
                    'graduation_note' => $participant->graduation_note,
                    'credit' => $participant->credit,
                    'enrolled_at' => optional($participant->enrolled_at)?->toDateString(),
                    'graduated_at' => optional($participant->graduated_at)?->toDateString(),
                    'project' => [
                        'id' => $participant->project?->id,
                        'name' => $participant->project?->name,
                    ],
                    'period' => [
                        'id' => $participant->period?->id,
                        'name' => $participant->period?->name,
                    ],
                    'user' => [
                        'id' => $user?->id,
                        'name' => $user?->name,
                        'surname' => $user?->surname,
                        'email' => $profileAllowed ? $user?->email : null,
                        'phone' => $profileAllowed ? $user?->phone : null,
                        'university' => $user?->university,
                        'department' => $user?->department,
                        'class_year' => $user?->class_year,
                        'hometown' => $user?->hometown,
                        'status' => $user?->status,
                        'profile_photo' => $this->mediaUrl($user?->profile_photo_path),
                        'cv' => $cvAllowed ? [
                            'digital_cv_data' => $user?->profile?->digital_cv_data,
                            'linkedin_url' => $user?->profile?->linkedin_url,
                            'github_url' => $user?->profile?->github_url,
                        ] : null,
                    ],
                ];
            })->values(),
        ]);
    }

    public function export(Request $request)
    {
        $this->abortUnlessAllowed($request, 'projects.participants.view');
        $validated = $request->validate([
            'project_id' => 'nullable|exists:projects,id',
            'status' => 'nullable|string|max:50',
            'graduation_status' => 'nullable|string|max:50',
            'search' => 'nullable|string|max:255',
            'format' => 'nullable|string|max:20',
        ]);
        $coordinator = $request->user();
        $manageableProjectIds = $this->permissionResolver->projectIdsForPermission($coordinator, 'projects.participants.view');

        $query = Participant::with([
            'project:id,name',
            'period:id,name',
            'user:id,name,surname,email,phone,university,department,class_year,hometown',
        ])->whereIn('project_id', $manageableProjectIds);

        if (! empty($validated['project_id'])) {
            $this->abortUnlessProjectAllowed($request, 'projects.participants.view', (int) $validated['project_id']);
            $query->where('project_id', (int) $validated['project_id']);
        }

        if (! empty($validated['status']) && in_array($validated['status'], ['graduated', 'completed'], true)) {
            $validated['graduation_status'] = $validated['status'];
        } elseif (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['graduation_status'])) {
            $query->where('graduation_status', $validated['graduation_status']);
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->whereHas('user', function ($builder) use ($search) {
                $builder->where(function ($userQuery) use ($search) {
                    $userQuery->where('name', 'like', "%$search%")
                        ->orWhere('surname', 'like', "%$search%")
                        ->orWhere('email', 'like', "%$search%")
                        ->orWhere('university', 'like', "%$search%")
                        ->orWhere('department', 'like', "%$search%");
                });
            });
        }

        $participants = $query->orderByDesc('created_at')->get();
        $headings = ['ID', 'Ad', 'Soyad', 'E-posta', 'Telefon', 'Proje', 'Donem', 'Universite', 'Bolum', 'Sinif', 'Kredi', 'Durum', 'Mezuniyet'];
        $rows = $participants->map(fn (Participant $participant) => [
            $participant->id,
            $participant->user?->name ?? '-',
            $participant->user?->surname ?? '-',
            $participant->user?->email ?? '-',
            $participant->user?->phone ?? '-',
            $participant->project?->name ?? '-',
            $participant->period?->name ?? '-',
            $participant->user?->university ?? '-',
            $participant->user?->department ?? '-',
            $participant->user?->class_year ?? '-',
            $participant->credit ?? 0,
            $participant->status,
            $participant->graduation_status ?? '-',
        ])->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'katilimcilar_'.now()->format('Ymd_His'),
            'Katilimcilar',
            $headings,
            $rows,
        );
    }

    /**
     * @return array{participant: Participant, certificate: Certificate|null}
     */
    private function applyGraduationTransition(
        Participant $participant,
        string $graduationStatus,
        ?string $graduationNote,
        User $actor
    ): array {
        $status = $graduationStatus;
        $participantStatus = match ($status) {
            'graduated' => 'graduated',
            'not_completed' => 'failed',
            default => 'passive',
        };

        $participant->update([
            'status' => $participantStatus,
            'graduation_status' => $status,
            'graduation_note' => $graduationNote,
            'graduated_at' => in_array($status, ['completed', 'graduated'], true) ? now() : null,
        ]);

        if ($status === 'graduated' && $participant->user) {
            Role::findOrCreate('alumni', 'web');
            $participant->user->update([
                'role' => 'alumni',
                'status' => 'alumni',
            ]);
            if ($participant->user->hasRole('student')) {
                $participant->user->removeRole('student');
            }
            $participant->user->assignRole('alumni');
        }

        $certificate = null;
        if (in_array($status, ['completed', 'graduated'], true)) {
            $certificateType = $status === 'graduated' ? 'graduation' : 'participation';
            $certificate = Certificate::query()->firstOrCreate(
                [
                    'user_id' => $participant->user_id,
                    'project_id' => $participant->project_id,
                    'type' => $certificateType,
                ],
                [
                    'period_id' => $participant->period_id,
                    'verification_code' => $this->uniqueCertificateCode(),
                    'issued_at' => now(),
                    'created_by' => $actor->id,
                ]
            );
            $certificate = $this->ensureCertificateComplete($certificate, $actor);
        }

        return [
            'participant' => $participant->fresh(['user', 'project:id,name', 'period:id,name']),
            'certificate' => $certificate,
        ];
    }

    private function ensureCertificateComplete(Certificate $certificate, User $actor): Certificate
    {
        $updates = [];
        if ($certificate->verification_code === null || $certificate->verification_code === '') {
            $updates['verification_code'] = $this->uniqueCertificateCode();
        }
        if ($certificate->issued_at === null) {
            $updates['issued_at'] = now();
        }
        if ($certificate->created_by === null) {
            $updates['created_by'] = $actor->id;
        }
        if ($updates !== []) {
            $certificate->update($updates);
        }

        return $certificate->fresh();
    }

    public function updateGraduationStatus(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'projects.participants.manage');
        $participant = Participant::with(['user', 'project:id,name', 'period:id,name'])->findOrFail($id);
        $this->abortUnlessProjectAllowed($request, 'projects.participants.manage', (int) $participant->project_id);
        $before = [
            'status' => $participant->status,
            'graduation_status' => $participant->graduation_status,
            'graduation_note' => $participant->graduation_note,
            'graduated_at' => optional($participant->graduated_at)?->toIso8601String(),
        ];

        $validated = $request->validate([
            'graduation_status' => 'required|in:completed,graduated,not_completed',
            'graduation_note' => 'nullable|string|max:2000',
        ]);

        if ($validated['graduation_status'] === 'not_completed' && trim((string) ($validated['graduation_note'] ?? '')) === '') {
            return response()->json([
                'message' => 'Tamamlayamadi durumunda gerekce zorunludur.',
            ], 422);
        }

        $result = DB::transaction(function () use ($participant, $validated, $request) {
            return $this->applyGraduationTransition(
                $participant,
                $validated['graduation_status'],
                $validated['graduation_note'] ?? null,
                $request->user(),
            );
        });
        $request->attributes->set('audit.subject', $result['participant']);
        $request->attributes->set('audit.event', 'participants.graduation.updated');
        $request->attributes->set('audit.description', 'participants.graduation.updated');
        $request->attributes->set('audit.attribute_changes', [
            'before' => $before,
            'after' => [
                'status' => $result['participant']->status,
                'graduation_status' => $result['participant']->graduation_status,
                'graduation_note' => $result['participant']->graduation_note,
                'graduated_at' => optional($result['participant']->graduated_at)?->toIso8601String(),
            ],
        ]);

        return response()->json([
            'message' => match ($validated['graduation_status']) {
                'graduated' => 'Katilimci mezun olarak isaretlendi.',
                'completed' => 'Katilimci tamamladi olarak isaretlendi.',
                default => 'Katilimci tamamlayamadi olarak isaretlendi.',
            },
            'participant' => $result['participant'],
            'certificate' => $result['certificate'],
        ]);
    }

    public function bulkUpdateGraduationStatus(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'projects.participants.manage');
        $validated = $request->validate([
            'participant_ids' => 'required|array|min:1|max:200',
            'participant_ids.*' => 'integer|exists:participants,id',
            'graduation_status' => 'required|in:completed,graduated,not_completed',
            'graduation_note' => 'nullable|string|max:2000',
        ]);

        if ($validated['graduation_status'] === 'not_completed' && trim((string) ($validated['graduation_note'] ?? '')) === '') {
            return response()->json([
                'message' => 'Tamamlayamadi durumunda gerekce zorunludur.',
            ], 422);
        }

        $ids = collect($validated['participant_ids'])->map(fn ($id) => (int) $id)->unique()->values()->all();
        $participants = Participant::with(['user', 'project:id,name', 'period:id,name'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        if (count($participants) !== count($ids)) {
            return response()->json(['message' => 'Bazi katilimci kayitlari bulunamadi.'], 422);
        }

        foreach ($participants as $participant) {
            $this->abortUnlessProjectAllowed($request, 'projects.participants.manage', (int) $participant->project_id);
        }

        $actor = $request->user();
        $results = [];
        $changeSnapshots = [];

        foreach ($ids as $participantId) {
            $participant = $participants->get($participantId);
            try {
                $payload = DB::transaction(function () use ($participant, $validated, $actor) {
                    return $this->applyGraduationTransition(
                        $participant,
                        $validated['graduation_status'],
                        $validated['graduation_note'] ?? null,
                        $actor,
                    );
                });
                $results[] = [
                    'participant_id' => $participantId,
                    'ok' => true,
                    'participant' => $payload['participant'],
                    'certificate' => $payload['certificate'],
                ];
                $changeSnapshots[] = [
                    'participant_id' => $participantId,
                    'after' => [
                        'status' => $payload['participant']->status,
                        'graduation_status' => $payload['participant']->graduation_status,
                        'graduation_note' => $payload['participant']->graduation_note,
                        'graduated_at' => optional($payload['participant']->graduated_at)?->toIso8601String(),
                    ],
                ];
            } catch (\Throwable) {
                $results[] = [
                    'participant_id' => $participantId,
                    'ok' => false,
                    'error' => 'Islem tamamlanamadi.',
                ];
            }
        }

        $okCount = collect($results)->where('ok', true)->count();
        $request->attributes->set('audit.event', 'participants.graduation.bulk_updated');
        $request->attributes->set('audit.description', 'participants.graduation.bulk_updated');
        $request->attributes->set('audit.attribute_changes', [
            'requested_participant_ids' => $ids,
            'graduation_status' => $validated['graduation_status'],
            'graduation_note' => $validated['graduation_note'] ?? null,
            'successful_count' => $okCount,
            'failed_count' => count($results) - $okCount,
            'successful_changes' => $changeSnapshots,
        ]);

        return response()->json([
            'message' => $okCount === count($results)
                ? 'Tum katilimcilar guncellendi.'
                : 'Bazi katilimcilar guncellenemedi.',
            'results' => $results,
        ]);
    }
}
