<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Services\WaitlistService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AdvanceApplicationWaitlists extends Command
{
    protected $signature = 'applications:advance-waitlists {--project=} {--period=} {--send}';

    protected $description = 'Aktif donemlerde yedek davetlerini onizler veya secilen projelerde ilerletir.';

    public function handle(WaitlistService $waitlists): int
    {
        $projectIds = $this->option('project')
            ? [(int) $this->option('project')]
            : config('application_waitlist.auto_project_ids', []);
        $projectIds = array_values(array_filter($projectIds, fn ($id) => $id > 0));
        if ($this->option('send') && $projectIds === []) {
            $this->error('Davet gondermek icin --project veya izin verilen proje kimlikleri gereklidir.');

            return self::FAILURE;
        }

        $query = Application::query()
            ->where('status', 'waitlisted')
            ->whereNotNull('period_id')
            ->whereHas('period', fn ($query) => $query->where('status', 'active'))
            ->when($projectIds !== [], fn ($query) => $query->whereIn('project_id', $projectIds))
            ->when($this->option('period'), fn ($query) => $query->where('period_id', (int) $this->option('period')))
            ->select('project_id', 'period_id', 'program_id')
            ->distinct()
            ->orderBy('project_id')
            ->orderBy('period_id')
            ->orderBy('program_id');

        $rows = [];
        $failed = 0;
        foreach ($query->get() as $group) {
            $scope = Application::query()
                ->where('status', 'waitlisted')
                ->where('project_id', $group->project_id)
                ->where('period_id', $group->period_id)
                ->when($group->program_id,
                    fn ($query) => $query->where('program_id', $group->program_id),
                    fn ($query) => $query->whereNull('program_id'))
                ->orderByRaw('waitlist_order IS NULL')
                ->orderBy('waitlist_order')
                ->orderBy('created_at')
                ->first();
            if (! $scope) {
                continue;
            }

            if (! $this->option('send')) {
                $candidate = Application::query()
                    ->where('status', 'waitlisted')
                    ->where('project_id', $group->project_id)
                    ->where('period_id', $group->period_id)
                    ->when($group->program_id,
                        fn ($query) => $query->where('program_id', $group->program_id),
                        fn ($query) => $query->whereNull('program_id'))
                    ->whereNull('waitlist_invited_at')
                    ->orderByRaw('waitlist_order IS NULL')
                    ->orderBy('waitlist_order')
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->first();
                $rows[] = [$group->project_id, $group->period_id, $group->program_id ?? '-', $candidate?->id ?? '-', 'Olası sıradaki; kurallar gönderimde yeniden kontrol edilir'];
                continue;
            }

            try {
                $invited = $waitlists->inviteNextIfSeatAvailable($scope);
                $rows[] = [$group->project_id, $group->period_id, $group->program_id ?? '-', $invited?->id ?? '-', $invited?->waitlist_invitation_delivery_status ?? 'Davet yok'];
            } catch (\Throwable $exception) {
                $failed++;
                Log::warning('application.waitlist_advance_failed', [
                    'project_id' => $group->project_id,
                    'period_id' => $group->period_id,
                    'program_id' => $group->program_id,
                    'error_type' => $exception::class,
                ]);
                $rows[] = [$group->project_id, $group->period_id, $group->program_id ?? '-', '-', 'Hata'];
            }
        }

        $this->table(['Proje', 'Dönem', 'Program', 'Başvuru', 'Sonuç'], $rows);
        $this->info($this->option('send') ? 'Yedek davet kontrolü tamamlandı.' : 'Önizleme; e-posta gönderilmedi.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
