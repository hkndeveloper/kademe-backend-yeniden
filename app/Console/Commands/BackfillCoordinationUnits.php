<?php

namespace App\Console\Commands;

use App\Services\CoordinationUnitBackfillService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class BackfillCoordinationUnits extends Command
{
    protected $signature = 'coordination-units:backfill
                            {--apply : Planlanan birim ve sorumluluk değişikliklerini uygular}
                            {--format=table : Çıktı formatı: table veya json}
                            {--strict : Blocker varsa başarısız çıkış kodu döndürür}';

    protected $description = 'Proje ve hizmet koordinatörlüklerini üyelik oluşturmadan güvenli ve idempotent biçimde backfill eder.';

    public function handle(CoordinationUnitBackfillService $service): int
    {
        $format = strtolower((string) $this->option('format'));
        if (! in_array($format, ['table', 'json'], true)) {
            throw new InvalidArgumentException('Desteklenmeyen format. table veya json kullanın.');
        }

        $apply = (bool) $this->option('apply');
        $report = $service->execute($apply);

        if ($format === 'json') {
            $this->line(json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));
        } else {
            $this->renderTable($report);
        }

        $blocked = $report['summary']['blocker_count'] > 0;
        $verificationFailed = $apply && isset($report['verification'])
            && (! $report['verification']['healthy'] || ! $report['verification']['idempotent']);

        if ($blocked) {
            $this->error('Backfill uygulanmadı: mevcut birim verisiyle çakışma bulundu.');
        } elseif ($apply) {
            $this->info("Backfill tamamlandı: {$report['summary']['applied_change_count']} değişiklik uygulandı; üyelik oluşturulmadı.");
        } else {
            $this->info('Dry-run tamamlandı; veritabanında değişiklik yapılmadı ve üyelik oluşturulmadı.');
        }

        return $verificationFailed || ($this->option('strict') && $blocked)
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function renderTable(array $report): void
    {
        $this->table(['Ölçüm', 'Değer'], [
            ['Mod', $report['meta']['mode']],
            ['Aktif proje', $report['summary']['active_project_count']],
            ['Proje birimi', $report['summary']['project_unit_change_count']],
            ['Hizmet birimi', $report['summary']['service_unit_change_count']],
            ['Proje sorumluluğu', $report['summary']['responsibility_change_count']],
            ['Üyelik', $report['summary']['membership_change_count']],
            ['Toplam önerilen', $report['summary']['proposed_change_count']],
            ['Blocker', $report['summary']['blocker_count']],
        ]);
    }
}
