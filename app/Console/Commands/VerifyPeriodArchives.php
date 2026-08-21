<?php

namespace App\Console\Commands;

use App\Models\PeriodArchive;
use App\Services\PeriodArchiveService;
use App\Services\PeriodLifecycleMonitor;
use Illuminate\Console\Command;

class VerifyPeriodArchives extends Command
{
    protected $signature = 'periods:verify-archives {--period=} {--archive=}';

    protected $description = 'Donem arsivlerinin canonical hash ve onceki surum zincirini dogrular.';

    public function handle(PeriodArchiveService $archiveService, PeriodLifecycleMonitor $monitor): int
    {
        $query = PeriodArchive::query()->with('previousArchive')->orderBy('period_id')->orderBy('archive_version');
        if ($this->option('period')) {
            $query->where('period_id', (int) $this->option('period'));
        }
        if ($this->option('archive')) {
            $query->whereKey((int) $this->option('archive'));
        }

        $invalid = 0;
        $rows = $query->get()->map(function (PeriodArchive $archive) use ($archiveService, &$invalid) {
            $result = $archiveService->verify($archive);
            $invalid += $result['status'] === 'invalid' ? 1 : 0;

            return [$archive->id, $archive->period_id, $archive->archive_version, $result['status']];
        });

        $this->table(['Arsiv', 'Donem', 'Surum', 'Durum'], $rows->all());
        $this->info($invalid === 0 ? 'Tum arsivler dogrulandi.' : "{$invalid} arsiv gecersiz.");
        $monitor->archiveVerificationRunCompleted($rows->count(), $invalid);

        return $invalid === 0 ? self::SUCCESS : self::FAILURE;
    }
}
