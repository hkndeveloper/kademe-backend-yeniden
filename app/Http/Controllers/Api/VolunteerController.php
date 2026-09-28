<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Concerns\ResolvesProjectPeriodContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\VolunteerOpportunityResource;
use App\Models\VolunteerApplication;
use App\Models\VolunteerOpportunity;
use App\Services\NotificationService;
use App\Services\ApplicationConsentService;
use App\Services\PermissionResolver;
use App\Support\AdminExportResponder;
use App\Support\ApplicationMailLinks;
use App\Support\IstanbulDateTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * @group Volunteer
 */
class VolunteerController extends Controller
{
    use AuthorizesGranularPermissions;
    use ResolvesProjectPeriodContext;

    public function __construct(
        private readonly PermissionResolver $permissionResolver,
        private readonly NotificationService $notificationService,
        private readonly ApplicationConsentService $applicationConsentService,
    ) {
    }

    private function volunteerStatusLabel(string $status): string
    {
        return match ($status) {
            'accepted' => 'Kabul edildi',
            'waitlisted' => 'Yedek liste',
            'rejected' => 'Reddedildi',
            default => 'Değerlendirme bekliyor',
        };
    }

    private function deliverApplicationEmail(VolunteerApplication $application, string $type, ?int $senderId): string
    {
        $application->load(['user:id,email,role', 'opportunity:id,title,project_id,period_id', 'opportunity.project:id,name', 'opportunity.period:id,name']);
        $isReceipt = $type === 'receipt';
        $subject = $isReceipt ? 'Gönüllü başvurunuz alındı' : 'Gönüllü başvuru durumunuz güncellendi';
        $status = $isReceipt ? 'Değerlendirme bekliyor' : $this->volunteerStatusLabel((string) $application->status);
        $intro = $isReceipt
            ? 'Gönüllü başvurunuz alındı. Değerlendirme sonucunda bilgilendirileceksiniz.'
            : 'Gönüllü başvurunuzun durumu güncellendi.';
        $applicationUrl = ApplicationMailLinks::portal($application->user, 'volunteer');
        $lines = [
            ['label' => 'Proje', 'value' => $application->opportunity?->project?->name ?? '-'],
            ['label' => 'Dönem', 'value' => $application->opportunity?->period?->name ?? '-'],
            ['label' => 'Gönüllülük ilanı', 'value' => $application->opportunity?->title ?? '-'],
            ['label' => 'Durum', 'value' => $status],
        ];
        $plainText = $intro."\n".implode("\n", array_map(
            fn (array $line) => $line['label'].': '.$line['value'],
            $lines
        )).($applicationUrl ? "\nGönüllü başvurularım: {$applicationUrl}" : '');

        $deliveryStatus = 'failed';
        try {
            if ($application->user?->email) {
                $deliveryStatus = $this->notificationService->sendTemplatedEmail(
                    [$application->user->email],
                    $subject,
                    'emails.application-status',
                    [
                        'title' => $subject,
                        'preheader' => $subject,
                        'intro' => $intro,
                        'lines' => $lines,
                        'action_url' => $applicationUrl,
                        'action_text' => $applicationUrl ? 'Gönüllü başvurularımı görüntüle' : null,
                        'plain_text' => $plainText,
                    ],
                    $application->opportunity?->project_id,
                    $senderId,
                ) > 0 ? 'sent' : 'failed';
            }
        } catch (\Throwable $exception) {
            $deliveryStatus = 'unknown';
            Log::warning('volunteer.application_email_delivery_unknown', [
                'application_id' => $application->id,
                'type' => $type,
                'error_type' => $exception::class,
            ]);
        }

        try {
            $query = VolunteerApplication::query()->whereKey($application->id);
            if ($isReceipt) {
                $updated = $query->where('receipt_email_status', 'pending')
                    ->update(['receipt_email_status' => $deliveryStatus]);
            } else {
                $updated = $query->where('decision_email_status', 'pending')
                    ->where('decision_email_key', $application->decision_email_key)
                    ->update(['decision_email_status' => $deliveryStatus]);
            }
            if ($updated !== 1) {
                return 'unknown';
            }
        } catch (\Throwable $exception) {
            Log::warning('volunteer.application_email_tracking_failed', [
                'application_id' => $application->id,
                'type' => $type,
                'error_type' => $exception::class,
            ]);

            return 'unknown';
        }

        return $deliveryStatus;
    }

    /**
     * List volunteer opportunities and my applications.
     *
     * Requires permission: `participant.volunteer.apply`. Returns open volunteer opportunities and the authenticated user previous applications.
     *
     * @group Volunteer
     * @authenticated
     *
     * @response 200 {"opportunities":[{"id":1,"title":"Etkinlik Gonullusu","status":"open"}],"my_applications":[{"id":1,"status":"pending","opportunity":{"id":1,"title":"Etkinlik Gonullusu"}}]}
     * @response 401 {"message":"Unauthenticated."}
     * @response 403 {"message":"This action is unauthorized."}
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $opportunities = VolunteerOpportunity::query()
            ->with([
                'project:id,name,slug,type',
                'period:id,name,status',
                'applications' => fn ($query) => $query
                    ->where('user_id', $user->id)
                    ->select([
                        'id',
                        'volunteer_opportunity_id',
                        'status',
                        'motivation_text',
                        'notes',
                        'evaluation_note',
                        'consent_text_snapshot',
                        'consent_accepted_at',
                        'receipt_email_status',
                        'created_at',
                    ]),
            ])
            ->where('status', 'open')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $myApplications = VolunteerApplication::query()
            ->with(['opportunity.project:id,name,slug,type'])
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(function (VolunteerApplication $application) {
                return [
                    'id' => $application->id,
                    'status' => $application->status,
                    'motivation_text' => $application->motivation_text,
                    'notes' => $application->notes,
                    'evaluation_note' => $application->evaluation_note,
                    'consent_text_snapshot' => $application->consent_text_snapshot,
                    'consent_accepted_at' => optional($application->consent_accepted_at)?->toIso8601String(),
                    'receipt_email_status' => $application->receipt_email_status,
                    'created_at' => optional($application->created_at)?->toIso8601String(),
                    'opportunity' => [
                        'id' => $application->opportunity?->id,
                        'title' => $application->opportunity?->title,
                        'project' => $application->opportunity?->project ? [
                            'id' => $application->opportunity->project->id,
                            'name' => $application->opportunity->project->name,
                            'slug' => $application->opportunity->project->slug,
                            'type' => $application->opportunity->project->type,
                        ] : null,
                    ],
                ];
            })
            ->values();

        return response()->json([
            'opportunities' => VolunteerOpportunityResource::collection($opportunities),
            'my_applications' => $myApplications,
        ]);
    }

    /**
     * Apply to a volunteer opportunity.
     *
     * Requires permission: `participant.volunteer.apply`. The opportunity must be open, quota must be available, and the current user must not have applied before.
     *
     * @group Volunteer
     * @authenticated
     *
     * @urlParam id integer required Volunteer opportunity id. Example: 1
     * @bodyParam motivation_text string required Motivation text, min 20 characters. Example: Bu etkinlikte gonullu olmak istiyorum.
     * @bodyParam notes string Optional additional note. Example: Hafta sonu uygunum.
     * @bodyParam accepted_terms boolean required Applicant accepts displayed application terms. Example: true
     * @bodyParam expected_consent_text string required Exact terms displayed to applicant. Example: Basvuru kosullarini okudum.
     * @response 201 {"message":"Gonullu basvurun alindi.","application":{"id":1,"status":"pending","opportunity":{"id":1,"title":"Etkinlik Gonullusu"}}}
     * @response 422 {"message":"Bu gonullu ilanina daha once basvurdun."}
     * @response 422 {"message":"Bu gonullu ilani icin kontenjan dolu."}
     */
    public function apply(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'motivation_text' => 'required|string|min:20|max:4000',
            'notes' => 'nullable|string|max:2000',
            'accepted_terms' => 'required|accepted',
            'expected_consent_text' => 'required|string|max:22000',
        ]);

        [$opportunity, $application] = DB::transaction(function () use ($request, $id, $validated) {
            $opportunity = VolunteerOpportunity::query()
                ->with('project:id,name,slug,type')
                ->where('status', 'open')
                ->lockForUpdate()
                ->findOrFail($id);
            $this->assertPeriodWritable($request, $opportunity->period_id);

            $consentText = $this->applicationConsentService->textWithAdditional($opportunity->consent_text);
            if ($validated['expected_consent_text'] !== $consentText) {
                throw ValidationException::withMessages([
                    'expected_consent_text' => 'Başvuru koşulları değişti. Güncel metni yeniden okuyup onaylayın.',
                ]);
            }

            if (VolunteerApplication::query()
                ->where('volunteer_opportunity_id', $opportunity->id)
                ->where('user_id', $request->user()->id)
                ->exists()) {
                throw ValidationException::withMessages([
                    'opportunity' => 'Bu gönüllü ilanına daha önce başvurdun.',
                ]);
            }

            $acceptedCount = VolunteerApplication::query()
                ->where('volunteer_opportunity_id', $opportunity->id)
                ->where('status', 'accepted')
                ->count();

            if ($opportunity->quota !== null && $acceptedCount >= $opportunity->quota) {
                throw ValidationException::withMessages([
                    'opportunity' => 'Bu gönüllü ilanı için kontenjan dolu.',
                ]);
            }

            $application = VolunteerApplication::create([
                'volunteer_opportunity_id' => $opportunity->id,
                'user_id' => $request->user()->id,
                'motivation_text' => $validated['motivation_text'],
                'notes' => $validated['notes'] ?? null,
                'status' => 'pending',
                'consent_text_snapshot' => $consentText,
                'consent_accepted_at' => now(),
                'receipt_email_status' => 'pending',
            ]);

            return [$opportunity, $application];
        });

        $deliveryStatus = $this->deliverApplicationEmail($application, 'receipt', $request->user()->id);

        return response()->json([
            'message' => match ($deliveryStatus) {
                'sent' => 'Gönüllü başvurun kaydedildi; bilgilendirme e-postası gönderildi.',
                'failed' => 'Gönüllü başvurun kaydedildi; bilgilendirme e-postası gönderilemedi.',
                default => 'Gönüllü başvurun kaydedildi; e-posta gönderimi kontrol edilmeli.',
            },
            'follow_up' => ['receipt_email_sent' => $deliveryStatus === 'sent', 'receipt_email_status' => $deliveryStatus],
            'application' => [
                'id' => $application->id,
                'status' => $application->status,
                'motivation_text' => $application->motivation_text,
                'notes' => $application->notes,
                'evaluation_note' => $application->evaluation_note,
                'consent_text_snapshot' => $application->consent_text_snapshot,
                'consent_accepted_at' => optional($application->consent_accepted_at)?->toIso8601String(),
                'receipt_email_status' => $deliveryStatus,
                'created_at' => optional($application->created_at)?->toIso8601String(),
                'opportunity' => [
                    'id' => $opportunity->id,
                    'title' => $opportunity->title,
                    'project' => $opportunity->project ? [
                        'id' => $opportunity->project->id,
                        'name' => $opportunity->project->name,
                        'slug' => $opportunity->project->slug,
                        'type' => $opportunity->project->type,
                    ] : null,
                ],
            ],
        ], 201);
    }

    public function panelIndex(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => 'nullable|exists:projects,id',
            'period_id' => 'nullable|exists:periods,id',
            'status' => 'nullable|string|max:50',
        ]);

        $context = $this->resolveProjectPeriodContext(
            $request,
            'volunteer.view',
            ! empty($validated['project_id']) ? (int) $validated['project_id'] : null,
            ! empty($validated['period_id']) ? (int) $validated['period_id'] : null,
        );
        $query = VolunteerOpportunity::query()
            ->with([
                'project:id,name',
                'period:id,name,status',
                'creator:id,name,surname',
                'applications.user:id,name,surname,email,phone',
            ])
            ->withCount('applications')
            ->orderByDesc('created_at');
        $this->applyProjectPeriodContext($query, $context, includeNullPeriodRows: true);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        return response()->json([
            'opportunities' => $query->paginate(20),
        ]);
    }

    public function panelStore(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'volunteer.manage');
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'period_id' => 'nullable|exists:periods,id',
            'title' => 'required|string|max:255',
            'description' => 'required|string|max:4000',
            'consent_text' => 'nullable|string|max:20000',
            'location' => 'nullable|string|max:255',
            'start_at' => 'nullable|date',
            'end_at' => 'nullable|date|after_or_equal:start_at',
            'quota' => 'nullable|integer|min:1',
            'status' => 'required|in:open,closed,archived',
        ]);
        $validated = IstanbulDateTime::normalizeFields($validated, ['start_at', 'end_at']);

        $this->abortUnlessProjectAllowed($request, 'volunteer.manage', (int) $validated['project_id']);

        if (! empty($validated['period_id'])) {
            abort_unless(
                \App\Models\Period::query()
                    ->whereKey((int) $validated['period_id'])
                    ->where('project_id', (int) $validated['project_id'])
                    ->exists(),
                422,
                'Secilen donem bu projeye ait degil.'
            );
            $this->assertPeriodWritable($request, (int) $validated['period_id']);
        }

        $opportunity = VolunteerOpportunity::query()->create([
            ...$validated,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Gonullu ilani olusturuldu.',
            'opportunity' => $opportunity->load(['project:id,name', 'creator:id,name,surname']),
        ], 201);
    }


    public function panelUpdate(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'volunteer.manage');

        $opportunity = VolunteerOpportunity::query()->findOrFail($id);
        $this->abortUnlessProjectAllowed($request, 'volunteer.manage', (int) $opportunity->project_id);
        $this->assertPeriodWritable($request, $opportunity->period_id);

        $validated = $request->validate([
            'project_id' => 'sometimes|required|exists:projects,id',
            'period_id' => 'nullable|exists:periods,id',
            'title' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|required|string|max:4000',
            'consent_text' => 'nullable|string|max:20000',
            'location' => 'nullable|string|max:255',
            'start_at' => 'nullable|date',
            'end_at' => 'nullable|date|after_or_equal:start_at',
            'quota' => 'nullable|integer|min:1',
            'status' => 'sometimes|required|in:open,closed,archived',
        ]);
        $validated = IstanbulDateTime::normalizeFields($validated, ['start_at', 'end_at']);

        $targetProjectId = (int) ($validated['project_id'] ?? $opportunity->project_id);
        $this->abortUnlessProjectAllowed($request, 'volunteer.manage', $targetProjectId);

        if (array_key_exists('period_id', $validated) && ! empty($validated['period_id'])) {
            abort_unless(
                \App\Models\Period::query()
                    ->whereKey((int) $validated['period_id'])
                    ->where('project_id', $targetProjectId)
                    ->exists(),
                422,
                'Secilen donem bu projeye ait degil.'
            );
            $this->assertPeriodWritable($request, (int) $validated['period_id']);
        }

        $opportunity = DB::transaction(function () use ($request, $id, $validated) {
            $lockedOpportunity = VolunteerOpportunity::query()->lockForUpdate()->findOrFail($id);
            $this->abortUnlessProjectAllowed($request, 'volunteer.manage', (int) $lockedOpportunity->project_id);
            $this->assertPeriodWritable($request, $lockedOpportunity->period_id);

            $targetProjectId = (int) ($validated['project_id'] ?? $lockedOpportunity->project_id);
            $this->abortUnlessProjectAllowed($request, 'volunteer.manage', $targetProjectId);
            if (! empty($validated['period_id'])) {
                abort_unless(
                    \App\Models\Period::query()
                        ->whereKey((int) $validated['period_id'])
                        ->where('project_id', $targetProjectId)
                        ->exists(),
                    422,
                    'Secilen donem bu projeye ait degil.'
                );
                $this->assertPeriodWritable($request, (int) $validated['period_id']);
            }

            if (array_key_exists('quota', $validated) && $validated['quota'] !== null
                && (int) $validated['quota'] !== (int) $lockedOpportunity->quota) {
                $acceptedCount = VolunteerApplication::query()
                    ->where('volunteer_opportunity_id', $lockedOpportunity->id)
                    ->where('status', 'accepted')
                    ->count();
                if ($acceptedCount > (int) $validated['quota']) {
                    throw ValidationException::withMessages([
                        'quota' => 'Kontenjan, kabul edilmiş gönüllü sayısından düşük olamaz.',
                    ]);
                }
            }

            $lockedOpportunity->update($validated);

            return $lockedOpportunity;
        });

        return response()->json([
            'message' => 'Gonullu ilani guncellendi.',
            'opportunity' => $opportunity->fresh(['project:id,name', 'period:id,name,status', 'creator:id,name,surname']),
        ]);
    }
    public function panelExport(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'nullable|exists:projects,id',
            'period_id' => 'nullable|exists:periods,id',
            'status' => 'nullable|string|max:50',
            'format' => 'nullable|string|max:20',
        ]);
        $context = $this->resolveProjectPeriodContext(
            $request,
            'volunteer.view',
            ! empty($validated['project_id']) ? (int) $validated['project_id'] : null,
            ! empty($validated['period_id']) ? (int) $validated['period_id'] : null,
        );

        $query = VolunteerOpportunity::query()
            ->with(['project:id,name', 'period:id,name,status', 'creator:id,name,surname', 'applications.user:id,name,surname,email'])
            ->withCount('applications')
            ->orderByDesc('created_at');
        $this->applyProjectPeriodContext($query, $context, includeNullPeriodRows: true);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $opportunities = $query->get();

        $headings = [
            'Ilan ID', 'Ilan Basligi', 'Proje', 'Donem', 'Durum', 'Kontenjan', 'Baslangic', 'Bitis',
            'Basvuru Sayisi', 'Olusturan', 'Olusturma Tarihi', 'Basvuranlar',
        ];
        $rows = $opportunities->map(function (VolunteerOpportunity $opportunity) {
            $applicantSummary = $opportunity->applications
                ->map(fn (VolunteerApplication $application) => trim(($application->user?->name ?? '-') . ' ' . ($application->user?->surname ?? '')) . " ({$application->status})")
                ->implode('; ');

            return [
                $opportunity->id,
                $opportunity->title,
                $opportunity->project?->name ?? '-',
                $opportunity->period?->name ?? '-',
                $opportunity->status,
                $opportunity->quota ?? '-',
                $opportunity->start_at?->format('d.m.Y H:i') ?? '-',
                $opportunity->end_at?->format('d.m.Y H:i') ?? '-',
                $opportunity->applications_count,
                $opportunity->creator ? trim($opportunity->creator->name . ' ' . $opportunity->creator->surname) : '-',
                $opportunity->created_at?->format('d.m.Y H:i') ?? '-',
                $applicantSummary !== '' ? $applicantSummary : '-',
            ];
        })->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'gonullu_ilanlari_' . now()->format('Ymd_His'),
            'Gonullu Ilanlari',
            $headings,
            $rows,
        );
    }

    public function panelUpdateApplication(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'volunteer.manage');
        $validated = $request->validate([
            'status' => 'required|in:pending,accepted,waitlisted,rejected',
            'evaluation_note' => 'nullable|string|max:3000',
        ]);

        [$application, $decisionChanged] = DB::transaction(function () use ($request, $id, $validated) {
            $opportunityId = VolunteerApplication::query()->findOrFail($id)->volunteer_opportunity_id;
            $opportunity = VolunteerOpportunity::query()->lockForUpdate()->findOrFail($opportunityId);
            $this->abortUnlessProjectAllowed($request, 'volunteer.manage', (int) $opportunity->project_id);
            $this->assertPeriodResolvable($request, $opportunity->period_id);

            $lockedApplication = VolunteerApplication::query()->lockForUpdate()->findOrFail($id);
            if ($validated['status'] === 'accepted' && $lockedApplication->status !== 'accepted'
                && $opportunity->quota !== null) {
                $acceptedCount = VolunteerApplication::query()
                    ->where('volunteer_opportunity_id', $opportunity->id)
                    ->where('status', 'accepted')
                    ->count();
                if ($acceptedCount >= (int) $opportunity->quota) {
                    throw ValidationException::withMessages([
                        'status' => 'Bu gönüllü ilanının kontenjanı dolu; yeni kabul yapılamaz.',
                    ]);
                }
            }

            $decisionChanged = $lockedApplication->status !== $validated['status'];
            $lockedApplication->update([
                ...$validated,
                ...($decisionChanged ? [
                    'decision_email_status' => 'pending',
                    'decision_email_key' => (string) Str::uuid(),
                ] : []),
            ]);

            return [$lockedApplication, $decisionChanged];
        });

        $deliveryStatus = $decisionChanged
            ? $this->deliverApplicationEmail($application, 'decision', $request->user()->id)
            : null;

        return response()->json([
            'message' => match ($deliveryStatus) {
                'sent' => 'Gönüllü başvurusu güncellendi; durum e-postası gönderildi.',
                'failed' => 'Gönüllü başvurusu güncellendi; durum e-postası gönderilemedi.',
                'unknown' => 'Gönüllü başvurusu güncellendi; e-posta gönderimi kontrol edilmeli.',
                default => 'Gönüllü başvurusu güncellendi; durum değişmediği için yeni e-posta gönderilmedi.',
            },
            'application' => $application->fresh(['user:id,name,surname,email,phone']),
            'follow_up' => [
                'decision_email_sent' => $deliveryStatus === null ? null : $deliveryStatus === 'sent',
                'decision_email_status' => $deliveryStatus,
            ],
        ]);
    }

    public function panelRetryNotification(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'volunteer.manage');
        $validated = $request->validate(['type' => 'required|in:receipt,decision']);

        $application = DB::transaction(function () use ($request, $id, $validated) {
            $opportunityId = VolunteerApplication::query()->findOrFail($id)->volunteer_opportunity_id;
            $opportunity = VolunteerOpportunity::query()->lockForUpdate()->findOrFail($opportunityId);
            $this->abortUnlessProjectAllowed($request, 'volunteer.manage', (int) $opportunity->project_id);

            $lockedApplication = VolunteerApplication::query()->lockForUpdate()->findOrFail($id);
            $isReceipt = $validated['type'] === 'receipt';
            $field = $isReceipt ? 'receipt_email_status' : 'decision_email_status';
            if ($isReceipt && $lockedApplication->status !== 'pending') {
                throw ValidationException::withMessages([
                    'type' => 'Sonuçlandırılmış başvuru için eski başvuru alındı e-postası yeniden gönderilemez.',
                ]);
            }
            if ($lockedApplication->{$field} !== 'failed'
                || (! $isReceipt && $lockedApplication->decision_email_key === null)) {
                throw ValidationException::withMessages([
                    'type' => 'Yeniden gönderilecek başarısız bir bildirim bulunmuyor.',
                ]);
            }

            $lockedApplication->update([$field => 'pending']);

            return $lockedApplication;
        });

        $deliveryStatus = $this->deliverApplicationEmail($application, $validated['type'], $request->user()->id);

        return response()->json([
            'message' => match ($deliveryStatus) {
                'sent' => 'Bildirim e-postası yeniden gönderildi; başvuru kararı değişmedi.',
                'failed' => 'Başvuru kararı değişmedi; bildirim e-postası yine gönderilemedi.',
                default => 'Başvuru kararı değişmedi; e-posta gönderimi kontrol edilmeli.',
            },
            'application' => $application->fresh(['user:id,name,surname,email,phone']),
            'sent' => $deliveryStatus === 'sent',
            'delivery_status' => $deliveryStatus,
        ]);
    }

    public function panelDestroy(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'volunteer.manage');
        $opportunity = VolunteerOpportunity::query()->findOrFail($id);
        $this->abortUnlessProjectAllowed($request, 'volunteer.manage', (int) $opportunity->project_id);
        $this->assertPeriodWritable($request, $opportunity->period_id);
        $opportunity->delete();

        return response()->json(['message' => 'Gonullu ilani silindi.']);
    }
}
