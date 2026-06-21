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
Veri asistani kural tabanlidir: mesajinizda yonetebildiginiz bir proje adi/slug/turu gecmeli (Diplomasi360, KADEME+, Pergel, Eurodesk, KPD, Zirve vb.).

Katilimci:
• "… ozet" / "… aktif sayi" / "… katilimci sayilari" — durum ve mezuniyet kirilimi tablosu
• "… katilimci listesi" / "liste" / "csv" / "ilk 100" — tablo + CSV (ust sinir 500)
• Durum filtresi: aktif, pasif, mezun (kayit), basarisiz, yedek / bekleme listesi
• Mezuniyet filtresi: tamamladi, mezuniyet tamamlandi, tamamlayamadi, kisa program

Donem:
• "… donem dagilimi" / "donem bazinda sayilar" — donem ve kayit durumuna gore adetler

Basvuru:
• "… basvuru ozeti" — durumlara gore sayim (tarih araligi opsiyonel)
• "… basvuru listesi" — son basvurular tablosu + CSV

Mali (financial.view + proje erisimi):
• "… mali ozet" / "harcama" / "odeme" / "butce" — pending/approved/paid/rejected toplamlari
• Birden fazla proje: "diplomasi360 ve pergel mali ozet son 30 gun"

Kredi (katilimci goruntuleme yetkisi):
• "… kredi ozeti" / "ortalama kredi" / "dusuk kredi" — ortalama, min, max, dusuk kredi sayisi

Karsilastirma:
• Iki veya daha cok proje adi + "karsilastir" / "vs" / " ve " + ozet — aktif/toplam katilimci ve basvuru sayilari

Tarih (basvurular ve mali islemler icin):
• son 7 gun, son 30 gun, son 90 gun, bu hafta, bu ay, gecen ay, bugun, dun
• 2026-01-01 - 2026-01-31

Genel:
• "Tum projeler ozet" / "genel ozet" — erisim kapsaminizdaki tum aktif projeler

Cikti tablolari CSV ile indirilebilir. Tam dogal dil yoktur; anahtar kelime + proje eslesmesi kullanilir.
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
        $wantsApplications = str_contains($normalized, 'basvuru') || str_contains($normalized, 'application');
        $wantsApplicationList = $wantsApplications && $wantsList;
        $wantsFinancial = $this->wantsFinancialSummary($normalized);
        $limit = $this->extractLimit($normalized);
        $applicationStatusFilter = $this->extractApplicationStatusFilter($normalized);
        $participantStatusFilter = $this->extractParticipantStatusFilter($normalized);
        $graduationStatusFilter = $this->extractGraduationStatusFilter($normalized);
        [$fromDate, $toDate, $dateLabel] = $this->extractDateRange($normalized);
        $wantsComparison = $this->wantsProjectComparison($normalized);
        $wantsPeriodBreakdown = $this->wantsPeriodBreakdownIntent($normalized);
        $wantsCreditSummary = $this->wantsCreditSummaryIntent($normalized);
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
            $selectedProjects = $this->filterProjectsForPermission($user, $selectedProjects, 'financial.view');
            if ($selectedProjects->isEmpty()) {
                return $this->permissionDeniedResponse('financial.view');
            }

            return $this->buildFinancialSummary($user, $selectedProjects, $fromDate, $toDate, $dateLabel);
        }

        if ($wantsApplicationList) {
            if (! $this->permissionResolver->canAccessProject($user, 'applications.view', $project->id)) {
                return $this->permissionDeniedResponse('applications.view');
            }

            return $this->buildApplicationList(
                $user,
                $project,
                $applicationStatusFilter,
                $fromDate,
                $toDate,
                $dateLabel,
                $limit,
            );
        }

        if ($wantsApplications) {
            if (! $this->permissionResolver->canAccessProject($user, 'applications.view', $project->id)) {
                return $this->permissionDeniedResponse('applications.view');
            }

            return $this->buildApplicationStats($user, $project, $applicationStatusFilter, $fromDate, $toDate, $dateLabel);
        }

        if ($wantsPeriodBreakdown) {
            if (! $this->permissionResolver->canAccessProject($user, 'projects.participants.view', $project->id)) {
                return $this->permissionDeniedResponse('projects.participants.view');
            }

            return $this->buildParticipantPeriodBreakdown($user, $project, $participantStatusFilter);
        }

        if ($wantsCreditSummary) {
            if (! $this->permissionResolver->canAccessProject($user, 'projects.participants.view', $project->id)) {
                return $this->permissionDeniedResponse('projects.participants.view');
            }

            return $this->buildCreditSummary($user, $project, $participantStatusFilter);
        }

        if ($wantsList) {
            if (! $this->permissionResolver->canAccessProject($user, 'projects.participants.view', $project->id)) {
                return $this->permissionDeniedResponse('projects.participants.view');
            }

            return $this->buildParticipantList(
                $user,
                $project,
                $participantStatusFilter,
                $graduationStatusFilter,
                $limit,
            );
        }

        if ($wantsComparison && $selectedProjects->count() > 1) {
            return $this->buildProjectComparisonSummary($user, $selectedProjects, $fromDate, $toDate, $dateLabel);
        }

        return $this->buildParticipantStats($user, $project, $participantStatusFilter, $graduationStatusFilter);
    }

    private function manageableProjects(User $user): Collection
    {
        if ($this->permissionResolver->hasGlobalScope($user, 'projects.view')) {
            return Project::query()->where('status', 'active')->orderBy('name')->get();
        }

        $ids = $this->permissionResolver->projectIdsForPermission($user, 'projects.view');
        if ($ids === []) {
            return collect();
        }

        return Project::query()
            ->where('status', 'active')
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get();
    }

    private function filterProjectsForPermission(User $user, Collection $projects, string $permission): Collection
    {
        return $projects
            ->filter(fn (Project $project) => $this->permissionResolver->canAccessProject($user, $permission, $project->id))
            ->values();
    }

    private function permissionDeniedResponse(string $permission): array
    {
        return $this->response(
            "Bu veri icin gerekli yetki bulunmuyor: {$permission}.",
            'permission_denied',
            null,
            null,
            null,
        );
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
            || str_contains($n, 'komut')
            || str_contains($n, 'ornek')
            || str_contains($n, 'ornekler')
            || str_contains($n, 'nasil')
            || $n === '?';
    }

    private function wantsParticipantList(string $n): bool
    {
        return str_contains($n, 'liste')
            || str_contains($n, 'listele')
            || str_contains($n, 'bilgi')
            || str_contains($n, 'kimler')
            || str_contains($n, 'detay')
            || str_contains($n, 'tablo')
            || str_contains($n, 'excel')
            || str_contains($n, 'csv')
            || str_contains($n, 'satir')
            || str_contains($n, 'isimleri')
            || str_contains($n, 'adlari');
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
            || str_contains($normalized, 'tutar')
            || str_contains($normalized, 'butce')
            || str_contains($normalized, 'bütce')
            || str_contains($normalized, 'masraf')
            || str_contains($normalized, 'gelir');
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
            'pending' => ['pending', 'beklemede', 'bekleyen'],
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
            'passive' => ['passive', 'pasif', 'inactive', 'inaktif'],
            'graduated' => ['graduated', 'mezun kayit', 'kayit mezun'],
            'failed' => ['failed', 'basarisiz', 'olumsuz', 'basarisizlik'],
            'waitlist' => ['waitlist', 'yedek', 'bekleme listesi', 'bekleme'],
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

    private function extractGraduationStatusFilter(string $normalized): ?string
    {
        $map = [
            'completed' => ['completed', 'tamamladi', 'tamamlandi', 'kisa program', 'kisaprogram', 'programi tamamladi'],
            'graduated' => ['graduation_status', 'mezuniyet tamamlandi', 'diploma alan', 'mezuniyet durumu'],
            'not_completed' => ['not_completed', 'tamamlayamadi', 'tamamlayamadı', 'yarida kaldi', 'yarıda kaldı'],
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

    private function wantsPeriodBreakdownIntent(string $normalized): bool
    {
        if (! str_contains($normalized, 'donem')) {
            return false;
        }

        return str_contains($normalized, 'dagilim')
            || str_contains($normalized, 'kirilim')
            || str_contains($normalized, 'bazinda')
            || str_contains($normalized, 'sayilari')
            || str_contains($normalized, 'sayisi')
            || str_contains($normalized, 'tablo')
            || str_contains($normalized, 'istatistik')
            || str_contains($normalized, 'rapor');
    }

    private function wantsCreditSummaryIntent(string $normalized): bool
    {
        if (! str_contains($normalized, 'kredi') && ! str_contains($normalized, 'puantaj')) {
            return false;
        }

        return str_contains($normalized, 'ozet')
            || str_contains($normalized, 'ortalama')
            || str_contains($normalized, 'dagilim')
            || str_contains($normalized, 'kirilim')
            || str_contains($normalized, 'istatistik')
            || str_contains($normalized, 'durum')
            || str_contains($normalized, 'tablo')
            || str_contains($normalized, 'minimum')
            || str_contains($normalized, 'maksimum')
            || str_contains($normalized, 'en dusuk')
            || str_contains($normalized, 'dusuk');
    }

    /**
     * @return array{0: Carbon|null, 1: Carbon|null, 2: string|null}
     */
    private function extractDateRange(string $normalized): array
    {
        if (str_contains($normalized, 'bu ay')) {
            return [now()->startOfMonth(), now()->endOfMonth(), 'bu ay'];
        }

        if (str_contains($normalized, 'bu hafta')) {
            return [now()->startOfWeek(), now()->endOfWeek(), 'bu hafta'];
        }

        if (str_contains($normalized, 'gecen ay')) {
            $ref = now()->subMonth();

            return [$ref->copy()->startOfMonth(), $ref->copy()->endOfMonth(), 'gecen ay'];
        }

        if (str_contains($normalized, 'bugun')) {
            return [now()->startOfDay(), now()->endOfDay(), 'bugun'];
        }

        if (str_contains($normalized, 'dun')) {
            return [
                now()->subDay()->startOfDay(),
                now()->subDay()->endOfDay(),
                'dun',
            ];
        }

        if (preg_match('/son\s+90\s+gun/u', $normalized) === 1 || str_contains($normalized, 'son 90')) {
            return [now()->subDays(90)->startOfDay(), now()->endOfDay(), 'son 90 gun'];
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

    private function buildParticipantStats(
        User $user,
        Project $project,
        ?string $statusFilter = null,
        ?string $graduationStatusFilter = null,
    ): array {
        $base = Participant::query()->where('project_id', $project->id);
        if ($statusFilter !== null) {
            $base->where('status', $statusFilter);
        }
        if ($graduationStatusFilter !== null) {
            $base->where('graduation_status', $graduationStatusFilter);
        }

        $total = (clone $base)->count();

        $byStatus = (clone $base)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $byGraduationRows = (clone $base)
            ->selectRaw('graduation_status, count(*) as c')
            ->groupBy('graduation_status')
            ->get();

        $active = (int) ($byStatus['active'] ?? 0);
        $graduationMarked = 0;
        foreach ($byGraduationRows as $row) {
            if ($row->graduation_status === 'graduated') {
                $graduationMarked = (int) $row->c;
                break;
            }
        }

        $filterNote = [];
        if ($statusFilter !== null) {
            $filterNote[] = "kayit durumu: {$statusFilter}";
        }
        if ($graduationStatusFilter !== null) {
            $filterNote[] = "mezuniyet: {$graduationStatusFilter}";
        }
        $suffix = $filterNote !== [] ? ' (' . implode(', ', $filterNote) . ')' : '';

        $reply = sprintf(
            "**%s** katilim ozeti%s:\n- Toplam kayit: %d\n- Aktif (kayit durumu): %d\n- Mezuniyet alani \"graduated\" sayisi: %d\n\nDetay tabloda kayit ve mezuniyet kirilimi var. Liste icin: \"… katilimci listesi\".",
            $project->name,
            $suffix,
            $total,
            $active,
            $graduationMarked,
        );

        $tableRows = [
            ['Toplam kayit', (string) $total],
            ['Aktif', (string) $active],
        ];
        foreach (['active', 'passive', 'graduated', 'failed', 'waitlist'] as $st) {
            $c = (int) ($byStatus[$st] ?? 0);
            $tableRows[] = ["Kayit: {$st}", (string) $c];
        }
        foreach ($byGraduationRows as $row) {
            $label = $row->graduation_status === null ? '(bos)' : (string) $row->graduation_status;
            $tableRows[] = ["Mezuniyet: {$label}", (string) $row->c];
        }

        $table = [
            'columns' => ['Olcum', 'Adet'],
            'rows' => $tableRows,
        ];

        $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'ozet_' . $project->slug);

        return $this->response($reply, 'participant_stats', $table, null, $token);
    }

    private function buildParticipantList(
        User $user,
        Project $project,
        ?string $statusFilter = null,
        ?string $graduationStatusFilter = null,
        int $limit = self::EXPORT_ROW_CAP,
    ): array {
        $query = Participant::query()
            ->where('project_id', $project->id)
            ->with(['user:id,name,surname,email,phone,university,department', 'period:id,name'])
            ->orderByDesc('updated_at')
            ->limit($limit);

        if ($statusFilter !== null) {
            $query->where('status', $statusFilter);
        }
        if ($graduationStatusFilter !== null) {
            $query->where('graduation_status', $graduationStatusFilter);
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
                $p->graduation_status !== null ? (string) $p->graduation_status : '-',
                (string) ($p->credit ?? 0),
                $p->period?->name ?? '-',
            ];
        }

        $table = [
            'columns' => ['Ad Soyad', 'E-posta', 'Telefon', 'Üniversite', 'Bölüm', 'Kayit durumu', 'Mezuniyet', 'Kredi', 'Dönem'],
            'rows' => $tableRows,
        ];

        $bits = [];
        if ($statusFilter !== null) {
            $bits[] = "kayit: {$statusFilter}";
        }
        if ($graduationStatusFilter !== null) {
            $bits[] = "mezuniyet: {$graduationStatusFilter}";
        }
        $filterSuffix = $bits !== [] ? ' (' . implode(', ', $bits) . ')' : '';

        $reply = sprintf(
            "**%s** — en guncel %d katilim kaydi listelendi (ust sinir %d)%s. CSV indirmek icin dugmeyi kullanin.",
            $project->name,
            count($tableRows),
            $limit,
            $filterSuffix,
        );

        $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'katilimcilar_' . $project->slug);

        return $this->response($reply, 'participant_list', $table, null, $token);
    }

    private function buildParticipantPeriodBreakdown(User $user, Project $project, ?string $statusFilter = null): array
    {
        $query = Participant::query()
            ->where('participants.project_id', $project->id)
            ->join('periods', 'periods.id', '=', 'participants.period_id')
            ->selectRaw('periods.name as period_name, participants.status, count(*) as c')
            ->groupBy('periods.name', 'participants.status')
            ->orderBy('periods.name');

        if ($statusFilter !== null) {
            $query->where('participants.status', $statusFilter);
        }

        $tableRows = [];
        foreach ($query->get() as $row) {
            $tableRows[] = [
                (string) $row->period_name,
                (string) $row->status,
                (string) $row->c,
            ];
        }

        $table = [
            'columns' => ['Donem', 'Kayit durumu', 'Adet'],
            'rows' => $tableRows,
        ];

        $reply = sprintf(
            "**%s** donem bazinda katilim dagilimi hazirlandi%s.",
            $project->name,
            $statusFilter ? " (yalnizca kayit durumu: {$statusFilter})" : '',
        );

        $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'donem_dagilim_' . $project->slug);

        return $this->response($reply, 'participant_period_breakdown', $table, null, $token);
    }

    private function buildCreditSummary(User $user, Project $project, ?string $statusFilter = null): array
    {
        $base = Participant::query()->where('project_id', $project->id);
        if ($statusFilter !== null) {
            $base->where('status', $statusFilter);
        }

        $count = (clone $base)->count();
        $avg = $count > 0 ? round((float) ((clone $base)->avg('credit')), 2) : 0.0;
        $min = (int) ((clone $base)->min('credit') ?? 0);
        $max = (int) ((clone $base)->max('credit') ?? 0);
        $threshold = 50;
        $low = (clone $base)->where('credit', '<', $threshold)->count();

        $reply = sprintf(
            "**%s** kredi ozeti%s:\n- Kayit sayisi: %d\n- Ortalama kredi: %s\n- Min / max: %d / %d\n- %d alti kredi kaydi: %d",
            $project->name,
            $statusFilter ? " (kayit durumu: {$statusFilter})" : '',
            $count,
            number_format($avg, 2, ',', '.'),
            $min,
            $max,
            $threshold,
            $low,
        );

        $table = [
            'columns' => ['Metrik', 'Deger'],
            'rows' => [
                ['Kayit sayisi', (string) $count],
                ['Ortalama kredi', number_format($avg, 2, ',', '.')],
                ['Minimum', (string) $min],
                ['Maksimum', (string) $max],
                ["Kredi < {$threshold}", (string) $low],
            ],
        ];

        $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'kredi_ozet_' . $project->slug);

        return $this->response($reply, 'credit_summary', $table, null, $token);
    }

    private function buildApplicationList(
        User $user,
        Project $project,
        ?string $statusFilter = null,
        ?Carbon $fromDate = null,
        ?Carbon $toDate = null,
        ?string $dateLabel = null,
        int $limit = self::EXPORT_ROW_CAP,
    ): array {
        $query = Application::query()
            ->where('project_id', $project->id)
            ->with(['user:id,name,surname,email,phone', 'period:id,name'])
            ->orderByDesc('created_at')
            ->limit($limit);

        if ($statusFilter !== null) {
            $query->where('status', $statusFilter);
        }
        if ($fromDate !== null && $toDate !== null) {
            $query->whereBetween('created_at', [$fromDate, $toDate]);
        }

        $tableRows = [];
        foreach ($query->get() as $app) {
            $u = $app->user;
            $tableRows[] = [
                $u ? trim(($u->name ?? '') . ' ' . ($u->surname ?? '')) : '-',
                $u?->email ?? '-',
                (string) $app->status,
                $app->created_at?->format('Y-m-d H:i') ?? '-',
                $app->period?->name ?? '-',
            ];
        }

        $table = [
            'columns' => ['Ad Soyad', 'E-posta', 'Durum', 'Olusturma', 'Donem'],
            'rows' => $tableRows,
        ];

        $meta = [];
        if ($statusFilter !== null) {
            $meta[] = "durum: {$statusFilter}";
        }
        if ($dateLabel !== null) {
            $meta[] = "tarih: {$dateLabel}";
        }
        $suffix = $meta !== [] ? ' (' . implode(', ', $meta) . ')' : '';

        $reply = sprintf(
            "**%s** basvuru listesi — son %d kayit (ust sinir %d)%s.",
            $project->name,
            count($tableRows),
            $limit,
            $suffix,
        );

        $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'basvurular_liste_' . $project->slug);

        return $this->response($reply, 'application_list', $table, null, $token);
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

        $reply = $this->permissionResolver->hasGlobalScope($user, 'projects.view')
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
