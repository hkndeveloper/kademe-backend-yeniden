<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Concerns\ResolvesProjectPeriodContext;
use App\Http\Controllers\Controller;
use App\Models\ApplicationWindow;
use App\Models\Period;
use App\Models\Project;
use App\Services\ApplicationIntakeService;
use App\Services\PeriodLifecycleService;
use App\Services\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * @group Application Intake Management
 */
class ApplicationIntakeController extends Controller
{
    use AuthorizesGranularPermissions;
    use ResolvesProjectPeriodContext;

    public function __construct(
        private readonly PermissionResolver $permissionResolver,
        private readonly ApplicationIntakeService $intakeService,
    ) {}

    public function show(Request $request, int $projectId): JsonResponse
    {
        $project = Project::query()
            ->with([
                'periods' => fn ($query) => $query->orderByDesc('start_date'),
                'currentPeriod',
                'applicationWindows.period:id,name,status,start_date,end_date',
                'applicationWindows.opener:id,name,surname',
                'applicationWindows.closer:id,name,surname',
                'applicationWindows.updater:id,name,surname',
            ])
            ->findOrFail($projectId);

        $canView = $this->permissionResolver->canAccessProject(
            $request->user(),
            'applications.intake.view',
            $projectId
        );
        $canManage = $this->permissionResolver->canAccessProject(
            $request->user(),
            'applications.intake.manage',
            $projectId
        );
        abort_unless($canView || $canManage, 403, 'Bu projenin basvuru ayarlarini goruntuleme yetkiniz yok.');

        $validated = $request->validate([
            'period_id' => 'nullable|integer|exists:periods,id',
        ]);
        $selectedPeriod = ! empty($validated['period_id'])
            ? $project->periods->firstWhere('id', (int) $validated['period_id'])
            : ($project->currentPeriodOrLegacy() ?? $project->periods->first());

        if (! empty($validated['period_id']) && ! $selectedPeriod) {
            throw ValidationException::withMessages([
                'period_id' => ['Secilen donem bu projeye ait degil.'],
            ]);
        }

        $window = $selectedPeriod
            ? $project->applicationWindows->firstWhere('period_id', $selectedPeriod->id)
            : null;

        $request->attributes->set('audit.permission_checked', $canView ? 'applications.intake.view' : 'applications.intake.manage');
        $request->attributes->set('audit.permission_scope', [
            'scope_type' => 'project_period',
            'project_id' => $project->id,
            'period_id' => $selectedPeriod?->id,
        ]);
        $request->attributes->set('audit.subject', $window ?? $project);

        return response()->json([
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'status' => $project->status,
            ],
            'periods' => $project->periods->map(fn (Period $period) => [
                'id' => $period->id,
                'name' => $period->name,
                'status' => $period->status,
                'start_date' => optional($period->start_date)?->toDateString(),
                'end_date' => optional($period->end_date)?->toDateString(),
                'lifecycle' => [
                    'is_archive_mode' => PeriodLifecycleService::isArchiveStatus($period->status),
                    'write_capabilities' => PeriodLifecycleService::writeCapabilitiesForStatus($period->status),
                ],
            ])->values(),
            'selected_period' => $selectedPeriod ? [
                'id' => $selectedPeriod->id,
                'name' => $selectedPeriod->name,
                'status' => $selectedPeriod->status,
                'start_date' => optional($selectedPeriod->start_date)?->toDateString(),
                'end_date' => optional($selectedPeriod->end_date)?->toDateString(),
                'lifecycle' => [
                    'is_archive_mode' => PeriodLifecycleService::isArchiveStatus($selectedPeriod->status),
                    'write_capabilities' => PeriodLifecycleService::writeCapabilitiesForStatus($selectedPeriod->status),
                ],
            ] : null,
            'settings' => $this->serializeWindow($project, $selectedPeriod, $window),
            'history' => $project->applicationWindows
                ->sortByDesc(fn (ApplicationWindow $item) => $item->status_changed_at ?? $item->updated_at)
                ->map(fn (ApplicationWindow $item) => $this->serializeWindow($project, $item->period, $item))
                ->values(),
            'access' => [
                'view' => $canView || $canManage,
                'manage' => $canManage,
            ],
        ]);
    }

    public function update(Request $request, int $projectId): JsonResponse
    {
        $project = Project::query()->findOrFail($projectId);
        $this->abortUnlessProjectAllowed($request, 'applications.intake.manage', $projectId);

        $validated = $request->validate([
            'period_id' => 'required|integer|exists:periods,id',
            'is_open' => 'required|boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'next_application_date' => 'nullable|date',
            'has_interview' => 'required|boolean',
            'quota' => 'nullable|integer|min:1',
            'change_note' => 'nullable|string|max:1000',
        ]);

        $period = Period::query()
            ->where('project_id', $projectId)
            ->find($validated['period_id']);
        if (! $period) {
            throw ValidationException::withMessages([
                'period_id' => ['Secilen donem bu projeye ait degil.'],
            ]);
        }

        $this->assertPeriodWritable($request, $period->id);

        if ($validated['is_open'] && $project->status !== 'active') {
            throw ValidationException::withMessages([
                'is_open' => ['Pasif veya arsivlenmis bir projede basvuru acilamaz.'],
            ]);
        }
        if ($validated['is_open'] && $period->status !== 'active') {
            throw ValidationException::withMessages([
                'is_open' => ['Basvuru yalnizca projenin aktif donemi icin acilabilir.'],
            ]);
        }
        if (! $validated['is_open'] && $period->status === 'active' && empty($validated['next_application_date'])) {
            throw ValidationException::withMessages([
                'next_application_date' => ['Aktif donemde basvurular kapaliysa sonraki basvuru tarihi zorunludur.'],
            ]);
        }

        $actorId = (int) $request->user()->id;
        [$window, $before, $transition] = DB::transaction(function () use ($project, $period, $validated, $actorId) {
            // Serialize every intake change for this project. This prevents two
            // coordinators from opening different period windows concurrently.
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
            $lockedPeriod = Period::query()
                ->where('project_id', $lockedProject->id)
                ->lockForUpdate()
                ->findOrFail($period->id);
            if ((bool) $validated['is_open'] && ($lockedProject->status !== 'active' || $lockedPeriod->status !== 'active')) {
                throw ValidationException::withMessages([
                    'is_open' => ['Basvuru yalnizca aktif proje ve aktif donem icin acilabilir.'],
                ]);
            }
            $window = ApplicationWindow::query()
                ->where('project_id', $lockedProject->id)
                ->where('period_id', $period->id)
                ->lockForUpdate()
                ->first();

            $before = $window ? $this->auditableValues($window) : null;
            $wasOpen = (bool) ($window?->is_open ?? false);
            $isOpen = (bool) $validated['is_open'];
            $transition = $wasOpen === $isOpen ? 'settings_updated' : ($isOpen ? 'opened' : 'closed');
            $now = now();

            $window ??= new ApplicationWindow([
                'project_id' => $lockedProject->id,
                'period_id' => $period->id,
            ]);
            $window->fill([
                'is_open' => $isOpen,
                'starts_at' => $validated['starts_at'] ?? null,
                'ends_at' => $validated['ends_at'] ?? null,
                'next_application_date' => $validated['next_application_date'] ?? null,
                'has_interview' => (bool) $validated['has_interview'],
                'quota' => $validated['quota'] ?? null,
                'change_note' => $validated['change_note'] ?? null,
                'updated_by' => $actorId,
            ]);

            if ($wasOpen !== $isOpen || ! $window->exists) {
                $window->status_changed_at = $now;
                if ($isOpen) {
                    $window->opened_by = $actorId;
                    $window->opened_at = $now;
                } else {
                    $window->closed_by = $actorId;
                    $window->closed_at = $now;
                }
            }
            $window->save();

            if ($isOpen) {
                ApplicationWindow::query()
                    ->where('project_id', $lockedProject->id)
                    ->whereKeyNot($window->id)
                    ->where('is_open', true)
                    ->update([
                        'is_open' => false,
                        'closed_by' => $actorId,
                        'closed_at' => $now,
                        'updated_by' => $actorId,
                        'status_changed_at' => $now,
                        'updated_at' => $now,
                    ]);
            }

            if ($lockedPeriod->status === 'active') {
                $lockedProject->update([
                    'application_open' => $isOpen,
                    'application_start_at' => $validated['starts_at'] ?? null,
                    'application_end_at' => $validated['ends_at'] ?? null,
                    'next_application_date' => $validated['next_application_date'] ?? null,
                    'has_interview' => (bool) $validated['has_interview'],
                    'quota' => $validated['quota'] ?? null,
                ]);
            }

            return [$window->fresh(['period', 'opener:id,name,surname', 'closer:id,name,surname', 'updater:id,name,surname']), $before, $transition];
        });

        $after = $this->auditableValues($window);
        $request->attributes->set('audit.subject', $window);
        $request->attributes->set('audit.event', 'project_applications.'.$transition);
        $request->attributes->set('audit.description', match ($transition) {
            'opened' => 'Proje basvurulari acildi.',
            'closed' => 'Proje basvurulari kapatildi.',
            default => 'Proje basvuru ayarlari guncellendi.',
        });
        $request->attributes->set('audit.attribute_changes', [
            'old' => $before,
            'attributes' => $after,
        ]);
        $request->attributes->set('audit.properties', [
            'project_id' => $project->id,
            'period_id' => $period->id,
            'transition' => $transition,
            'change_note' => $validated['change_note'] ?? null,
        ]);

        return response()->json([
            'message' => match ($transition) {
                'opened' => 'Basvurular basariyla acildi.',
                'closed' => 'Basvurular basariyla kapatildi.',
                default => 'Basvuru ayarlari guncellendi.',
            },
            'settings' => $this->serializeWindow($project->fresh(), $period, $window),
        ]);
    }

    private function serializeWindow(Project $project, ?Period $period, ?ApplicationWindow $window): ?array
    {
        if (! $period) {
            return null;
        }

        $isOpen = (bool) ($window?->is_open ?? ($period->status === 'active' && $project->application_open));
        $startsAt = $window?->starts_at ?? $project->application_start_at;
        $endsAt = $window?->ends_at ?? $project->application_end_at;

        return [
            'id' => $window?->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'period_name' => $period->name,
            'period_status' => $period->status,
            'is_open' => $isOpen,
            'is_effectively_open' => $this->intakeService->isOpen($project, $period, $window),
            'effective_status' => $this->intakeService->status($project, $period, $window),
            'starts_at' => optional($startsAt)?->toISOString(),
            'ends_at' => optional($endsAt)?->toISOString(),
            'next_application_date' => optional($window?->next_application_date ?? $project->next_application_date)?->toDateString(),
            'has_interview' => $this->intakeService->hasInterview($project, $window),
            'quota' => $this->intakeService->quota($project, $window),
            'change_note' => $window?->change_note,
            'opened_at' => optional($window?->opened_at)?->toISOString(),
            'closed_at' => optional($window?->closed_at)?->toISOString(),
            'status_changed_at' => optional($window?->status_changed_at)?->toISOString(),
            'updated_at' => optional($window?->updated_at)?->toISOString(),
            'opened_by' => $this->actorPayload($window?->opener),
            'closed_by' => $this->actorPayload($window?->closer),
            'updated_by' => $this->actorPayload($window?->updater),
            'source' => $window ? 'period_window' : 'legacy_project',
        ];
    }

    private function actorPayload($user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => trim($user->name.' '.$user->surname),
        ];
    }

    private function auditableValues(ApplicationWindow $window): array
    {
        return [
            'is_open' => (bool) $window->is_open,
            'starts_at' => optional($window->starts_at)?->toISOString(),
            'ends_at' => optional($window->ends_at)?->toISOString(),
            'next_application_date' => optional($window->next_application_date)?->toDateString(),
            'has_interview' => (bool) $window->has_interview,
            'quota' => $window->quota === null ? null : (int) $window->quota,
        ];
    }
}
