<?php

namespace App\Console\Commands;

use App\Enums\PeriodWriteAction;
use App\Models\CreditLog;
use App\Models\Participant;
use App\Models\Period;
use App\Models\Project;
use App\Services\PeriodWritePolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Yeni dönem başladığında aktif katılımcıların kredilerini
 * dönemin başlangıç değerine (credit_start_amount) resetler.
 *
 * Kullanım:
 *   php artisan kademe:reset-period-credits            → sadece bugün başlayan dönemleri işler
 *   php artisan kademe:reset-period-credits --force     → tüm aktif dönemleri işler
 *   php artisan kademe:reset-period-credits --period=5  → belirli bir dönemi işler
 */
class ResetPeriodCredits extends Command
{
    protected $signature = 'kademe:reset-period-credits
                            {--period= : Belirli bir donem ID}
                            {--force : Tum aktif donemleri isle}';

    protected $description = 'Donem degisiminde aktif katilimcilarin kredilerini donem baslangic degerine resetler.';

    public function handle(PeriodWritePolicy $periodWritePolicy): int
    {
        if ($this->option('period')) {
            $period = Period::query()->with(['project.currentPeriod'])->find((int) $this->option('period'));
            $periods = collect();
            if ($period
                && $period->status === 'active'
                && (int) optional($period->project->currentPeriodOrLegacy())->id === (int) $period->id) {
                $periods->push($period);
            }
        } else {
            $projects = Project::query()
                ->where('status', 'active')
                ->with([
                    'currentPeriod',
                    'periods' => fn ($query) => $query
                        ->whereIn('status', ['active', 'closing'])
                        ->orderByDesc('start_date'),
                ])
                ->get();

            $periods = $projects
                ->map(fn (Project $project) => $project->currentPeriodOrLegacy())
                ->filter(fn (?Period $period) => $period?->status === 'active')
                ->when(
                    ! $this->option('force'),
                    fn ($items) => $items->filter(fn (Period $period) => $period->start_date?->isToday()),
                )
                ->values();
        }

        if ($periods->isEmpty()) {
            $this->info('Islenecek donem bulunamadi.');

            return 0;
        }

        $totalReset = 0;

        foreach ($periods as $period) {
            $periodWritePolicy->assertAllowed(null, $period, PeriodWriteAction::RESOLVE_OPERATION);
            $startAmount = $period->credit_start_amount ?? 100;
            $this->info("Donem: {$period->name} (ID: {$period->id}) — Baslangic kredi: {$startAmount}");

            $participants = Participant::query()
                ->where('period_id', $period->id)
                ->where('status', 'active')
                ->get();

            foreach ($participants as $participant) {
                $oldCredit = $participant->credit;
                if ((int) $oldCredit === (int) $startAmount) {
                    continue;
                }

                DB::transaction(function () use ($participant, $startAmount, $period, $oldCredit) {
                    $delta = $startAmount - $oldCredit;
                    CreditLog::create([
                        'participant_id' => $participant->id,
                        'user_id' => $participant->user_id,
                        'project_id' => $participant->project_id,
                        'period_id' => $period->id,
                        'amount' => $delta,
                        'type' => 'period_reset',
                        'reason' => "Donem degisimi kredi reseti ({$period->name})",
                        'program_id' => null,
                        'created_by' => null,
                    ]);

                    $participant->update(['credit' => $startAmount]);
                });

                $totalReset++;
            }

            $this->info("  → {$participants->count()} katilimci, {$totalReset} resetlendi.");
        }

        $this->info("Tamamlandi. Toplam {$totalReset} katilimcinin kredisi resetlendi.");

        return 0;
    }
}
