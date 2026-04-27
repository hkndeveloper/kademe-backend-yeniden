<?php

namespace App\Services;

use App\Models\Participant;
use App\Models\CreditLog;
use Illuminate\Support\Facades\DB;

class CreditService
{
    /**
     * Katılımcıdan kredi (puan) düşürür
     */
    public function deduct(Participant $participant, int $amount, string $reason, ?int $programId = null, ?int $adminId = null)
    {
        DB::beginTransaction();
        try {
            $log = CreditLog::create([
                'participant_id' => $participant->id,
                'user_id' => $participant->user_id,
                'project_id' => $participant->project_id,
                'period_id' => $participant->period_id,
                'amount' => -$amount,
                'type' => 'deduction',
                'reason' => $reason,
                'program_id' => $programId,
                'created_by' => $adminId ?? $participant->user_id,
            ]);

            $participant->decrement('credit', $amount);

            $this->checkThresholdAndBlacklist($participant);

            DB::commit();
            return $log;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Krediyi artırır (Yoklamaya katılınca vs.)
     */
    public function reward(Participant $participant, int $amount, string $reason, ?int $programId = null, ?int $adminId = null)
    {
        DB::beginTransaction();
        try {
            $log = CreditLog::create([
                'participant_id' => $participant->id,
                'user_id' => $participant->user_id,
                'project_id' => $participant->project_id,
                'period_id' => $participant->period_id,
                'amount' => $amount,
                'type' => 'manual_adjust',
                'reason' => $reason,
                'program_id' => $programId,
                'created_by' => $adminId ?? $participant->user_id,
            ]);

            $participant->increment('credit', $amount);

            DB::commit();
            return $log;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
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
