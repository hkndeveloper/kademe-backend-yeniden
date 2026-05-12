<?php

namespace App\Services;

use App\Models\Participant;
use App\Models\CreditLog;
use App\Models\Program;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreditService
{
    /**
     * Katılımcıdan kredi (puan) düşürür
     */
    public function deduct(Participant $participant, int $amount, string $reason, ?int $programId = null, ?int $adminId = null)
    {
        return DB::transaction(function () use ($participant, $amount, $reason, $programId, $adminId) {
            $log = $this->createLogAndApplyDelta(
                $participant,
                -abs($amount),
                'deduction',
                $reason,
                $programId,
                $adminId ?? $participant->user_id
            );

            $this->checkThresholdAndBlacklist($participant->fresh(['period', 'user']));

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

            $this->checkThresholdAndBlacklist($participant->fresh(['period', 'user']));

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
            $currentNet = (int) CreditLog::query()
                ->where('participant_id', $participant->id)
                ->where('program_id', $program->id)
                ->sum('amount');
            $targetNet = $isValid ? 0 : -$creditDeduction;
            $delta = $targetNet - $currentNet;

            if ($delta === 0) {
                return null;
            }

            $log = $this->createLogAndApplyDelta(
                $participant,
                $delta,
                $delta > 0 ? 'restore' : 'manual_adjust',
                $isValid
                    ? 'Manuel yoklama katildi olarak duzeltildi'
                    : 'Manuel yoklama gelmedi olarak duzeltildi',
                $program->id,
                $adminId
            );

            if ($delta < 0) {
                $this->checkThresholdAndBlacklist($participant->fresh(['period', 'user']));
            }

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
    private function checkThresholdAndBlacklist(Participant $participant): void
    {
        $threshold = $participant->period?->credit_threshold ?? 75;

        if ($participant->credit >= $threshold) {
            return;
        }

        $user = $participant->user;

        // Kural 1: Dusuk kredi uyarisi (SMS gateway kapsam disi olsa bile log olustur)
        Log::info('credit.low_threshold_warning', [
            'user_id'        => $user->id,
            'participant_id' => $participant->id,
            'project_id'     => $participant->project_id,
            'credit'         => $participant->credit,
            'threshold'      => $threshold,
        ]);

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
            }
        }
    }
}
