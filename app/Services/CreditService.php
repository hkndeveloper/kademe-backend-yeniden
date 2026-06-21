<?php

namespace App\Services;

use App\Models\Participant;
use App\Models\CreditLog;
use App\Models\Program;
use App\Models\SystemNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreditService
{
    public function __construct(
        private readonly NotificationService $notificationService
    ) {
    }

    /**
     * Katılımcıdan kredi (puan) düşürür
     */
    public function deduct(Participant $participant, int $amount, string $reason, ?int $programId = null, ?int $adminId = null)
    {
        return DB::transaction(function () use ($participant, $amount, $reason, $programId, $adminId) {
            $creditBefore = (int) $participant->credit;
            $log = $this->createLogAndApplyDelta(
                $participant,
                -abs($amount),
                'deduction',
                $reason,
                $programId,
                $adminId ?? $participant->user_id
            );

            $this->checkThresholdAndBlacklist($participant->fresh(['period', 'user', 'project.coordinators']), $creditBefore);

            return $log;
        });
    }

    /**
     * Krediyi artırır (Yoklamaya katılınca vs.)
     */
    public function reward(Participant $participant, int $amount, string $reason, ?int $programId = null, ?int $adminId = null)
    {
        return DB::transaction(fn () => $this->createLogAndApplyDelta(
            $participant,
            abs($amount),
            'manual_adjust',
            $reason,
            $programId,
            $adminId ?? $participant->user_id
        ));
    }

    public function deductOnceForProgram(Participant $participant, Program $program, ?int $adminId = null, ?string $reason = null): ?CreditLog
    {
        $amount = max((int) ($program->credit_deduction ?? 0), 0);
        if ($amount === 0) {
            return null;
        }

        return DB::transaction(function () use ($participant, $program, $adminId, $reason, $amount) {
            $creditBefore = (int) $participant->credit;
            $alreadyDeducted = CreditLog::query()
                ->where('participant_id', $participant->id)
                ->where('program_id', $program->id)
                ->where('type', 'deduction')
                ->exists();

            if ($alreadyDeducted) {
                return null;
            }

            $log = $this->createLogAndApplyDelta(
                $participant,
                -$amount,
                'deduction',
                $reason ?: 'Etkinlik tamamlandi, degerlendirme bekleniyor',
                $program->id,
                $adminId
            );

            $this->checkThresholdAndBlacklist($participant->fresh(['period', 'user', 'project.coordinators']), $creditBefore);

            return $log;
        });
    }

    public function restoreOnceForFeedback(Participant $participant, Program $program, ?int $adminId = null): ?CreditLog
    {
        $amount = max((int) ($program->credit_deduction ?? 0), 0);
        if ($amount === 0) {
            return null;
        }

        return DB::transaction(function () use ($participant, $program, $adminId, $amount) {
            $deductionExists = CreditLog::query()
                ->where('participant_id', $participant->id)
                ->where('program_id', $program->id)
                ->where('type', 'deduction')
                ->exists();

            $restoreExists = CreditLog::query()
                ->where('participant_id', $participant->id)
                ->where('program_id', $program->id)
                ->where('type', 'restore')
                ->exists();

            if (! $deductionExists || $restoreExists) {
                return null;
            }

            return $this->createLogAndApplyDelta(
                $participant,
                $amount,
                'restore',
                'Oturum degerlendirmesi tamamlandi',
                $program->id,
                $adminId
            );
        });
    }

    public function reconcileCompletedProgramAttendance(Program $program, Participant $participant, bool $isValid, int $adminId): ?CreditLog
    {
        if ($program->status !== 'completed') {
            return null;
        }

        $creditDeduction = max((int) ($program->credit_deduction ?? 0), 0);
        if ($creditDeduction === 0) {
            return null;
        }

        return DB::transaction(function () use ($program, $participant, $isValid, $adminId, $creditDeduction) {
            $creditBefore = (int) $participant->credit;
            $deductionExists = CreditLog::query()
                ->where('participant_id', $participant->id)
                ->where('program_id', $program->id)
                ->where('type', 'deduction')
                ->exists();

            if ($deductionExists) {
                return null;
            }

            $log = $this->createLogAndApplyDelta(
                $participant,
                -$creditDeduction,
                'deduction',
                $isValid
                    ? 'Manuel yoklama katildi olarak duzeltildi, degerlendirme bekleniyor'
                    : 'Manuel yoklama gelmedi olarak duzeltildi, kredi dusumu uygulandi',
                $program->id,
                $adminId
            );

            $this->checkThresholdAndBlacklist($participant->fresh(['period', 'user', 'project.coordinators']), $creditBefore);

            return $log;
        });
    }

    private function createLogAndApplyDelta(Participant $participant, int $delta, string $type, string $reason, ?int $programId = null, ?int $adminId = null): CreditLog
    {
        $log = CreditLog::create([
            'participant_id' => $participant->id,
            'user_id' => $participant->user_id,
            'project_id' => $participant->project_id,
            'period_id' => $participant->period_id,
            'amount' => $delta,
            'type' => $type,
            'reason' => $reason,
            'program_id' => $programId,
            'created_by' => $adminId,
        ]);

        if ($delta > 0) {
            $participant->increment('credit', $delta);
        } elseif ($delta < 0) {
            $participant->decrement('credit', abs($delta));
        }

        return $log;
    }

    /**
     * Bir devamsizligi mazaretli olarak isaretle.
     * Admin panelinden cagrilir.
     */
    public function markExcused(CreditLog $log, bool $excused = true): void
    {
        $log->update(['excused' => $excused]);
    }

    /**
     * Puan belli bir sinirin (orn: 75) altina dusunce yapilacak islemler.
     *
     * Kural 1: Kredi < threshold (75) → uyari logu + event (ileride SMS gateway tetikleyebilir)
     * Kural 2: Mazeretsiz devamsizlik >= 3 → kara liste
     * Kural 3: Kredi <= 30 → kara liste
     */
    private function checkThresholdAndBlacklist(Participant $participant, ?int $creditBefore = null): void
    {
        $threshold = $participant->period?->credit_threshold ?? 75;

        if ($participant->credit >= $threshold) {
            return;
        }

        $user = $participant->user;

        // Kural 1: Dusuk kredi uyarisi (SMS gateway kapsam disi olsa bile log + event olustur)
        Log::info('credit.low_threshold_warning', [
            'user_id'        => $user->id,
            'participant_id' => $participant->id,
            'project_id'     => $participant->project_id,
            'credit'         => $participant->credit,
            'threshold'      => $threshold,
        ]);

        // Event dispatch: ileride SMS, bildirim, e-posta listener'lari baglanabilir.
        event(new \App\Events\CreditThresholdReached($participant, $threshold));

        if ($creditBefore === null || $creditBefore >= $threshold) {
            $this->notifyLowCredit($participant, $threshold);
        }

        // Kural 2: Mazeretsiz 3 devamsizlik → 6 ay kara liste
        $unexcusedAbsenceCount = CreditLog::query()
            ->where('participant_id', $participant->id)
            ->where('type', 'deduction')
            ->whereNotNull('program_id')
            ->where('excused', false)
            ->count();

        if ($unexcusedAbsenceCount >= 3) {
            Log::warning('credit.unexcused_absence_blacklist', [
                'user_id'         => $user->id,
                'participant_id'  => $participant->id,
                'absence_count'   => $unexcusedAbsenceCount,
            ]);

            if ($user->status !== 'blacklisted') {
                $user->update([
                    'status'            => 'blacklisted',
                    'blacklist_count'   => ($user->blacklist_count ?? 0) + 1,
                    'blacklisted_until' => now()->addMonths(6),
                ]);

                $this->notifyBlacklisted($participant, 'Mazeretsiz devamsizlik sayiniz kritik seviyeye ulasti.');
            }

            return;
        }

        // Kural 3: Kredi <= 30 → aninda kara liste
        if ($participant->credit <= 30) {
            Log::warning('credit.hard_limit_blacklist', [
                'user_id'        => $user->id,
                'participant_id' => $participant->id,
                'credit'         => $participant->credit,
            ]);

            if ($user->status !== 'blacklisted') {
                $user->update([
                    'status'            => 'blacklisted',
                    'blacklist_count'   => ($user->blacklist_count ?? 0) + 1,
                    'blacklisted_until' => now()->addMonths(6),
                ]);

                $this->notifyBlacklisted($participant, 'Krediniz kritik alt limite dustu.');
            }
        }
    }

    private function notifyLowCredit(Participant $participant, int $threshold): void
    {
        $participant->loadMissing(['user:id,email,name,surname', 'project.coordinators:id,email,name,surname']);
        $projectName = $participant->project?->name ?? 'KADEME';
        $studentName = trim(($participant->user?->name ?? '').' '.($participant->user?->surname ?? ''));

        if ($participant->user?->id) {
            SystemNotification::notify(
                $participant->user->id,
                'credit_low',
                'Kredi durumunuz kritik seviyeye yaklasti',
                "Mevcut krediniz {$participant->credit}. Esik deger: {$threshold}.",
                '/student/dashboard',
                Participant::class,
                $participant->id
            );
        }

        if ($participant->user?->email) {
            $this->notificationService->sendEmail(
                [$participant->user->email],
                'KADEME kredi uyarisi',
                "Merhaba {$studentName},\n{$projectName} kapsaminda mevcut krediniz {$participant->credit} seviyesine dustu. Esik deger: {$threshold}. Lutfen sonraki programlara katilim ve geri bildirim sureclerini takip edin.",
                $participant->project_id
            );
        }

        $coordinatorEmails = $participant->project?->coordinators?->pluck('email')->filter()->values()->all() ?? [];
        if ($coordinatorEmails !== []) {
            $this->notificationService->sendEmail(
                $coordinatorEmails,
                'Kredi risk raporu',
                "Proje: {$projectName}\nKatilimci: {$studentName}\nMevcut kredi: {$participant->credit}\nEsik deger: {$threshold}",
                $participant->project_id
            );
        }
    }

    private function notifyBlacklisted(Participant $participant, string $reason): void
    {
        $participant->loadMissing(['user:id,email,name,surname,blacklisted_until', 'project.coordinators:id,email,name,surname']);
        $projectName = $participant->project?->name ?? 'KADEME';
        $studentName = trim(($participant->user?->name ?? '').' '.($participant->user?->surname ?? ''));
        $until = optional($participant->user?->blacklisted_until)?->format('d.m.Y');

        if ($participant->user?->id) {
            SystemNotification::notify(
                $participant->user->id,
                'blacklist',
                'Basvuru kisitlamasi olustu',
                $until ? "{$reason} Kisit bitis tarihi: {$until}." : $reason,
                '/student/dashboard',
                Participant::class,
                $participant->id
            );
        }

        if ($participant->user?->email) {
            $this->notificationService->sendEmail(
                [$participant->user->email],
                'KADEME basvuru kisitlamasi bilgilendirmesi',
                "Merhaba {$studentName},\n{$projectName} kapsaminda {$reason}".($until ? "\nKisit bitis tarihi: {$until}" : ''),
                $participant->project_id
            );
        }

        $coordinatorEmails = $participant->project?->coordinators?->pluck('email')->filter()->values()->all() ?? [];
        if ($coordinatorEmails !== []) {
            $this->notificationService->sendEmail(
                $coordinatorEmails,
                'Katilimci kara listeye alindi',
                "Proje: {$projectName}\nKatilimci: {$studentName}\nGerekce: {$reason}\nMevcut kredi: {$participant->credit}".($until ? "\nKisit bitis tarihi: {$until}" : ''),
                $participant->project_id
            );
        }
    }
}
