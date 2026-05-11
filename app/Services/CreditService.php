<?php

namespace App\Services;

use App\Models\Participant;
use App\Models\CreditLog;
use App\Models\Program;
use Illuminate\Support\Facades\DB;

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
     * Puan belli bir sınırın (örn: 75) altına düşünce yapılacak işlemler
     */
    private function checkThresholdAndBlacklist(Participant $participant)
    {
        $threshold = $participant->period->credit_threshold ?? 75;

        // Kredi sınırın altındaysa
        if ($participant->credit < $threshold) {
            
            // Eğer 30'un altındaysa komple kara listeye al (Örnek Mantık)
            if ($participant->credit <= 30) {
                $user = $participant->user;
                $user->update([
                    'status' => 'blacklisted',
                    'blacklist_count' => $user->blacklist_count + 1,
                    // 6 aylık kara liste cezası
                    'blacklisted_until' => now()->addMonths(6)
                ]);
            }
            
            // NOT: Burada ileride SendSmsJob tetiklenebilir "Krediniz risk seviyesinde"
        }
    }
}
