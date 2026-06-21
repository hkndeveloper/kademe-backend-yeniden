<?php

namespace App\Jobs;

use App\Models\Attendance;
use App\Models\Participant;
use App\Models\Program;
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

    public function __construct(Program $program)
    {
        $this->program = $program;
    }

    public function handle(CreditService $creditService): void
    {
        $participants = Participant::query()
            ->where('project_id', $this->program->project_id)
            ->when(
                $this->program->period_id,
                fn ($query) => $query->where('period_id', $this->program->period_id),
                fn ($query) => $query->whereNull('period_id')
            )
            ->where('status', 'active')
            ->get();

        foreach ($participants as $participant) {
            $hasAttended = Attendance::query()
                ->where('program_id', $this->program->id)
                ->where('user_id', $participant->user_id)
                ->where('is_valid', true)
                ->exists();

            $creditService->deductOnceForProgram(
                $participant,
                $this->program,
                null,
                $hasAttended
                    ? 'Etkinlik tamamlandi, degerlendirme bekleniyor'
                    : 'Etkinlige katilim saglanmadi, kredi dusumu uygulandi'
            );
        }
    }
}
