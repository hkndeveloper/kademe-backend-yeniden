<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
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

        /** @var Project $project */
        $project = $matched->first();

        if ($wantsApplications) {
            return $this->buildApplicationStats($user, $project);
        }

        if ($wantsList) {
            return $this->buildParticipantList($user, $project);
        }

        return $this->buildParticipantStats($user, $project);
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

        return collect($scored)->pluck('project')->take(1);
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

    private function buildParticipantStats(User $user, Project $project): array
    {
        $base = Participant::query()->where('project_id', $project->id);
        $total = (clone $base)->count();
        $active = (clone $base)->where('status', 'active')->count();
        $graduated = (clone $base)->where('graduation_status', 'graduated')->count();

        $reply = sprintf(
            "**%s** katılımcı özeti:\n- Toplam kayıt: %d\n- Aktif: %d\n- Mezun (işaretli): %d\n\nListe için: \"… katılımcı listesi\" yazın.",
            $project->name,
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

    private function buildParticipantList(User $user, Project $project): array
    {
        $rows = Participant::query()
            ->where('project_id', $project->id)
            ->with(['user:id,name,surname,email,phone,university,department', 'period:id,name'])
            ->orderByDesc('updated_at')
            ->limit(self::EXPORT_ROW_CAP)
            ->get();

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
            "**%s** — en güncel %d katılım kaydı listelendi (üst sınır %d). CSV indirmek için düğmeyi kullanın.",
            $project->name,
            count($tableRows),
            self::EXPORT_ROW_CAP,
        );

        $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'katilimcilar_' . $project->slug);

        return $this->response($reply, 'participant_list', $table, null, $token);
    }

    private function buildApplicationStats(User $user, Project $project): array
    {
        $base = Application::query()->where('project_id', $project->id);
        $byStatus = (clone $base)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $lines = ["**{$project->name}** başvuru durumları:"];
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

        $exportToken = $tableRows !== []
            ? $this->storeExportPayload($user, $table['columns'], $table['rows'], 'basvurular_' . $project->slug)
            : null;

        return $this->response(implode("\n", $lines), 'application_stats', $table, null, $exportToken);
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
