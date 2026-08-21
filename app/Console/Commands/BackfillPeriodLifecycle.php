<?php

namespace App\Console\Commands;

use App\Services\PeriodLifecycleBackfillService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use JsonException;

class BackfillPeriodLifecycle extends Command
{
    protected $signature = 'periods:backfill-lifecycle
                            {--apply : Planlanan degisiklikleri tek transaction icinde uygular}
                            {--mapping= : Manuel passive ve legacy arsiv onaylarini iceren JSON dosyasi}
                            {--format=table : Cikti formati: table veya json}
                            {--output= : JSON raporunun yazilacagi dosya yolu}
                            {--strict : Blocker veya dogrulama sorunu varsa basarisiz cikis kodu dondurur}';

    protected $description = 'Legacy donem durumlarini ve current period pointerlarini guvenli, idempotent bicimde backfill eder.';

    public function handle(PeriodLifecycleBackfillService $service): int
    {
        $format = strtolower((string) $this->option('format'));
        if (! in_array($format, ['table', 'json'], true)) {
            throw new InvalidArgumentException('Desteklenmeyen format. table veya json kullanin.');
        }

        try {
            $mapping = $this->readMapping();
        } catch (InvalidArgumentException|JsonException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $report = $service->execute($apply, $mapping);
        $encoded = json_encode(
            $report,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        if ($output = $this->option('output')) {
            $path = $this->absolutePath((string) $output);
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $encoded.PHP_EOL);
            $this->info("Donem backfill raporu yazildi: {$path}");
        }

        if ($format === 'json') {
            $this->line($encoded);
        } else {
            $this->renderTable($report);
        }

        $blocked = $report['summary']['blocker_count'] > 0;
        $verificationFailed = $apply && isset($report['verification'])
            && (! $report['verification']['idempotent'] || ! $report['verification']['healthy']);

        if ($blocked) {
            $this->error('Backfill uygulanmadi: manuel karar veya veri duzeltmesi gerektiren blocker bulundu.');
        } elseif ($apply) {
            $this->info("Backfill tamamlandi: {$report['summary']['applied_change_count']} degisiklik uygulandi.");
        } else {
            $this->info('Dry-run tamamlandi; veritabaninda degisiklik yapilmadi.');
        }

        return ($apply && $blocked) || $verificationFailed || ($this->option('strict') && $blocked)
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function readMapping(): array
    {
        $mappingPath = $this->option('mapping');
        if (! $mappingPath) {
            return [];
        }

        $path = $this->absolutePath((string) $mappingPath);
        if (! File::isFile($path)) {
            throw new InvalidArgumentException("Mapping dosyasi bulunamadi: {$path}");
        }

        $mapping = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($mapping)) {
            throw new InvalidArgumentException('Mapping dosyasinin kok degeri bir JSON nesnesi olmalidir.');
        }

        return $mapping;
    }

    private function renderTable(array $report): void
    {
        $this->table(['Olcum', 'Deger'], [
            ['Mod', $report['meta']['mode']],
            ['Passive durum degisimi', $report['summary']['passive_status_change_count']],
            ['Pointer degisimi', $report['summary']['pointer_change_count']],
            ['Legacy arsiv', $report['summary']['legacy_archive_change_count']],
            ['Toplam onerilen', $report['summary']['proposed_change_count']],
            ['Blocker', $report['summary']['blocker_count']],
            ['Uygulanabilir', $report['summary']['can_apply'] ? 'evet' : 'hayir'],
        ]);

        if ($report['blockers'] !== []) {
            $this->newLine();
            $this->line('Blocker listesi');
            $this->table(
                ['Tip', 'Detay'],
                collect($report['blockers'])->map(fn (array $item) => [
                    $item['type'],
                    json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ])->all(),
            );
        }
    }

    private function absolutePath(string $path): string
    {
        if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }
}
