<?php

namespace App\Jobs;

use App\Models\Program;
use App\Models\Participant;
use App\Services\CreditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CreditDeductionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected Program $program;

    /**
     * Create a new job instance.
     */
    public function __construct(Program $program)
    {
        $this->program = $program;
    }

    /**
     * Execute the job.
     */
    public function handle(CreditService $creditService): void
    {
        // Program bittiğinde çağrılır.
        // Bu programın ait olduğu proje ve dönemdeki tüm aktif katılımcıları bul
        $participants = Participant::where('project_id', $this->program->project_id)
            ->where('period_id', $this->program->period_id)
            ->where('status', 'active')
            ->get();

        foreach ($participants as $participant) {
            // Öğrenci bu programa katılmış mı?
            $hasAttended = \App\Models\Attendance::where('program_id', $this->program->id)
                ->where('user_id', $participant->user_id)
                ->exists();

            // Eğer katılmamışsa kredi düşümünü (ceza) uygula
            if (!$hasAttended) {
                $deductionAmount = $this->program->credit_deduction ?? 10;
                
                $creditService->deduct(
                    $participant,
                    $deductionAmount,
                    "Devamsızlık: {$this->program->title}",
                    $this->program->id
                );
            }
        }
    }
}
