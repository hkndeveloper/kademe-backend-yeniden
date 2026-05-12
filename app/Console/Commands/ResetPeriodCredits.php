<?php

namespace App\Console\Commands;

use App\Models\CreditLog;
use App\Models\Participant;
use App\Models\Period;
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

    public function handle(): int
    {
        $periodQuery = Period::query()->where('status', 'active');

        if ($this->option('period')) {
            $periodQuery->where('id', (int) $this->option('period'));
        } elseif (! $this->option('force')) {
            // Sadece bugün başlayan dönemleri işle
            $periodQuery->whereDate('start_date', today());
        }

        $periods = $periodQuery->get();

        if ($periods->isEmpty()) {
            $this->info('Islenecek donem bulunamadi.');
            return 0;
        }

        $totalReset = 0;

        foreach ($periods as $period) {
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
                        'user_id'        => $participant->user_id,
                        'project_id'     => $participant->project_id,
                        'period_id'      => $period->id,
                        'amount'         => $delta,
                        'type'           => 'period_reset',
                        'reason'         => "Donem degisimi kredi reseti ({$period->name})",
                        'program_id'     => null,
                        'created_by'     => null,
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
