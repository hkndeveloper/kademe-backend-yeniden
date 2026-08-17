<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Concerns\ResolvesProjectPeriodContext;
use App\Http\Controllers\Controller;
use App\Models\Badge;
use App\Models\Certificate;
use App\Models\CreditLog;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Services\PermissionResolver;
use App\Support\AdminExportResponder;
use App\Support\CertificatePdfGenerator;
use App\Support\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Barryvdh\DomPDF\Facade\Pdf;
use Spatie\Permission\Models\Role;

/**
 * @group Participants
 */
class CoordinatorParticipantController extends Controller
{
    use AuthorizesGranularPermissions;
    use ResolvesProjectPeriodContext;

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
            'period_id' => 'nullable|exists:periods,id',
            'status' => 'nullable|string|max:50',
            'graduation_status' => 'nullable|string|max:50',
            'search' => 'nullable|string|max:255',
        ]);
        $coordinator = $request->user();
        $context = $this->resolveProjectPeriodContextForAnyPermission(
            $request,
            $viewPermissions,
            ! empty($validated['project_id']) ? (int) $validated['project_id'] : null,
            ! empty($validated['period_id']) ? (int) $validated['period_id'] : null,
        );
        $manageableProjectIds = $context->projectIdsForQuery();
        $canViewParticipants = $this->permissionResolver->hasPermission($coordinator, 'projects.participants.view');
        $canViewAlumni = $this->permissionResolver->hasPermission($coordinator, 'projects.alumni.view');
        $canViewCv = $this->permissionResolver->hasPermission($coordinator, 'projects.student_cv.view');
        $participantViewProjectIds = $canViewParticipants
            ? $this->permissionResolver->projectIdsForPermission($coordinator, 'projects.participants.view')
            : [];
        $alumniViewProjectIds = $canViewAlumni
            ? $this->permissionResolver->projectIdsForPermission($coordinator, 'projects.alumni.view')
            : [];
        $cvViewProjectIds = $canViewCv
            ? $this->permissionResolver->projectIdsForPermission($coordinator, 'projects.student_cv.view')
            : [];
        $canManageParticipants = $this->permissionResolver->hasPermission($coordinator, 'projects.participants.manage');
        $participantManageProjectIds = $canManageParticipants
            ? $this->permissionResolver->projectIdsForPermission($coordinator, 'projects.participants.manage')
            : [];

        $query = Participant::with([
            'project:id,name',
            'period:id,name',
            'user:id,name,surname,email,phone,university,department,class_year,hometown,profile_photo_path,status',
            'user.profile:id,user_id,linkedin_url,github_url',
            'creditLogs' => fn ($query) => $query->with('creator:id,name,surname')->latest()->limit(5),
        ]);
        $this->applyProjectPeriodContext($query, $context);

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
            'participants' => $participants->map(function ($participant) use ($canViewCv, $canViewParticipants, $canViewAlumni, $canManageParticipants, $participantViewProjectIds, $alumniViewProjectIds, $cvViewProjectIds, $participantManageProjectIds) {
                $user = $participant->user;
                $profileAllowed = (
                    $canViewParticipants
                    && in_array((int) $participant->project_id, $participantViewProjectIds, true)
                ) || (
                    $canViewAlumni
                    && in_array((int) $participant->project_id, $alumniViewProjectIds, true)
                    && (! is_null($participant->graduated_at) || $participant->graduation_status === 'graduated')
                );
                $cvAllowed = $canViewCv
                    && in_array((int) $participant->project_id, $cvViewProjectIds, true);
                $creditManageAllowed = $canManageParticipants
                    && in_array((int) $participant->project_id, $participantManageProjectIds, true);

                return [
                    'id' => $participant->id,
                    'status' => $participant->status,
                    'graduation_status' => $participant->graduation_status,
                    'graduation_note' => $participant->graduation_note,
                    'credit' => $participant->credit,
                    'credit_logs' => $creditManageAllowed ? $participant->creditLogs->map(fn (CreditLog $log) => [
                        'id' => $log->id,
                        'amount' => (int) $log->amount,
                        'type' => $log->type,
                        'reason' => $log->reason,
                        'created_at' => optional($log->created_at)?->toIso8601String(),
                        'created_by' => $log->creator ? trim($log->creator->name.' '.$log->creator->surname) : null,
                    ])->values() : [],
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
                        'public_profile_visible' => (bool) ($user?->public_profile_visible ?? false),
                        'public_photo_visible' => (bool) ($user?->public_photo_visible ?? false),
                        'public_alumni_visible' => (bool) ($user?->public_alumni_visible ?? false),
                        'cv' => $cvAllowed ? [
                            'has_digital_cv' => (bool) $user?->profile,
                            'linkedin_url' => $user?->profile?->linkedin_url,
                            'github_url' => $user?->profile?->github_url,
                        ] : null,
                    ],
                ];
            })->values(),
        ]);
    }

    public function updatePublicVisibility(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'projects.participants.manage');

        $participant = Participant::with(['user:id,public_profile_visible,public_photo_visible,public_alumni_visible', 'project:id,name'])
            ->findOrFail($id);

        $this->abortUnlessProjectAllowed($request, 'projects.participants.manage', (int) $participant->project_id);

        $validated = $request->validate([
            'public_profile_visible' => 'sometimes|boolean',
            'public_photo_visible' => 'sometimes|boolean',
            'public_alumni_visible' => 'sometimes|boolean',
        ]);

        $publicProfileVisible = array_key_exists('public_profile_visible', $validated)
            ? (bool) $validated['public_profile_visible']
            : (bool) ($participant->user?->public_profile_visible ?? false);

        $updates = [
            'public_profile_visible' => $publicProfileVisible,
            'public_photo_visible' => $publicProfileVisible && (bool) ($validated['public_photo_visible'] ?? $participant->user?->public_photo_visible ?? false),
            'public_alumni_visible' => (bool) ($validated['public_alumni_visible'] ?? $participant->user?->public_alumni_visible ?? false),
        ];

        $participant->user?->update($updates);

        $request->attributes->set('audit.event', 'participants.public_visibility.updated');
        $request->attributes->set('audit.description', 'participants.public_visibility.updated');
        $request->attributes->set('audit.properties', [
            'participant_id' => $participant->id,
            'project_id' => $participant->project_id,
            'public_visibility' => $updates,
        ]);

        return response()->json([
            'message' => 'Public gorunurluk ayarlari guncellendi.',
            'participant' => [
                'id' => $participant->id,
                'user' => $participant->user?->fresh([
                    'profile:id,user_id,linkedin_url,github_url',
                ])?->only([
                    'id',
                    'public_profile_visible',
                    'public_photo_visible',
                    'public_alumni_visible',
                ]),
            ],
        ]);
    }

    public function cv(Request $request, int $id): JsonResponse
    {
        $participant = $this->participantForCv($request, $id);

        return response()->json([
            'participant' => [
                'id' => $participant->id,
                'project' => [
                    'id' => $participant->project?->id,
                    'name' => $participant->project?->name,
                ],
                'user' => [
                    'id' => $participant->user?->id,
                    'name' => $participant->user?->name,
                    'surname' => $participant->user?->surname,
                    'cv' => [
                        'digital_cv_data' => $participant->user?->profile?->digital_cv_data,
                        'linkedin_url' => $participant->user?->profile?->linkedin_url,
                        'github_url' => $participant->user?->profile?->github_url,
                    ],
                ],
            ],
        ]);
    }

    public function cvPdf(Request $request, int $id)
    {
        $participant = $this->participantForCv($request, $id);
        $payload = $this->digitalCvPdfPayload($participant);

        abort_if($payload['form'] === [], 404, 'Kayitli CV verisi bulunamadi.');

        $fullName = trim((string) ($payload['form']['fullName'] ?? 'KADEME Dijital CV')) ?: 'KADEME Dijital CV';
        $fileName = str($fullName)->lower()->replaceMatches('/[^a-z0-9]+/i', '-')->trim('-')->value() ?: 'kademe-dijital-cv';

        $request->attributes->set('audit.subject', $participant);
        $request->attributes->set('audit.event', 'participants.cv.downloaded');
        $request->attributes->set('audit.description', 'participants.cv.downloaded');
        $request->attributes->set('audit.properties', [
            'participant_id' => $participant->id,
            'project_id' => $participant->project_id,
            'user_id' => $participant->user_id,
        ]);

        return Pdf::loadView('pdf.digital-cv', [
            'form' => $payload['form'],
            'approved' => $payload['approved'],
            'projects' => $payload['projects'],
            'badges' => $payload['badges'],
            'certificates' => $payload['certificates'],
            'creditHistory' => $payload['credit_history'],
            'generatedAt' => now()->format('d.m.Y H:i'),
        ])->setPaper('a4')->download($fileName.'-kademe-cv.pdf');
    }

    private function participantForCv(Request $request, int $id): Participant
    {
        $this->abortUnlessAllowed($request, 'projects.student_cv.view');

        $participant = Participant::with([
            'project:id,name,slug,type,short_description,description',
            'period:id,name',
            'user:id,name,surname,email,phone,university,department,class_year,hometown',
            'user.profile:id,user_id,digital_cv_data,linkedin_url,github_url,instagram_url,motivation_message',
        ])->findOrFail($id);

        $this->abortUnlessProjectAllowed($request, 'projects.student_cv.view', (int) $participant->project_id);

        return $participant;
    }

    private function digitalCvPdfPayload(Participant $participant): array
    {
        $user = $participant->user;
        $profile = $user?->profile;
        $savedData = is_array($profile?->digital_cv_data) ? $profile->digital_cv_data : [];
        $form = $this->normalizeDigitalCvForm($participant, $savedData);

        if ($form === []) {
            return [
                'form' => [],
                'approved' => [],
                'projects' => [],
                'badges' => [],
                'certificates' => [],
                'credit_history' => [],
            ];
        }

        $participations = Participant::query()
            ->where('user_id', $participant->user_id)
            ->with(['project:id,name,slug,type,short_description,description', 'period:id,name'])
            ->orderByDesc('graduated_at')
            ->orderByDesc('created_at')
            ->get();

        $certificates = Certificate::query()
            ->where('user_id', $participant->user_id)
            ->with(['project:id,name,slug', 'period:id,name'])
            ->orderByDesc('issued_at')
            ->get();

        $badges = $user ? $user->badges()->with('project:id,name,slug')->orderByDesc('user_badges.awarded_at')->get() : collect();

        $creditHistory = CreditLog::query()
            ->where('user_id', $participant->user_id)
            ->with(['project:id,name,slug', 'program:id,title'])
            ->orderByDesc('created_at')
            ->take(25)
            ->get();

        return [
            'form' => $form,
            'approved' => [
                'title' => 'KADEME Onayli Dijital CV',
                'generated_at' => now()->toIso8601String(),
                'total_credit' => (int) $participations->sum('credit'),
                'completed_project_count' => $participations
                    ->filter(fn (Participant $item) => in_array($item->graduation_status, ['completed', 'graduated'], true) || $item->graduated_at !== null)
                    ->count(),
                'badge_count' => $badges->count(),
                'certificate_count' => $certificates->count(),
            ],
            'projects' => $participations
                ->filter(fn (Participant $item) => $item->project !== null)
                ->map(fn (Participant $item) => [
                    'id' => $item->project->id,
                    'name' => $item->project->name,
                    'type' => $item->project->type,
                    'description' => $item->project->short_description ?: $item->project->description,
                    'period' => $item->period?->name,
                    'status' => $item->status,
                    'graduation_status' => $item->graduation_status,
                    'credit' => (int) $item->credit,
                    'enrolled_at' => optional($item->enrolled_at)?->toIso8601String(),
                    'graduated_at' => optional($item->graduated_at)?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'badges' => $badges->map(fn (Badge $badge) => [
                'id' => $badge->id,
                'name' => $badge->name,
                'description' => $badge->description,
                'tier' => $badge->tier,
                'title_label' => $badge->title_label,
                'project' => $badge->project?->name,
                'awarded_at' => optional($badge->pivot?->awarded_at)?->toIso8601String(),
            ])->values()->all(),
            'certificates' => $certificates->map(fn (Certificate $certificate) => [
                'id' => $certificate->id,
                'type' => $certificate->type,
                'project' => $certificate->project?->name,
                'period' => $certificate->period?->name,
                'verification_code' => $certificate->verification_code,
                'issued_at' => optional($certificate->issued_at)?->toIso8601String(),
            ])->values()->all(),
            'credit_history' => $creditHistory->map(fn (CreditLog $log) => [
                'amount' => (int) $log->amount,
                'type' => $log->type,
                'reason' => $log->reason,
                'project' => $log->project?->name,
                'program' => $log->program?->title,
                'created_at' => optional($log->created_at)?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    private function normalizeDigitalCvForm(Participant $participant, array $savedData): array
    {
        if ($savedData === []) {
            return [];
        }

        $form = is_array($savedData['form'] ?? null) ? $savedData['form'] : $savedData;
        $user = $participant->user;
        $profile = $user?->profile;

        $normalized = array_merge([
            'fullName' => trim(($user?->name ?? '').' '.($user?->surname ?? '')),
            'email' => $user?->email,
            'phone' => $user?->phone,
            'location' => $user?->hometown,
            'university' => $user?->university,
            'department' => $user?->department,
            'classYear' => $user?->class_year,
            'summary' => $profile?->motivation_message,
            'linkedin' => $profile?->linkedin_url,
            'github' => $profile?->github_url,
            'instagram' => $profile?->instagram_url,
            'skills' => null,
            'languages' => null,
            'experience' => [],
            'education' => [],
            'projects' => [],
            'certificates' => [],
        ], $form);

        foreach (['experience', 'education', 'projects', 'certificates'] as $key) {
            $normalized[$key] = is_array($normalized[$key] ?? null) ? $normalized[$key] : [];
        }

        return collect($normalized)
            ->filter(function ($value) {
                if (is_array($value)) {
                    return $value !== [];
                }

                return $value !== null && $value !== '';
            })
            ->all();
    }
    public function export(Request $request)
    {
        $this->abortUnlessAllowed($request, 'projects.participants.view');
        $validated = $request->validate([
            'project_id' => 'nullable|exists:projects,id',
            'period_id' => 'nullable|exists:periods,id',
            'status' => 'nullable|string|max:50',
            'graduation_status' => 'nullable|string|max:50',
            'search' => 'nullable|string|max:255',
            'format' => 'nullable|string|max:20',
        ]);
        $coordinator = $request->user();
        $context = $this->resolveProjectPeriodContext(
            $request,
            'projects.participants.view',
            ! empty($validated['project_id']) ? (int) $validated['project_id'] : null,
            ! empty($validated['period_id']) ? (int) $validated['period_id'] : null,
        );

        $query = Participant::with([
            'project:id,name',
            'period:id,name',
            'user:id,name,surname,email,phone,university,department,class_year,hometown',
        ]);
        $this->applyProjectPeriodContext($query, $context);

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
                    'period_id' => $participant->period_id,
                    'type' => $certificateType,
                ],
                [
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

        $certificate = $certificate->fresh(['user:id,name,surname', 'project:id,name', 'period:id,name']);

        if (empty($certificate->certificate_path) || ! MediaStorage::exists($certificate->certificate_path)) {
            $certificate = CertificatePdfGenerator::generate($certificate);
        }

        return $certificate;
    }

    public function updateGraduationStatus(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'projects.participants.manage');
        $participant = Participant::with(['user', 'project:id,name', 'period:id,name'])->findOrFail($id);
        $this->abortUnlessProjectAllowed($request, 'projects.participants.manage', (int) $participant->project_id);
        $this->assertPeriodWritable($request, $participant->period_id);
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
                $this->assertPeriodWritable($request, $participant->period_id);
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

