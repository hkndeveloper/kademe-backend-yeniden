<?php

namespace App\Console\Commands;

use App\Services\FinancialProcessingUnitBackfillService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class BackfillFinancialProcessingUnits extends Command
{
    protected $signature = 'financials:backfill-processing-units
                            {--apply : Planlanan processing unit atamalarini uygular}
                            {--format=table : Cikti formati: table veya json}';

    protected $description = 'Eski mali kayitlari proje finance_procurement sorumluluguna guvenli ve idempotent bicimde baglar.';

    public function handle(FinancialProcessingUnitBackfillService $service): int
    {
        $format = strtolower((string) $this->option('format'));
        if (! in_array($format, ['table', 'json'], true)) {
            throw new InvalidArgumentException('Desteklenmeyen format. table veya json kullanin.');
        }

        $report = $service->execute((bool) $this->option('apply'));

        if ($format === 'json') {
            $this->line(json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));
        } else {
            $this->table(['Olcum', 'Deger'], [
                ['Mod', $report['meta']['mode']],
                ['Onerilen atama', $report['summary']['proposed_change_count']],
                ['Uygulanan atama', $report['summary']['applied_change_count']],
                ['Atlanan kayit', $report['summary']['skipped_count']],
            ]);
        }

        if ($this->option('apply')) {
            $this->info("Mali kayit backfill tamamlandi: {$report['summary']['applied_change_count']} atama uygulandi; mevcut atamalar degistirilmedi.");
        } else {
            $this->info('Dry-run tamamlandi; veritabaninda degisiklik yapilmadi.');
        }

        return self::SUCCESS;
    }
}
