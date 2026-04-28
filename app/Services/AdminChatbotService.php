<?php

namespace App\Services;

use App\Models\Application;
use App\Models\FinancialTransaction;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Kural tabanlı panel asistanı: kullanıcı mesajından güvenli, önceden tanımlı sorgular çalıştırır.
 * Ham SQL veya kullanıcı girdisiyle dinamik sorgu üretilmez.
 */
class AdminChatbotService
{
    private const EXPORT_ROW_CAP = 500;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {
    }

    private const HELP_TEXT = <<<'TXT'
Şu an desteklenen örnek sorular:
• Bir proje adı veya kodu geçirerek (ör. Diplomasi360, KADEME+, Pergel): "aktif öğrenci sayısı", "katılımcı listesi", "özet"
• "Tüm projeler özet" veya "genel özet" — yönetebildiğiniz projelerdeki toplam katılımcı sayıları
• "Başvuru" + proje — bekleyen / onaylanan başvuru sayıları (yaklaşık)
• "Mali özet" + proje(ler) — harcama/ödeme toplamları (örn: "diplomasi360 ve pergel mali özet son 30 gün")
• Tarih filtresi: "son 7 gün", "son 30 gün", "bu ay", "YYYY-MM-DD - YYYY-MM-DD"

Çıktı: Tablo görünürse CSV olarak indirebilirsiniz. Tam doğal dil anlama yoktur; anahtar kelimeler ve proje eşleşmesi kullanılır.
TXT;

    public function handle(User $user, string $message): array
    {
        $normalized = $this->normalize($message);
        if ($normalized === '') {
            return $this->response('Lütfen bir soru veya komut yazın.', 'empty', null, null, null);
        }

        if ($this->isHelpIntent($normalized)) {
            return $this->response(self::HELP_TEXT, 'help', null, null, null);
        }

        $projects = $this->manageableProjects($user);
        if ($projects->isEmpty()) {
            return $this->response('Erişebileceğiniz aktif proje bulunmuyor.', 'no_projects', null, null, null);
        }

        $matched = $this->matchProjects($normalized, $projects);
        $wantsList = $this->wantsParticipantList($normalized);
        $wantsApplications = str_contains($normalized, 'basvuru') || str_contains($normalized, 'başvuru');
        $wantsFinancial = $this->wantsFinancialSummary($normalized);
        $limit = $this->extractLimit($normalized);
        $applicationStatusFilter = $this->extractApplicationStatusFilter($normalized);
        $participantStatusFilter = $this->extractParticipantStatusFilter($normalized);
        [$fromDate, $toDate, $dateLabel] = $this->extractDateRange($normalized);
        $wantsComparison = $this->wantsProjectComparison($normalized);
        $wantsSummaryAll = (str_contains($normalized, 'tum') || str_contains($normalized, 'tüm') || str_contains($normalized, 'genel'))
            && (str_contains($normalized, 'ozet') || str_contains($normalized, 'özet') || str_contains($normalized, 'toplam'));

        if ($wantsSummaryAll && $matched->isEmpty()) {
            return $this->buildAllProjectsSummary($user, $projects);
        }

        if ($matched->isEmpty()) {
            return $this->response(
                "Proje eşleşmedi. Proje adı, slug veya türü yazın (ör. diplomasi360, kademe+, pergel).\n\n" . self::HELP_TEXT,
                'no_match',
                null,
                null,
                null,
            );
        }

        $selectedProjects = $matched->take($wantsComparison || $wantsFinancial ? 5 : 1)->values();
        /** @var Project $project */
        $project = $selectedProjects->first();

        if ($wantsFinancial) {
            return $this->buildFinancialSummary($user, $selectedProjects, $fromDate, $toDate, $dateLabel);
        }

        if ($wantsApplications) {
            return $this->buildApplicationStats($user, $project, $applicationStatusFilter, $fromDate, $toDate, $dateLabel);
        }

        if ($wantsList) {
            return $this->buildParticipantList($user, $project, $participantStatusFilter, $limit);
        }

        if ($wantsComparison && $selectedProjects->count() > 1) {
            return $this->buildProjectComparisonSummary($user, $selectedProjects, $fromDate, $toDate, $dateLabel);
        }

        return $this->buildParticipantStats($user, $project, $participantStatusFilter);
    }

    private function manageableProjects(User $user): Collection
    {
        if ($user->role === 'super_admin') {
            return Project::query()->where('status', 'active')->orderBy('name')->get();
        }

        $ids = $this->permissionResolver->manageableProjectIdsForUser($user);
        if ($ids === []) {
            return collect();
        }

        return Project::query()
            ->where('status', 'active')
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get();
    }

    private function normalize(string $message): string
    {
        $t = mb_strtolower(trim($message), 'UTF-8');
        $t = str_replace(['ı', 'ğ', 'ü', 'ş', 'ö', 'ç', 'İ'], ['i', 'g', 'u', 's', 'o', 'c', 'i'], $t);
        $t = preg_replace('/\s+/u', ' ', $t) ?? '';

        return $t;
    }

    private function isHelpIntent(string $n): bool
    {
        return str_contains($n, 'yardim')
            || str_contains($n, 'yardım')
            || str_contains($n, 'help')
            || str_contains($n, 'ne yap')
            || str_contains($n, 'neler')
            || $n === '?';
    }

    private function wantsParticipantList(string $n): bool
    {
        return str_contains($n, 'liste')
            || str_contains($n, 'listele')
            || str_contains($n, 'bilgi')
            || str_contains($n, 'kimler')
            || str_contains($n, 'detay')
            || str_contains($n, 'tablo');
    }

    private function wantsProjectComparison(string $normalized): bool
    {
        return str_contains($normalized, 'karsilastir')
            || str_contains($normalized, 'karşılaştır')
            || str_contains($normalized, 'vs')
            || str_contains($normalized, ' ve ');
    }

    private function wantsFinancialSummary(string $normalized): bool
    {
        return str_contains($normalized, 'mali')
            || str_contains($normalized, 'finans')
            || str_contains($normalized, 'harcama')
            || str_contains($normalized, 'odeme')
            || str_contains($normalized, 'ödeme')
            || str_contains($normalized, 'gider')
            || str_contains($normalized, 'tutar');
    }

    private function extractLimit(string $normalized): int
    {
        $matches = [];
        if (preg_match('/(?:limit|ilk)\s*:?\s*(\d{1,4})/u', $normalized, $matches) === 1) {
            $parsed = (int) ($matches[1] ?? 0);
            if ($parsed > 0) {
                return min($parsed, self::EXPORT_ROW_CAP);
            }
        }

        return self::EXPORT_ROW_CAP;
    }

    private function extractApplicationStatusFilter(string $normalized): ?string
    {
        $map = [
            'pending' => ['pending', 'beklemede'],
            'accepted' => ['accepted', 'kabul', 'kabul edildi'],
            'rejected' => ['rejected', 'red', 'reddedildi'],
            'waitlisted' => ['waitlisted', 'yedek', 'yedek listede'],
            'interview_planned' => ['interview_planned', 'mulakat planlandi', 'mülakat planlandı'],
            'interview_passed' => ['interview_passed', 'mulakat gecti', 'mülakat geçti'],
            'interview_failed' => ['interview_failed', 'mulakat olumsuz', 'mülakat olumsuz'],
        ];

        foreach ($map as $status => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($normalized, $this->normalize($keyword))) {
                    return $status;
                }
            }
        }

        return null;
    }

    private function extractParticipantStatusFilter(string $normalized): ?string
    {
        $map = [
            'active' => ['active', 'aktif'],
            'inactive' => ['inactive', 'pasif'],
            'pending' => ['pending', 'beklemede'],
            'completed' => ['completed', 'tamamlandi', 'tamamlandı'],
        ];

        foreach ($map as $status => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($normalized, $this->normalize($keyword))) {
                    return $status;
                }
            }
        }

        return null;
    }

    /**
     * @return array{0: Carbon|null, 1: Carbon|null, 2: string|null}
     */
    private function extractDateRange(string $normalized): array
    {
        if (str_contains($normalized, 'bu ay')) {
            return [now()->startOfMonth(), now()->endOfMonth(), 'bu ay'];
        }

        $matches = [];
        if (preg_match('/son\s+(\d{1,3})\s+gun/u', $normalized, $matches) === 1) {
            $days = max(1, min(365, (int) ($matches[1] ?? 30)));
            return [now()->subDays($days)->startOfDay(), now()->endOfDay(), "son {$days} gun"];
        }

        if (
            preg_match(
                '/(\d{4}-\d{2}-\d{2})\s*(?:-|–|—|to|ile)\s*(\d{4}-\d{2}-\d{2})/u',
                $normalized,
                $matches
            ) === 1
        ) {
            try {
                $from = Carbon::parse((string) ($matches[1] ?? ''))->startOfDay();
                $to = Carbon::parse((string) ($matches[2] ?? ''))->endOfDay();
                if ($from->lte($to)) {
                    return [$from, $to, $from->format('Y-m-d') . ' - ' . $to->format('Y-m-d')];
                }
            } catch (\Throwable) {
                return [null, null, null];
            }
        }

        return [null, null, null];
    }

    private function matchProjects(string $normalized, Collection $projects): Collection
    {
        $scored = [];

        foreach ($projects as $project) {
            $score = 0;
            $slug = $this->normalize($project->slug);
            $type = $this->normalize((string) $project->type);
            $name = $this->normalize($project->name);
            $nameCompact = str_replace([' ', '-', '+'], '', $name);

            if ($slug !== '' && str_contains($normalized, $slug)) {
                $score += 10;
            }
            if ($type !== '' && str_contains($normalized, $type)) {
                $score += 8;
            }
            if ($name !== '' && str_contains($normalized, $name)) {
                $score += 7;
            }
            if ($nameCompact !== '' && str_contains(str_replace([' ', '-'], '', $normalized), $nameCompact)) {
                $score += 5;
            }

            foreach ($this->aliasesForType((string) $project->type) as $alias) {
                $a = $this->normalize($alias);
                if ($a !== '' && str_contains($normalized, $a)) {
                    $score += 6;
                }
            }

            if ($score > 0) {
                $scored[$project->id] = ['project' => $project, 'score' => $score];
            }
        }

        if ($scored === []) {
            return collect();
        }

        uasort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return collect($scored)->pluck('project')->take(8);
    }

    private function aliasesForType(string $type): array
    {
        $map = [
            'diplomasi360' => ['diplomasi360', 'diplomasi 360', 'diplomasi'],
            'kademe_plus' => ['kademe+', 'kademe plus', 'kademeplus', 'kademe arti'],
            'pergel_fellowship' => ['pergel', 'fellowship'],
            'eurodesk' => ['eurodesk'],
            'kpd' => ['kpd', 'psikolojik', 'danismanlik', 'danışmanlık'],
            'zirve_kademe' => ['zirve'],
        ];

        return $map[$type] ?? [];
    }

    private function buildParticipantStats(User $user, Project $project, ?string $statusFilter = null): array
    {
        $base = Participant::query()->where('project_id', $project->id);
        if ($statusFilter !== null) {
            $base->where('status', $statusFilter);
        }

        $total = (clone $base)->count();
        $active = (clone $base)->where('status', 'active')->count();
        $graduated = (clone $base)->where('graduation_status', 'graduated')->count();

        $reply = sprintf(
            "**%s** katılımcı özeti%s:\n- Toplam kayıt: %d\n- Aktif: %d\n- Mezun (işaretli): %d\n\nListe için: \"… katılımcı listesi\" yazın.",
            $project->name,
            $statusFilter ? " (durum filtresi: {$statusFilter})" : '',
            $total,
            $active,
            $graduated,
        );

        $table = [
            'columns' => ['Durum', 'Adet'],
            'rows' => [
                ['Toplam', (string) $total],
                ['Aktif', (string) $active],
                ['Mezun', (string) $graduated],
            ],
        ];

        $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'ozet_' . $project->slug);

        return $this->response($reply, 'participant_stats', $table, null, $token);
    }

    private function buildParticipantList(User $user, Project $project, ?string $statusFilter = null, int $limit = self::EXPORT_ROW_CAP): array
    {
        $query = Participant::query()
            ->where('project_id', $project->id)
            ->with(['user:id,name,surname,email,phone,university,department', 'period:id,name'])
            ->orderByDesc('updated_at')
            ->limit($limit);

        if ($statusFilter !== null) {
            $query->where('status', $statusFilter);
        }

        $rows = $query->get();

        $tableRows = [];
        foreach ($rows as $p) {
            $u = $p->user;
            $tableRows[] = [
                $u ? trim(($u->name ?? '') . ' ' . ($u->surname ?? '')) : '-',
                $u?->email ?? '-',
                $u?->phone ?? '-',
                $u?->university ?? '-',
                $u?->department ?? '-',
                (string) $p->status,
                (string) ($p->credit ?? 0),
                $p->period?->name ?? '-',
            ];
        }

        $table = [
            'columns' => ['Ad Soyad', 'E-posta', 'Telefon', 'Üniversite', 'Bölüm', 'Durum', 'Kredi', 'Dönem'],
            'rows' => $tableRows,
        ];

        $reply = sprintf(
            "**%s** — en güncel %d katılım kaydı listelendi (üst sınır %d)%s. CSV indirmek için düğmeyi kullanın.",
            $project->name,
            count($tableRows),
            $limit,
            $statusFilter ? ", durum: {$statusFilter}" : '',
        );

        $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'katilimcilar_' . $project->slug);

        return $this->response($reply, 'participant_list', $table, null, $token);
    }

    private function buildApplicationStats(
        User $user,
        Project $project,
        ?string $statusFilter = null,
        ?Carbon $fromDate = null,
        ?Carbon $toDate = null,
        ?string $dateLabel = null,
    ): array
    {
        $base = Application::query()->where('project_id', $project->id);
        if ($statusFilter !== null) {
            $base->where('status', $statusFilter);
        }
        if ($fromDate !== null && $toDate !== null) {
            $base->whereBetween('created_at', [$fromDate, $toDate]);
        }

        $byStatus = (clone $base)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $labelSuffix = '';
        if ($statusFilter !== null) {
            $labelSuffix .= "durum: {$statusFilter}";
        }
        if ($dateLabel !== null) {
            $labelSuffix .= ($labelSuffix !== '' ? ', ' : '') . "tarih: {$dateLabel}";
        }
        $lines = ["**{$project->name}** başvuru durumları" . ($labelSuffix !== '' ? " ({$labelSuffix})" : '') . ":"];
        $tableRows = [];
        foreach ($byStatus as $status => $count) {
            $lines[] = sprintf('- %s: %d', $status, $count);
            $tableRows[] = [(string) $status, (string) $count];
        }
        if ($byStatus->isEmpty()) {
            $lines[] = 'Bu proje için başvuru kaydı yok.';
        }

        $table = [
            'columns' => ['Durum', 'Adet'],
            'rows' => $tableRows,
        ];

        $exportToken = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'basvurular_' . $project->slug);

        return $this->response(implode("\n", $lines), 'application_stats', $table, null, $exportToken);
    }

    private function buildFinancialSummary(
        User $user,
        Collection $projects,
        ?Carbon $fromDate = null,
        ?Carbon $toDate = null,
        ?string $dateLabel = null,
    ): array {
        $rows = [];

        foreach ($projects as $project) {
            $query = FinancialTransaction::query()->where('project_id', $project->id);

            if ($fromDate !== null && $toDate !== null) {
                $query->whereBetween('submitted_at', [$fromDate, $toDate]);
            }

            $pending = (clone $query)->where('status', 'pending')->sum('amount');
            $approved = (clone $query)->where('status', 'approved')->sum('amount');
            $paid = (clone $query)->where('status', 'paid')->sum('amount');
            $rejected = (clone $query)->where('status', 'rejected')->sum('amount');
            $total = (clone $query)->sum('amount');

            $rows[] = [
                (string) $project->name,
                number_format((float) $pending, 2, ',', '.'),
                number_format((float) $approved, 2, ',', '.'),
                number_format((float) $paid, 2, ',', '.'),
                number_format((float) $rejected, 2, ',', '.'),
                number_format((float) $total, 2, ',', '.'),
            ];
        }

        $table = [
            'columns' => ['Proje', 'Pending Tutar', 'Approved Tutar', 'Paid Tutar', 'Rejected Tutar', 'Toplam'],
            'rows' => $rows,
        ];

        $reply = 'Mali ozet tablosu hazirlandi';
        if ($dateLabel !== null) {
            $reply .= " ({$dateLabel})";
        }
        $reply .= '. CSV olarak indirebilirsiniz.';

        $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'mali_ozet');

        return $this->response($reply, 'financial_summary', $table, null, $token);
    }

    private function buildProjectComparisonSummary(
        User $user,
        Collection $projects,
        ?Carbon $fromDate = null,
        ?Carbon $toDate = null,
        ?string $dateLabel = null,
    ): array {
        $rows = [];

        foreach ($projects as $project) {
            $applications = Application::query()->where('project_id', $project->id);
            if ($fromDate !== null && $toDate !== null) {
                $applications->whereBetween('created_at', [$fromDate, $toDate]);
            }

            $rows[] = [
                (string) $project->name,
                (string) Participant::query()->where('project_id', $project->id)->where('status', 'active')->count(),
                (string) Participant::query()->where('project_id', $project->id)->count(),
                (string) (clone $applications)->count(),
                (string) (clone $applications)->where('status', 'pending')->count(),
                (string) (clone $applications)->where('status', 'accepted')->count(),
            ];
        }

        $table = [
            'columns' => ['Proje', 'Aktif Katilimci', 'Toplam Katilimci', 'Basvuru', 'Pending', 'Accepted'],
            'rows' => $rows,
        ];

        $reply = 'Proje karsilastirma tablosu hazirlandi';
        if ($dateLabel !== null) {
            $reply .= " ({$dateLabel})";
        }
        $reply .= '.';

        $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'proje_karsilastirma');

        return $this->response($reply, 'project_comparison', $table, null, $token);
    }

    private function buildAllProjectsSummary(User $user, Collection $projects): array
    {
        $tableRows = [];
        foreach ($projects as $project) {
            $active = Participant::query()
                ->where('project_id', $project->id)
                ->where('status', 'active')
                ->count();
            $total = Participant::query()->where('project_id', $project->id)->count();
            $tableRows[] = [$project->name, (string) $project->type, (string) $active, (string) $total];
        }

        $table = [
            'columns' => ['Proje', 'Tür', 'Aktif katılımcı', 'Toplam kayıt'],
            'rows' => $tableRows,
        ];

        $reply = $user->role === 'super_admin'
            ? 'Tüm aktif projeler için özet tablo hazırlandı.'
            : 'Erişim kapsamınızdaki aktif projeler için özet tablo hazırlandı.';

        $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'proje_ozet');

        return $this->response($reply . "\n\nCSV olarak indirebilirsiniz.", 'all_summary', $table, null, $token);
    }

    private function storeExportPayload(User $user, array $columns, array $rows, string $filenameBase): string
    {
        $token = Str::random(48);
        Cache::put(
            $this->exportCacheKey($token),
            [
                'user_id' => $user->id,
                'headings' => $columns,
                'rows' => $rows,
                'filename' => $filenameBase . '_' . now()->format('Ymd_His'),
            ],
            now()->addMinutes(15),
        );

        return $token;
    }

    public function exportCacheKey(string $token): string
    {
        return 'admin_chatbot_export:' . $token;
    }

    public function takeExportPayload(string $token): ?array
    {
        $key = $this->exportCacheKey($token);
        $payload = Cache::get($key);
        if (! is_array($payload)) {
            return null;
        }
        Cache::forget($key);

        return $payload;
    }

    private function response(
        string $reply,
        string $intent,
        ?array $table,
        ?array $stats,
        ?string $exportToken,
    ): array {
        return [
            'reply' => $reply,
            'intent' => $intent,
            'table' => $table,
            'stats' => $stats,
            'export_token' => $exportToken,
            'export_available' => $exportToken !== null,
        ];
    }
}
