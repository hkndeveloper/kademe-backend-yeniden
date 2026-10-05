<?php

namespace App\Console\Commands;

use App\Models\KpdReport;
use App\Support\KpdReportStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PrivatizeKpdReports extends Command
{
    protected $signature = 'kpd:privatize-reports {--apply : Copy legacy files, switch database paths, and remove public originals}';

    protected $description = 'Move legacy KPD reports from the general media disk into private storage';

    public function handle(): int
    {
        $total = 0;
        $moved = 0;
        $failed = 0;

        KpdReport::query()->orderBy('id')->chunkById(100, function ($reports) use (&$total, &$moved, &$failed) {
            foreach ($reports as $report) {
                if (KpdReportStorage::isPrivate($report->file_path)) {
                    continue;
                }

                $total++;
                $oldPath = (string) $report->file_path;
                $oldKey = KpdReportStorage::key($oldPath);
                if (! $oldKey || ! KpdReportStorage::exists($oldPath)) {
                    $this->error("Rapor {$report->id}: eski dosya bulunamadi; veritabani kaydi degistirilmedi.");
                    $failed++;
                    continue;
                }

                if (! $this->option('apply')) {
                    $this->line("Rapor {$report->id}: tasinacak ({$oldKey}).");
                    continue;
                }

                $newKey = 'kpd-reports/migrated/'.$report->id.'/'.basename($oldKey);
                $source = KpdReportStorage::disk($oldPath);
                $target = Storage::disk('application_private');
                try {
                    $stream = $source->readStream($oldKey);
                    if ($stream === false) {
                        throw new \RuntimeException('Dosya okunamadi.');
                    }
                    $written = $target->writeStream($newKey, $stream);
                } catch (\Throwable $exception) {
                    $target->delete($newKey);
                    $this->error("Rapor {$report->id}: kopyalanamadi ({$exception->getMessage()}); eski dosya korundu.");
                    $failed++;
                    continue;
                } finally {
                    if (isset($stream) && is_resource($stream)) {
                        fclose($stream);
                    }
                }

                if (! $written || ! $target->exists($newKey) || $source->size($oldKey) !== $target->size($newKey)) {
                    $target->delete($newKey);
                    $this->error("Rapor {$report->id}: kopya dogrulanamadi; eski dosya korundu.");
                    $failed++;
                    continue;
                }

                $updated = KpdReport::query()
                    ->whereKey($report->id)
                    ->where('file_path', $oldPath)
                    ->update(['file_path' => KpdReportStorage::privatePath($newKey)]);

                if ($updated !== 1) {
                    $target->delete($newKey);
                    $this->error("Rapor {$report->id}: kayit degisti; eski dosya korundu.");
                    $failed++;
                    continue;
                }

                if (! $source->delete($oldKey)) {
                    $this->warn("Rapor {$report->id}: ozel kopya hazir, fakat eski genel medya kopyasi silinemedi.");
                    $failed++;
                }

                $moved++;
            }
        });

        $this->info($this->option('apply')
            ? "KPD raporlari: {$moved}/{$total} tasindi; {$failed} sorun."
            : "KPD raporlari: {$total} eski kayit bulundu; degisiklik icin --apply kullanin.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
