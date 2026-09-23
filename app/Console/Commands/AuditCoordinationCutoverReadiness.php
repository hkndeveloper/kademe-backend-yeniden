<?php

namespace App\Console\Commands;

use App\Services\CoordinationCutoverReadinessService;
use Illuminate\Console\Command;

class AuditCoordinationCutoverReadiness extends Command
{
    protected $signature = 'coordination-units:cutover-readiness
        {--target=shadow : shadow, pilot veya enforce}
        {--user=* : Pilot icin degerlendirilecek kullanici ID listesi}
        {--format=table : table veya json}
        {--strict : Hedef hazir degilse basarisiz cikis kodu dondurur}';

    protected $description = 'Koordinasyon yetkilendirmesi cutover hazirligini veri yazmadan denetler';

    public function handle(CoordinationCutoverReadinessService $service): int
    {
        $target = strtolower((string) $this->option('target'));
        if (! in_array($target, ['shadow', 'pilot', 'enforce'], true)) {
            $this->error('target shadow, pilot veya enforce olmalidir.');

            return self::INVALID;
        }

        $userIds = collect($this->option('user'))
            ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        $report = $service->inspect($target, $userIds);

        if ((string) $this->option('format') === 'json') {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $summary = $report['summary'];
            $this->table(['Kontrol', 'Sonuc'], [
                ['Hedef', $target],
                ['Salt okunur', 'evet'],
                ['Sema hazir', $summary['schema_ready'] ? 'evet' : 'hayir'],
                ['Teknik hazir', $summary['technical_ready'] ? 'evet' : 'hayir'],
                ['Uyelik verisi hazir', $summary['membership_data_ready'] ? 'evet' : 'hayir'],
                ['Hedef acilabilir', $summary['requested_target_ready'] ? 'evet' : 'hayir'],
                ['Aktif yetkili kullanici', $summary['active_authority_user_count']],
                ['Degerlendirilen kullanici', $summary['evaluated_user_count']],
                ['Aktif uyelik', $summary['active_membership_count']],
                ['Teknik engel', $summary['technical_blocker_count']],
                ['Veri engeli', $summary['data_blocker_count']],
            ]);

            foreach ($report['findings']['technical_blockers'] as $blocker) {
                $this->error('TEKNIK: '.json_encode($blocker, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            foreach ($report['findings']['data_blockers'] as $blocker) {
                $this->warn('VERI: '.json_encode($blocker, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            foreach ($report['findings']['warnings'] as $warning) {
                $this->warn('UYARI: '.json_encode($warning, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }

        if ($this->option('strict') && ! $report['summary']['requested_target_ready']) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
