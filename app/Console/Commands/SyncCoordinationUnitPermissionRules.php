<?php

namespace App\Console\Commands;

use App\Services\CoordinationUnitPermissionRuleSyncService;
use Illuminate\Console\Command;

class SyncCoordinationUnitPermissionRules extends Command
{
    protected $signature = 'coordination-units:sync-permissions
                            {--apply : Planlanan bootstrap, family pasiflestirme ve reset degisikliklerini uygular}
                            {--reset-defaults : Admin ozellestirmelerini acikca varsayilan sablona dondurur}
                            {--format=table : Çıktı formatı: table veya json}
                            {--strict : Blocker varsa başarısız çıkış kodu döndürür}';

    protected $description = 'Birim koordinatör/personel permission şablonlarını silmeden ve varsayılan dry-run ile senkronlar.';

    public function handle(CoordinationUnitPermissionRuleSyncService $service): int
    {
        $format = strtolower((string) $this->option('format'));
        if (! in_array($format, ['table', 'json'], true)) {
            $this->error('Desteklenmeyen format. table veya json kullanın.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $resetDefaults = (bool) $this->option('reset-defaults');
        $report = $service->execute($apply, $resetDefaults);

        if ($format === 'json') {
            $this->line(json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));
        } else {
            $this->table(['Ölçüm', 'Değer'], [
                ['Mod', $report['meta']['mode']],
                ['Şablon politikası', $report['meta']['template_policy']],
                ['Şablonlu birim', $report['summary']['templated_unit_count']],
                ['Aktif kural', $report['summary']['active_rule_count']],
                ['Tarihsel kural', $report['summary']['historical_rule_count']],
                ['Hedef varsayılan kural', $report['summary']['target_default_rule_count']],
                ['Aktif hedef kural', $report['summary']['active_target_rule_count']],
                ['Hedefle birebir aktif', $report['summary']['active_exact_target_rule_count']],
                ['Ek aktif admin kuralı', $report['summary']['active_additional_rule_count']],
                ['Legacy coordinator yetkisi', $report['legacy_global_role_permissions']['coordinator']['count']],
                ['Legacy staff yetkisi', $report['legacy_global_role_permissions']['staff']['count']],
                ['Yeni kural', $report['summary']['create_count']],
                ['Varsayılana dönecek', $report['summary']['update_count']],
                ['Pasifleşecek family kuralı', $report['summary']['deactivate_count']],
                ['Korunan admin kararı', $report['summary']['preserved_customization_count']],
                ['Toplam önerilen', $report['summary']['proposed_change_count']],
                ['Toplam uygulanan', $report['summary']['applied_change_count']],
                ['Blocker', $report['summary']['blocker_count']],
            ]);
        }

        $blocked = $report['summary']['blocker_count'] > 0;
        $verificationFailed = $apply && isset($report['verification'])
            && (! $report['verification']['healthy'] || ! $report['verification']['idempotent']);

        if ($blocked) {
            $this->error('Permission senkronu uygulanmadı: mevcut kurallarla çakışma bulundu.');
        } elseif ($apply) {
            $this->info("Permission senkronu tamamlandı: {$report['summary']['applied_change_count']} değişiklik uygulandı.");
        } else {
            $this->info('Dry-run tamamlandı; permission kuralları değiştirilmedi.');
        }

        return $verificationFailed || ($this->option('strict') && $blocked)
            ? self::FAILURE
            : self::SUCCESS;
    }
}
