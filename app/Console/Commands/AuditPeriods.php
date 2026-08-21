<?php

namespace App\Console\Commands;

use App\Services\PeriodAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class AuditPeriods extends Command
{
    protected $signature = 'periods:audit
                            {--report-only : Degisiklik yapmadan salt-okunur denetim calistirir}
                            {--format=table : Cikti formati: table veya json}
                            {--output= : JSON raporunun yazilacagi dosya yolu}
                            {--strict : Anomali varsa basarisiz cikis kodu dondurur}';

    protected $description = 'Donem, arsiv ve period_id baglantilarini salt-okunur olarak denetler.';

    public function handle(PeriodAuditService $auditService): int
    {
        $format = strtolower((string) $this->option('format'));
        if (! in_array($format, ['table', 'json'], true)) {
            throw new InvalidArgumentException('Desteklenmeyen format. table veya json kullanin.');
        }

        $report = $auditService->run();
        $encodedReport = json_encode(
            $report,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        if ($output = $this->option('output')) {
            $outputPath = $this->absoluteOutputPath((string) $output);
            File::ensureDirectoryExists(dirname($outputPath));
            File::put($outputPath, $encodedReport.PHP_EOL);
            $this->info("Salt-okunur donem denetim raporu yazildi: {$outputPath}");
        }

        if ($format === 'json') {
            $this->line($encodedReport);
        } else {
            $this->renderTableReport($report);
        }

        if ($report['summary']['healthy']) {
            $this->info('Donem denetimi tamamlandi: kritik anomali bulunamadi.');
        } else {
            $this->error("Donem denetimi tamamlandi: {$report['summary']['anomaly_count']} kritik anomali bulundu.");
        }

        return $this->option('strict') && ! $report['summary']['healthy']
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function renderTableReport(array $report): void
    {
        $statuses = collect($report['summary']['period_statuses'])
            ->map(fn ($count, $status) => "{$status}: {$count}")
            ->implode(', ');

        $this->table(['Olcum', 'Deger'], [
            ['DB surucusu', $report['meta']['driver']],
            ['Projeler', $report['summary']['projects']],
            ['Donemler', $report['summary']['periods']],
            ['Donem durumlari', $statuses ?: '-'],
            ['Arsivler', $report['summary']['period_archives']],
            ['period_id tasiyan tablolar', $report['summary']['period_scoped_tables']],
            ['Kritik anomaliler', $report['summary']['anomaly_count']],
            ['Uyarilar', $report['summary']['warning_count']],
        ]);

        $this->newLine();
        $this->line('Donemsel tablo envanteri');
        $this->table(
            ['Tablo', 'Sinif', 'Null', 'Yetim', 'Proje/donem uyusmazligi'],
            collect($report['period_tables'])->map(fn (array $table) => [
                $table['table'],
                $table['classification'],
                $table['null_period_count'],
                $table['orphan_period_count'],
                $table['project_period_mismatch_count'],
            ])->all(),
        );

        $this->newLine();
        $this->line('Anomali gruplari');
        $this->table(
            ['Grup', 'Kayit'],
            collect($report['anomalies'])->map(fn ($items, $group) => [$group, count($items)])->values()->all(),
        );
    }

    private function absoluteOutputPath(string $output): string
    {
        if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $output) === 1) {
            return $output;
        }

        return base_path($output);
    }
}
