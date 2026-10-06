<?php

namespace App\Services;

use App\Enums\PeriodWriteAction;
use App\Models\Application;
use App\Models\Period;
use App\Models\Project;
use App\Models\User;
use App\Support\IstanbulDateTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WaitlistService
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly PeriodWritePolicy $periodWritePolicy,
        private readonly ApplicationCapacityService $capacityService,
    ) {}

    public function expireOverdueInvitations(Application $scope): int
    {
        $this->assertResolvable($scope);

        $overdue = $this->scopeQuery($scope)
            ->where('status', 'waitlisted')
            ->whereNotNull('waitlist_invited_at')
            ->whereNotNull('waitlist_invitation_expires_at')
            ->where('waitlist_invitation_expires_at', '<=', now())
            ->where(function ($query) {
                $query->whereNull('waitlist_invitation_delivery_status')
                    ->orWhere('waitlist_invitation_delivery_status', 'sent');
            })
            ->get(['id', 'waitlist_invited_at', 'waitlist_invitation_expires_at']);

        $expired = 0;
        foreach ($overdue as $invitation) {
            $updated = Application::query()->whereKey($invitation->id)
                ->where('status', 'waitlisted')
                ->where('waitlist_invitation_expires_at', '<=', now())
                ->where(function ($query) {
                    $query->whereNull('waitlist_invitation_delivery_status')
                        ->orWhere('waitlist_invitation_delivery_status', 'sent');
                })
                ->update(['waitlist_invitation_delivery_status' => 'expired']);
            if ($updated !== 1) {
                continue;
            }

            activity('application_waitlist')->performedOn($invitation)->withProperties([
                'invited_at' => $invitation->waitlist_invited_at?->toISOString(),
                'expires_at' => $invitation->waitlist_invitation_expires_at?->toISOString(),
            ])->log('waitlist_invitation_expired');
            $expired++;
        }

        return $expired;
    }

    public function inviteSpecific(Application $application, ?int $senderId = null, Carbon|string|null $expiresAt = null): Application
    {
        $reserved = $this->withScopeLock($application, function () use ($application, $senderId, $expiresAt) {
            $current = Application::query()->lockForUpdate()->findOrFail($application->id);
            $this->assertResolvable($current, $senderId);
            $this->expireOverdueInvitations($current);

            if (in_array($current->fresh()?->waitlist_invitation_delivery_status, ['failed', 'unknown'], true)) {
                throw new \RuntimeException('Bu davetin bildirim durumunu kontrol edin; gerekirse yalniz bildirimi yeniden gonderin.');
            }
            if ($current->usesInterview() && $current->interview_passed_at === null) {
                throw new \RuntimeException('Mülakat olumlu tamamlanmadan yedek daveti gönderilemez.');
            }
            if ($this->hasActiveInvitation($current, $current->id)) {
                throw new \RuntimeException('Ayni kapsamda aktif yedek liste daveti zaten mevcut.');
            }
            if (! $this->capacityService->hasAvailableSeat($current, forApplicant: true)) {
                throw new \RuntimeException('Kontenjan dolu oldugu icin yedek daveti gonderilemez.');
            }

            return $this->markInvited($current, $expiresAt);
        });

        return $this->deliverInvitation($reserved, $senderId);
    }

    public function inviteNextIfSeatAvailable(Application $scope, ?int $senderId = null, Carbon|string|null $expiresAt = null): ?Application
    {
        $reserved = $this->withScopeLock($scope, function () use ($scope, $senderId, $expiresAt) {
            $this->assertResolvable($scope, $senderId);
            $this->expireOverdueInvitations($scope);

            if ($this->hasActiveInvitation($scope)) {
                return null;
            }

            $candidate = $this->scopeQuery($scope)
                ->where('status', 'waitlisted')
                ->whereNull('waitlist_invited_at')
                ->orderByRaw('waitlist_order IS NULL')
                ->orderBy('waitlist_order')
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            // Do not silently skip the coordinator's first candidate.
            if (! $candidate || ($candidate->usesInterview() && $candidate->interview_passed_at === null)) {
                return null;
            }
            if (! $this->capacityService->hasAvailableSeat($candidate, forApplicant: true)) {
                return null;
            }

            return $this->markInvited($candidate, $expiresAt);
        });

        return $reserved ? $this->deliverInvitation($reserved, $senderId) : null;
    }

    public function hasAvailableSeat(Application $scope): bool
    {
        return $this->capacityService->hasAvailableSeat($scope);
    }

    public function retryFailedInvitation(Application $application, ?int $senderId = null): Application
    {
        $reserved = $this->withScopeLock($application, function () use ($application, $senderId) {
            $current = Application::query()->lockForUpdate()->findOrFail($application->id);
            $this->assertResolvable($current, $senderId);
            if ($current->usesInterview() && $current->interview_passed_at === null) {
                throw new \RuntimeException('Mülakat olumlu tamamlanmadan yedek daveti gönderilemez.');
            }
            if (! $this->capacityService->hasAvailableSeat($current, forApplicant: true)) {
                throw new \RuntimeException('Kontenjan dolu oldugu icin davet e-postasi yeniden gonderilemez.');
            }
            $updated = Application::query()
                ->whereKey($current->id)
                ->where('status', 'waitlisted')
                ->whereNotNull('waitlist_invited_at')
                ->where('waitlist_invitation_delivery_status', 'failed')
                ->update(['waitlist_invitation_delivery_status' => 'pending']);

            if ($updated !== 1) {
                throw new \RuntimeException('Yeniden gonderilecek basarisiz bir yedek daveti bulunmuyor.');
            }

            return $current->fresh();
        });

        return $this->deliverInvitation($reserved, $senderId);
    }

    private function hasActiveInvitation(Application $scope, ?int $exceptApplicationId = null): bool
    {
        return $this->scopeQuery($scope)
            ->when($exceptApplicationId, fn ($query) => $query->whereKeyNot($exceptApplicationId))
            ->where('status', 'waitlisted')
            ->whereNotNull('waitlist_invited_at')
            ->where(function ($query) {
                $query->whereIn('waitlist_invitation_delivery_status', ['pending', 'failed', 'unknown'])
                    ->orWhereNull('waitlist_invitation_expires_at')
                    ->orWhere('waitlist_invitation_expires_at', '>', now());
            })
            ->exists();
    }

    private function markInvited(Application $application, Carbon|string|null $expiresAt): Application
    {
        $previous = $application->fresh();
        $expiresAt = $expiresAt ? Carbon::parse($expiresAt) : now()->addDays(3);
        $responseSeconds = max(1, $expiresAt->getTimestamp() - now()->getTimestamp());
        $updated = Application::query()->whereKey($application->id)
            ->where('status', 'waitlisted')
            ->where(function ($query) {
                $query->whereNull('waitlist_invited_at')
                    ->orWhere('waitlist_invitation_delivery_status', 'expired');
            })
            ->update([
                'waitlist_invited_at' => now(),
                'waitlist_invitation_expires_at' => null,
                'waitlist_invitation_delivery_status' => 'pending',
                'waitlist_invitation_response_seconds' => $responseSeconds,
            ]);

        if ($updated !== 1) {
            throw new \RuntimeException('Bu basvuru icin yedek daveti zaten kaydedilmis veya durumu degismis.');
        }

        if ($previous?->waitlist_invitation_delivery_status === 'expired') {
            activity('application_waitlist')->performedOn($application)->withProperties([
                'previous_invited_at' => $previous->waitlist_invited_at?->toISOString(),
                'previous_expires_at' => $previous->waitlist_invitation_expires_at?->toISOString(),
            ])->log('waitlist_invitation_reissued');
        }

        return $application->fresh();
    }

    private function withScopeLock(Application $application, callable $operation): mixed
    {
        return DB::transaction(function () use ($application, $operation) {
            // Keep the same lock order as final acceptance and period changes.
            Project::query()->lockForUpdate()->findOrFail($application->project_id);
            if ($application->period_id !== null) {
                Period::query()->lockForUpdate()->findOrFail($application->period_id);
            }

            return $operation();
        });
    }

    private function deliverInvitation(Application $application, ?int $senderId): Application
    {
        $application = $application->fresh();
        if (! $application || $application->status !== 'waitlisted'
            || $application->waitlist_invitation_delivery_status !== 'pending') {
            throw new \RuntimeException('Davet gonderilmeden once basvuru durumu degisti. E-posta gonderilmedi.');
        }

        $responseSeconds = max(1, (int) ($application->waitlist_invitation_response_seconds ?? 3 * 86400));
        $expiresAt = now()->addSeconds($responseSeconds);

        $sent = false;
        $unknown = false;
        try {
            $application->load(['project:id,name', 'period:id,name', 'program:id,title', 'user:id,email,role']);
            if ($application->applicant()?->email) {
                $deadline = IstanbulDateTime::format($expiresAt).' (Türkiye saati)';
                $applicationUrl = app(ApplicationTrackingService::class)->issue($application);
                $plainText = 'Proje: '.($application->project?->name ?? '-')."\n"
                    .'Dönem: '.($application->period?->name ?? '-')."\n"
                    .'Program: '.($application->program?->title ?? '-')."\n"
                    .'Durum: Yedek listeden davet edildiniz.'."\n"
                    .'Son yanıt tarihi: '.$deadline
                    .($applicationUrl ? "\nBaşvurularım: {$applicationUrl}" : '');
                $sent = app(ApplicationMessageService::class)->send(
                    $application,
                    'waitlist_invited',
                    'Yedek listeden davet edildiniz',
                    [
                        'title' => 'Yedek Liste Daveti',
                        'preheader' => 'Yedek listeden davet edildiniz.',
                        'intro' => 'Kontenjan açıldığı için yedek listeden davet edildiniz. Lütfen son yanıt saatinden önce başvurularınızı kontrol edin.',
                        'lines' => [
                            ['label' => 'Proje', 'value' => $application->project?->name ?? '-'],
                            ['label' => 'Dönem', 'value' => $application->period?->name ?? '-'],
                            ['label' => 'Program', 'value' => $application->program?->title ?? '-'],
                            ['label' => 'Son yanıt tarihi', 'value' => $deadline],
                        ],
                        'action_url' => $applicationUrl,
                        'action_text' => $applicationUrl ? 'Başvurularımı görüntüle' : null,
                        'plain_text' => $plainText,
                    ],
                    $senderId
                ) > 0;
            }
        } catch (\Throwable $exception) {
            $unknown = true;
            Log::warning('application.waitlist_invitation_email_failed', [
                'application_id' => $application->id,
                'error_type' => $exception::class,
            ]);
        }

        Application::query()->whereKey($application->id)
            ->where('status', 'waitlisted')
            ->where('waitlist_invitation_delivery_status', 'pending')
            ->update([
                'waitlist_invitation_delivery_status' => $sent ? 'sent' : ($unknown ? 'unknown' : 'failed'),
                'waitlist_invited_at' => $sent ? now() : $application->waitlist_invited_at,
                'waitlist_invitation_expires_at' => $sent ? $expiresAt : null,
            ]);

        return $application->fresh(['user:id,name,surname,email', 'project:id,name', 'period:id,name', 'program:id,title']);
    }

    private function scopeQuery(Application $scope)
    {
        return Application::query()
            ->where('project_id', $scope->project_id)
            ->where('period_id', $scope->period_id)
            ->when($scope->training_id, fn ($query) => $query->where('training_id', $scope->training_id), fn ($query) => $query->whereNull('training_id'))
            ->when(
                $scope->program_id,
                fn ($query) => $query->where('program_id', $scope->program_id),
                fn ($query) => $query->whereNull('program_id')
            );
    }

    private function assertResolvable(Application $application, ?int $actorId = null): void
    {
        $application->loadMissing('period');
        if (! $application->period) {
            return;
        }

        $this->periodWritePolicy->assertAllowed(
            $actorId ? User::query()->find($actorId) : null,
            $application->period,
            PeriodWriteAction::RESOLVE_OPERATION,
        );
    }
}
