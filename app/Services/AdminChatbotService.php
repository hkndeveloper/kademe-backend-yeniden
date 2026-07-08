<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\Application;
use App\Models\Assignment;
use App\Models\Certificate;
use App\Models\DigitalBohca;
use App\Models\FinancialTransaction;
use App\Models\Internship;
use App\Models\Mentor;
use App\Models\EurodeskProject;
use App\Models\RewardAward;
use App\Models\RewardTier;
use App\Models\ProjectModule;
use App\Models\ProjectModuleEnrollment;
use App\Models\Participant;
use App\Models\Period;
use App\Models\Program;
use App\Models\Project;
use App\Models\Request as ServiceRequest;
use App\Models\StaffProfile;
use App\Models\SupportTicket;
use App\Models\Trainer;
use App\Models\User;
use App\Models\VolunteerOpportunity;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

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
Veri asistanı kural tabanlıdır. Ham SQL üretmez; yalnızca tanımlı anahtar kelimelerle, action+scope ve proje erişimi kontrollerinden geçen okuma sorgularını çalıştırır.

Örnekler:
• "Diplomasi360 katılımcı özeti" / "katılımcı listesi limit 50"
• "Pergel başvuru özeti son 30 gün" / "başvuru listesi"
• "KADEME+ program listesi" / "program özeti" / "yoklama"
• "Eurodesk mali özet son 90 gün"
• "Diplomasi360 dijital bohça listesi"
• "Pergel ödev özeti"
• "KADEME+ sertifika listesi"
• "KPD özel modül özeti" / "staj mentor eurodesk rozet ödül modülleri"
• "tüm projeler özet"
• "projeleri karşılaştır"
• "eğitmen özeti" / "eğitmen listesi"
• "log özeti son 7 gün" / "başarısız loglar" / "son log listesi"
• "destek özeti" / "destek listesi"
• "talep listesi son 30 gün"
• "duyuru listesi" / "duyuru özeti"
• "dönem listesi" / "dönem özeti"
• "kullanıcı rol dağılımı" / "kullanıcı listesi"
• "personel özeti" / "personel listesi"
• "action scope mantığı"
• "özel modül yetkisi"
• "cv pdf indir"

Çıktı tabloları CSV, Excel, PDF veya Word olarak indirilebilir. Proje bazlı sorgularda hem ilgili action+scope hem de o projeye erişim aranır.
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

        $limit = $this->extractLimit($normalized);
        [$fromDate, $toDate, $dateLabel] = $this->extractDateRange($normalized);

        if ($this->wantsTrainerIntent($normalized)) {
            return $this->handleTrainerIntent($user, $normalized, $limit);
        }

        if ($this->wantsLogIntent($normalized)) {
            return $this->handleLogIntent($user, $normalized, $fromDate, $toDate, $dateLabel, $limit);
        }

        if ($this->wantsUsersIntent($normalized)) {
            return $this->handleUsersIntent($user, $normalized, $limit);
        }

        if ($this->wantsStaffIntent($normalized)) {
            return $this->handleStaffIntent($user, $normalized, $limit);
        }

        if ($this->wantsSystemGuideIntent($normalized)) {
            return $this->buildSystemGuide($normalized);
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
        $wantsPrograms = $this->wantsProgramIntent($normalized);
        $wantsVolunteer = $this->wantsVolunteerIntent($normalized);
        $wantsDigitalBohca = $this->wantsDigitalBohcaIntent($normalized);
        $wantsAssignments = $this->wantsAssignmentIntent($normalized);
        $wantsCertificates = $this->wantsCertificateIntent($normalized);
        $wantsSupport = $this->wantsSupportIntent($normalized);
        $wantsRequests = $this->wantsRequestIntent($normalized);
        $wantsAnnouncements = $this->wantsAnnouncementIntent($normalized);
        $wantsPeriods = $this->wantsPeriodIntent($normalized);
        $wantsSpecialModules = $this->wantsSpecialModuleIntent($normalized);
        $wantsSummaryAll = (str_contains($normalized, 'tum') || str_contains($normalized, 'tüm') || str_contains($normalized, 'genel'))
            && (str_contains($normalized, 'ozet') || str_contains($normalized, 'özet') || str_contains($normalized, 'toplam'));

        if ($wantsSummaryAll && $matched->isEmpty()) {
            return $this->buildAllProjectsSummary($user, $projects);
        }

        if ($wantsSupport) {
            return $this->handleSupportIntent($user, $normalized, $matched, $fromDate, $toDate, $dateLabel, $limit);
        }

        if ($wantsRequests) {
            return $this->handleRequestIntent($user, $normalized, $matched, $fromDate, $toDate, $dateLabel, $limit);
        }

        if ($wantsAnnouncements) {
            return $this->handleAnnouncementIntent($user, $normalized, $matched, $fromDate, $toDate, $dateLabel, $limit);
        }

        if ($wantsPeriods && ! $wantsPeriodBreakdown) {
            return $this->handlePeriodIntent($user, $normalized, $matched, $limit);
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

        if ($wantsPrograms) {
            if (! $this->permissionResolver->canAccessProject($user, 'programs.view', $project->id)) {
                return $this->permissionDeniedResponse('programs.view');
            }

            return $this->buildProgramSummary($user, $project, $normalized, $fromDate, $toDate, $dateLabel, $limit);
        }

        if ($wantsVolunteer) {
            if (! $this->permissionResolver->canAccessProject($user, 'volunteer.view', $project->id)) {
                return $this->permissionDeniedResponse('volunteer.view');
            }

            return $this->buildVolunteerSummary($user, $project, $normalized, $limit);
        }

        if ($wantsDigitalBohca) {
            if (! $this->permissionResolver->canAccessProject($user, 'digital_bohca.view', $project->id)) {
                return $this->permissionDeniedResponse('digital_bohca.view');
            }

            return $this->buildDigitalBohcaSummary($user, $project, $normalized, $limit);
        }

        if ($wantsAssignments) {
            if (! $this->permissionResolver->canAccessProject($user, 'assignments.view', $project->id)) {
                return $this->permissionDeniedResponse('assignments.view');
            }

            return $this->buildAssignmentSummary($user, $project, $normalized, $limit);
        }

        if ($wantsCertificates) {
            if (! $this->permissionResolver->canAccessProject($user, 'certificates.view', $project->id)) {
                return $this->permissionDeniedResponse('certificates.view');
            }

            return $this->buildCertificateSummary($user, $project, $normalized, $limit);
        }

        if ($wantsSpecialModules) {
            return $this->buildProjectSpecialModuleSummary($user, $project);
        }

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
            "**%s** — en guncel %d katilim kaydi listelendi (ust sinir %d)%s. Disa aktarmak icin dugmeyi kullanin.",
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
            $lines[] = sprintf('- %s: %d', $this->localizeEnumValue((string) $status), $count);
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
        $reply .= '. Disa aktarabilirsiniz.';

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

        return $this->response($reply . "\n\nDisa aktarabilirsiniz.", 'all_summary', $table, null, $token);
    }

    private function wantsTrainerIntent(string $n): bool
    {
        return str_contains($n, 'egitmen') || str_contains($n, 'eğitmen') || str_contains($n, 'trainer');
    }

    private function wantsLogIntent(string $n): bool
    {
        return str_contains($n, 'log') || str_contains($n, 'aktivite') || str_contains($n, 'islem gecmisi') || str_contains($n, 'işlem geçmişi');
    }

    private function wantsUsersIntent(string $n): bool
    {
        return str_contains($n, 'kullanici') || str_contains($n, 'kullanıcı') || str_contains($n, 'rol dagilim') || str_contains($n, 'rol dağılım');
    }

    private function wantsStaffIntent(string $n): bool
    {
        return str_contains($n, 'personel') || str_contains($n, 'birim uyes') || str_contains($n, 'birim üyes') || str_contains($n, 'staff');
    }

    private function wantsSystemGuideIntent(string $n): bool
    {
        return str_contains($n, 'action scope')
            || str_contains($n, 'yetki mantig')
            || str_contains($n, 'yetki mantığ')
            || str_contains($n, 'proje ozgu')
            || str_contains($n, 'proje özgü')
            || str_contains($n, 'cv pdf')
            || str_contains($n, 'cv indir')
            || str_contains($n, 'chatbot ne yapar');
    }

    private function wantsProgramIntent(string $n): bool
    {
        return str_contains($n, 'program') || str_contains($n, 'etkinlik') || str_contains($n, 'yoklama') || str_contains($n, 'feedback') || str_contains($n, 'geri bildirim');
    }

    private function wantsVolunteerIntent(string $n): bool
    {
        return str_contains($n, 'gonullu') || str_contains($n, 'gönüllü') || str_contains($n, 'volunteer');
    }

    private function wantsDigitalBohcaIntent(string $n): bool
    {
        return str_contains($n, 'bohca') || str_contains($n, 'bohça') || str_contains($n, 'dokuman') || str_contains($n, 'döküman');
    }

    private function wantsAssignmentIntent(string $n): bool
    {
        return str_contains($n, 'odev') || str_contains($n, 'ödev') || str_contains($n, 'assignment');
    }

    private function wantsCertificateIntent(string $n): bool
    {
        return str_contains($n, 'sertifika') || str_contains($n, 'certificate');
    }

    private function wantsSupportIntent(string $n): bool
    {
        return str_contains($n, 'destek') || str_contains($n, 'support') || str_contains($n, 'ticket');
    }

    private function wantsRequestIntent(string $n): bool
    {
        return str_contains($n, 'talep') || str_contains($n, 'request');
    }

    private function wantsAnnouncementIntent(string $n): bool
    {
        return str_contains($n, 'duyuru') || str_contains($n, 'mesaj') || str_contains($n, 'iletisim') || str_contains($n, 'iletişim') || str_contains($n, 'sms') || str_contains($n, 'mail');
    }

    private function wantsPeriodIntent(string $n): bool
    {
        return str_contains($n, 'donem') || str_contains($n, 'dönem');
    }

    private function wantsSpecialModuleIntent(string $n): bool
    {
        return str_contains($n, 'ozel modul') || str_contains($n, 'özel modül') || str_contains($n, 'staj') || str_contains($n, 'mentor') || str_contains($n, 'rozet') || str_contains($n, 'odul') || str_contains($n, 'ödül') || str_contains($n, 'hediye');
    }

    private function wantsTableList(string $n): bool
    {
        return $this->wantsParticipantList($n) || str_contains($n, 'son ') || str_contains($n, 'kayitlar') || str_contains($n, 'kayıtlar');
    }

    private function hasUsablePermission(User $user, string $permission): bool
    {
        if (! $this->permissionResolver->hasPermission($user, $permission)) {
            return false;
        }

        $scopeType = $this->permissionResolver->scopeFor($user, $permission)['scope_type'] ?? null;
        return ! in_array($scopeType, [null, '', 'none'], true);
    }

    private function projectIdsOrNull(User $user, string $permission): ?array
    {
        if ($this->permissionResolver->hasGlobalScope($user, $permission)) {
            return null;
        }

        return $this->permissionResolver->projectIdsForPermission($user, $permission);
    }

    private function selectedProjectIds(Collection $matched): array
    {
        return $matched->map(fn (Project $project) => (int) $project->id)->values()->all();
    }

    private function applyProjectVisibility($query, User $user, string $permission, string $column = 'project_id'): void
    {
        $ids = $this->projectIdsOrNull($user, $permission);
        if ($ids !== null) {
            $query->whereIn($column, $ids);
        }
    }

    private function applyMatchedProjects($query, Collection $matched, string $column = 'project_id'): void
    {
        $ids = $this->selectedProjectIds($matched);
        if ($ids !== []) {
            $query->whereIn($column, $ids);
        }
    }

    private function handleTrainerIntent(User $user, string $normalized, int $limit): array
    {
        if (! $this->hasUsablePermission($user, 'trainers.view')) {
            return $this->permissionDeniedResponse('trainers.view');
        }

        $query = Trainer::query()->latest('updated_at');
        foreach (['active', 'passive', 'candidate'] as $status) {
            if (str_contains($normalized, $status) || ($status === 'active' && str_contains($normalized, 'aktif')) || ($status === 'candidate' && str_contains($normalized, 'aday')) || ($status === 'passive' && str_contains($normalized, 'pasif'))) {
                $query->where('status', $status);
                break;
            }
        }

        if ($this->wantsTableList($normalized)) {
            $rows = $query->limit($limit)->get()->map(fn (Trainer $trainer) => [
                $trainer->full_name,
                $trainer->email ?? '-',
                $trainer->phone ?? '-',
                $trainer->title ?? '-',
                $trainer->organization ?? '-',
                $trainer->expertise ?? '-',
                $trainer->status,
                $trainer->last_worked_at?->format('Y-m-d') ?? '-',
                filled($trainer->kademe_comment) ? 'var' : 'yok',
            ])->all();
            $table = ['columns' => ['Ad Soyad', 'E-posta', 'Telefon', 'Unvan', 'Kurum', 'Uzmanlik', 'Durum', 'Son Calisma', 'Kademe Yorumu'], 'rows' => $rows];
            $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'egitmenler');
            return $this->response('Egitmen listesi hazirlandi. CSV, Excel, PDF veya Word olarak indirebilirsiniz.', 'trainer_list', $table, null, $token);
        }

        $table = ['columns' => ['Metrik', 'Adet'], 'rows' => [
            ['Toplam', (string) Trainer::query()->count()],
            ['Aktif', (string) Trainer::query()->where('status', 'active')->count()],
            ['Aday', (string) Trainer::query()->where('status', 'candidate')->count()],
            ['Pasif', (string) Trainer::query()->where('status', 'passive')->count()],
            ['E-posta bulunan', (string) Trainer::query()->whereNotNull('email')->where('email', '!=', '')->count()],
            ['Kademe yorumu bulunan', (string) Trainer::query()->whereNotNull('kademe_comment')->where('kademe_comment', '!=', '')->count()],
        ]];
        $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'egitmen_ozet');
        return $this->response('Egitmen ozeti hazirlandi. Bu modul proje bagimsizdir ve trainers.* izinlerinde kullanilabilir scope gerekir.', 'trainer_summary', $table, null, $token);
    }

    private function handleLogIntent(User $user, string $normalized, ?Carbon $fromDate, ?Carbon $toDate, ?string $dateLabel, int $limit): array
    {
        if (! $this->permissionResolver->hasPermission($user, 'logs.view')) {
            return $this->permissionDeniedResponse('logs.view');
        }

        try {
            $query = Activity::query()->with('causer:id,name,surname,role')->latest();
            if (! $this->permissionResolver->hasGlobalScope($user, 'logs.view')) {
                $query->where(function ($builder) use ($user) {
                    $builder->where(function ($self) use ($user) {
                        $self->where('causer_type', User::class)->where('causer_id', $user->id);
                    })->orWhere('log_name', 'permissions');
                });
            }
            if ($fromDate !== null && $toDate !== null) {
                $query->whereBetween('created_at', [$fromDate, $toDate]);
            }
            if (str_contains($normalized, 'basarisiz') || str_contains($normalized, 'başarısız') || str_contains($normalized, 'failed') || str_contains($normalized, 'hata')) {
                $query->where('properties->outcome', 'denied_or_failed');
            }

            if ($this->wantsTableList($normalized)) {
                $rows = $query->limit($limit)->get()->map(function (Activity $log) {
                    $props = $log->properties?->toArray() ?? [];
                    return [
                        (string) $log->id,
                        $log->created_at?->format('Y-m-d H:i:s') ?? '-',
                        $log->log_name ?? '-',
                        $log->event ?? $log->description ?? '-',
                        data_get($props, 'outcome', '-'),
                        (string) data_get($props, 'status_code', '-'),
                        data_get($props, 'path', '-'),
                        $log->causer ? trim(($log->causer->name ?? '') . ' ' . ($log->causer->surname ?? '')) : 'Sistem',
                    ];
                })->all();
                $table = ['columns' => ['ID', 'Tarih', 'Kaynak', 'Aksiyon', 'Sonuc', 'HTTP', 'Yol', 'Kullanici'], 'rows' => $rows];
                $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'log_listesi');
                return $this->response('Log listesi hazirlandi' . ($dateLabel ? " ({$dateLabel})" : '') . '.', 'log_list', $table, null, $token);
            }

            $total = (clone $query)->count();
            $success = (clone $query)->where('properties->outcome', 'success')->count();
            $failed = (clone $query)->where('properties->outcome', 'denied_or_failed')->count();
            $logs = (clone $query)->limit(1000)->get(['id', 'log_name', 'event', 'properties']);
            $rows = [['Toplam', (string) $total], ['Basarili', (string) $success], ['Basarisiz / engellenen', (string) $failed]];
            foreach ($logs->pluck('log_name')->filter()->countBy()->sortDesc()->take(6) as $source => $count) {
                $rows[] = ['Kaynak: ' . $source, (string) $count];
            }
            $table = ['columns' => ['Metrik', 'Adet'], 'rows' => $rows];
            $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'log_ozet');
            return $this->response('Log ozeti hazirlandi' . ($dateLabel ? " ({$dateLabel})" : '') . '.', 'log_summary', $table, null, $token);
        } catch (\Throwable) {
            return $this->response('Log kaynagi su anda okunamadi.', 'log_unavailable', null, null, null);
        }
    }

    private function handleUsersIntent(User $user, string $normalized, int $limit): array
    {
        if (! $this->hasUsablePermission($user, 'users.view')) {
            return $this->permissionDeniedResponse('users.view');
        }

        if ($this->wantsTableList($normalized)) {
            $rows = User::query()->latest('updated_at')->limit($limit)->get()->map(fn (User $u) => [
                (string) $u->id,
                trim(($u->name ?? '') . ' ' . ($u->surname ?? '')),
                $u->email ?? '-',
                $u->role ?? '-',
                $u->status ?? '-',
                $u->created_at?->format('Y-m-d') ?? '-',
            ])->all();
            $table = ['columns' => ['ID', 'Ad Soyad', 'E-posta', 'Rol', 'Durum', 'Olusturma'], 'rows' => $rows];
            $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'kullanicilar');
            return $this->response('Kullanici listesi hazirlandi.', 'user_list', $table, null, $token);
        }

        $rows = [];
        foreach (User::query()->selectRaw('role, status, count(*) as c')->groupBy('role', 'status')->orderBy('role')->get() as $row) {
            $rows[] = [(string) ($row->role ?? '-'), (string) ($row->status ?? '-'), (string) $row->c];
        }
        $table = ['columns' => ['Rol', 'Durum', 'Adet'], 'rows' => $rows];
        $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'kullanici_ozet');
        return $this->response('Kullanici rol/durum dagilimi hazirlandi.', 'user_summary', $table, null, $token);
    }

    private function handleStaffIntent(User $user, string $normalized, int $limit): array
    {
        if (! $this->hasUsablePermission($user, 'staff.view')) {
            return $this->permissionDeniedResponse('staff.view');
        }

        $query = StaffProfile::query()->with('user:id,name,surname,email,status')->latest('updated_at');
        if (! $this->permissionResolver->hasGlobalScope($user, 'staff.view')) {
            $query->where('user_id', $user->id);
        }

        if ($this->wantsTableList($normalized)) {
            $rows = $query->limit($limit)->get()->map(fn (StaffProfile $profile) => [
                $profile->user ? trim(($profile->user->name ?? '') . ' ' . ($profile->user->surname ?? '')) : '-',
                $profile->user?->email ?? '-',
                $profile->title ?? '-',
                $profile->unit ?? '-',
                $profile->contract_type ?? '-',
                $profile->start_date?->format('Y-m-d') ?? '-',
                $profile->user?->status ?? '-',
            ])->all();
            $table = ['columns' => ['Ad Soyad', 'E-posta', 'Unvan', 'Birim', 'Sozlesme', 'Baslangic', 'Durum'], 'rows' => $rows];
            $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'personel');
            return $this->response('Personel listesi hazirlandi.', 'staff_list', $table, null, $token);
        }

        $rows = [];
        foreach ((clone $query)->reorder()->selectRaw('unit, count(*) as c')->groupBy('unit')->orderBy('unit')->get() as $row) {
            $rows[] = [(string) ($row->unit ?? '-'), (string) $row->c];
        }
        $table = ['columns' => ['Birim', 'Adet'], 'rows' => $rows];
        $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'personel_ozet');
        return $this->response('Personel birim dagilimi hazirlandi.', 'staff_summary', $table, null, $token);
    }

    private function buildSystemGuide(string $normalized): array
    {
        if (str_contains($normalized, 'cv')) {
            return $this->response("**CV ve PDF Yetki Bilgisi**\n\nKatılımcı CV ekranında panel erişimi `projects.student_cv.view` izni ve ilgili proje erişimiyle kontrol edilir. CV detayı ham JSON olarak basılmaz; okunabilir bölümlere ayrılır. PDF indirme de aynı izin ve proje kontrolünden geçer.", 'system_cv_guide', null, null, null);
        }

        if (str_contains($normalized, 'proje')) {
            return $this->response("**Proje Özel Modül Yetkisi**\n\nProje özel modüllerinde görünürlük yalnızca action+scope değildir. Kullanıcının ilgili proje ailesinde erişebildiği proje de olmalıdır. Frontend sidebar bu bilgiyi `permission_scopes` ve `authorization_context` ile saklar; backend ise her endpointte `canAccessProject` veya aileye uygun scope kontrolü yapar.", 'system_project_module_guide', null, null, null);
        }

        return $this->response("**Action+Scope Yetki Modeli**\n\nFrontend yalnızca uygun menüyü ve aksiyonu gösterir; backend ise her istekte permission ve scope'u tekrar kontrol eder. `all` tüm sistem, `selected_projects` seçili projeler, `own`, `assigned` ve `self` kullanıcının yetki bağlamındaki kayıtlar içindir. Proje bağımsız modüllerde, eğitmenlerde olduğu gibi `all` scope yeterli tutulabilir.", 'system_permission_guide', null, null, null);
    }

    private function handleSupportIntent(User $user, string $normalized, Collection $matched, ?Carbon $fromDate, ?Carbon $toDate, ?string $dateLabel, int $limit): array
    {
        if (! $this->hasUsablePermission($user, 'support.view')) {
            return $this->permissionDeniedResponse('support.view');
        }

        $query = SupportTicket::query()->with(['project:id,name', 'assignee:id,name,surname', 'user:id,name,surname,email'])->latest();
        if (! $this->permissionResolver->hasGlobalScope($user, 'support.view')) {
            $ids = $this->permissionResolver->projectIdsForPermission($user, 'support.view');
            $query->where(function ($builder) use ($user, $ids) {
                $builder->whereIn('project_id', $ids)->orWhere('assigned_to', $user->id)->orWhere('user_id', $user->id);
            });
        }
        $this->applyMatchedProjects($query, $matched);
        if ($fromDate !== null && $toDate !== null) {
            $query->whereBetween('created_at', [$fromDate, $toDate]);
        }
        foreach (['open', 'pending', 'closed', 'resolved'] as $status) {
            if (str_contains($normalized, $status) || ($status === 'closed' && str_contains($normalized, 'kapali')) || ($status === 'open' && str_contains($normalized, 'acik'))) {
                $query->where('status', $status);
                break;
            }
        }

        if ($this->wantsTableList($normalized)) {
            $rows = $query->limit($limit)->get()->map(fn (SupportTicket $ticket) => [
                (string) $ticket->id,
                $ticket->subject ?? '-',
                $ticket->category ?? '-',
                $ticket->status ?? '-',
                $ticket->project?->name ?? '-',
                $ticket->assignee ? trim(($ticket->assignee->name ?? '') . ' ' . ($ticket->assignee->surname ?? '')) : '-',
                $ticket->created_at?->format('Y-m-d H:i') ?? '-',
            ])->all();
            $table = ['columns' => ['ID', 'Konu', 'Kategori', 'Durum', 'Proje', 'Atanan', 'Tarih'], 'rows' => $rows];
            $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'destek_listesi');
            return $this->response('Destek kayitlari listelendi' . ($dateLabel ? " ({$dateLabel})" : '') . '.', 'support_list', $table, null, $token);
        }

        $rows = [];
        foreach ((clone $query)->reorder()->selectRaw('status, count(*) as c')->groupBy('status')->get() as $row) {
            $rows[] = [(string) ($row->status ?? '-'), (string) $row->c];
        }
        $table = ['columns' => ['Durum', 'Adet'], 'rows' => $rows];
        $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'destek_ozet');
        return $this->response('Destek ozeti hazirlandi' . ($dateLabel ? " ({$dateLabel})" : '') . '.', 'support_summary', $table, null, $token);
    }

    private function handleRequestIntent(User $user, string $normalized, Collection $matched, ?Carbon $fromDate, ?Carbon $toDate, ?string $dateLabel, int $limit): array
    {
        if (! $this->hasUsablePermission($user, 'requests.view')) {
            return $this->permissionDeniedResponse('requests.view');
        }

        $query = ServiceRequest::query()->with(['requester:id,name,surname,email', 'project:id,name', 'targetUser:id,name,surname'])->latest();
        $this->applyProjectVisibility($query, $user, 'requests.view');
        $this->applyMatchedProjects($query, $matched);
        if ($fromDate !== null && $toDate !== null) {
            $query->whereBetween('created_at', [$fromDate, $toDate]);
        }

        if ($this->wantsTableList($normalized)) {
            $rows = $query->limit($limit)->get()->map(fn (ServiceRequest $request) => [
                (string) $request->id,
                $request->type ?? '-',
                $request->status ?? '-',
                $request->target_unit ?? '-',
                $request->project?->name ?? '-',
                $request->requester ? trim(($request->requester->name ?? '') . ' ' . ($request->requester->surname ?? '')) : '-',
                $request->created_at?->format('Y-m-d H:i') ?? '-',
            ])->all();
            $table = ['columns' => ['ID', 'Tip', 'Durum', 'Hedef Birim', 'Proje', 'Talep Eden', 'Tarih'], 'rows' => $rows];
            $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'talepler');
            return $this->response('Talep listesi hazirlandi' . ($dateLabel ? " ({$dateLabel})" : '') . '.', 'request_list', $table, null, $token);
        }

        $rows = [];
        foreach ((clone $query)->reorder()->selectRaw('status, count(*) as c')->groupBy('status')->get() as $row) {
            $rows[] = [(string) ($row->status ?? '-'), (string) $row->c];
        }
        $table = ['columns' => ['Durum', 'Adet'], 'rows' => $rows];
        $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'talep_ozet');
        return $this->response('Talep ozeti hazirlandi' . ($dateLabel ? " ({$dateLabel})" : '') . '.', 'request_summary', $table, null, $token);
    }

    private function handleAnnouncementIntent(User $user, string $normalized, Collection $matched, ?Carbon $fromDate, ?Carbon $toDate, ?string $dateLabel, int $limit): array
    {
        if (! $this->hasUsablePermission($user, 'announcements.view')) {
            return $this->permissionDeniedResponse('announcements.view');
        }

        $query = Announcement::query()->with(['project:id,name', 'creator:id,name,surname'])->latest('published_at');
        $this->applyProjectVisibility($query, $user, 'announcements.view');
        $this->applyMatchedProjects($query, $matched);
        if ($fromDate !== null && $toDate !== null) {
            $query->whereBetween('created_at', [$fromDate, $toDate]);
        }

        if ($this->wantsTableList($normalized)) {
            $rows = $query->limit($limit)->get()->map(fn (Announcement $announcement) => [
                (string) $announcement->id,
                $announcement->title ?? '-',
                $announcement->category ?? '-',
                $announcement->project?->name ?? '-',
                $announcement->published_at?->format('Y-m-d H:i') ?? '-',
                $announcement->expires_at?->format('Y-m-d H:i') ?? '-',
            ])->all();
            $table = ['columns' => ['ID', 'Baslik', 'Kategori', 'Proje', 'Yayin', 'Bitis'], 'rows' => $rows];
            $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'duyurular');
            return $this->response('Duyuru listesi hazirlandi' . ($dateLabel ? " ({$dateLabel})" : '') . '.', 'announcement_list', $table, null, $token);
        }

        $rows = [];
        foreach ((clone $query)->reorder()->selectRaw('category, count(*) as c')->groupBy('category')->get() as $row) {
            $rows[] = [(string) ($row->category ?? '-'), (string) $row->c];
        }
        $table = ['columns' => ['Kategori', 'Adet'], 'rows' => $rows];
        $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'duyuru_ozet');
        return $this->response('Duyuru ozeti hazirlandi' . ($dateLabel ? " ({$dateLabel})" : '') . '.', 'announcement_summary', $table, null, $token);
    }

    private function handlePeriodIntent(User $user, string $normalized, Collection $matched, int $limit): array
    {
        if (! $this->hasUsablePermission($user, 'periods.view')) {
            return $this->permissionDeniedResponse('periods.view');
        }

        $query = Period::query()->with('project:id,name')->latest('start_date');
        $this->applyProjectVisibility($query, $user, 'periods.view');
        $this->applyMatchedProjects($query, $matched);

        if ($this->wantsTableList($normalized)) {
            $rows = $query->limit($limit)->get()->map(fn (Period $period) => [
                (string) $period->id,
                $period->project?->name ?? '-',
                $period->name,
                $period->status ?? '-',
                $period->start_date?->format('Y-m-d') ?? '-',
                $period->end_date?->format('Y-m-d') ?? '-',
                (string) ($period->credit_start_amount ?? '-'),
                (string) ($period->credit_threshold ?? '-'),
            ])->all();
            $table = ['columns' => ['ID', 'Proje', 'Donem', 'Durum', 'Baslangic', 'Bitis', 'Baslangic Kredi', 'Esik'], 'rows' => $rows];
            $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'donemler');
            return $this->response('Donem listesi hazirlandi.', 'period_list', $table, null, $token);
        }

        $rows = [];
        foreach ((clone $query)->reorder()->selectRaw('status, count(*) as c')->groupBy('status')->get() as $row) {
            $rows[] = [(string) ($row->status ?? '-'), (string) $row->c];
        }
        $table = ['columns' => ['Durum', 'Adet'], 'rows' => $rows];
        $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'donem_ozet');
        return $this->response('Donem ozeti hazirlandi.', 'period_summary', $table, null, $token);
    }

    private function buildProgramSummary(User $user, Project $project, string $normalized, ?Carbon $fromDate, ?Carbon $toDate, ?string $dateLabel, int $limit): array
    {
        $query = Program::query()->where('project_id', $project->id)->with('period:id,name')->withCount(['attendances', 'feedbacks'])->latest('start_at');
        if ($fromDate !== null && $toDate !== null) {
            $query->whereBetween('start_at', [$fromDate, $toDate]);
        }

        if ($this->wantsTableList($normalized)) {
            $rows = $query->limit($limit)->get()->map(fn (Program $program) => [
                (string) $program->id,
                $program->title,
                $program->period?->name ?? '-',
                $program->status ?? '-',
                $program->location ?? '-',
                $program->start_at?->format('Y-m-d H:i') ?? '-',
                (string) ($program->attendances_count ?? 0),
                (string) ($program->feedbacks_count ?? 0),
            ])->all();
            $table = ['columns' => ['ID', 'Program', 'Donem', 'Durum', 'Yer', 'Baslangic', 'Yoklama', 'Feedback'], 'rows' => $rows];
            $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'programlar_' . $project->slug);
            return $this->response("{$project->name} program listesi hazirlandi" . ($dateLabel ? " ({$dateLabel})" : '') . '.', 'program_list', $table, null, $token);
        }

        $rows = [];
        foreach ((clone $query)->reorder()->selectRaw('status, count(*) as c')->groupBy('status')->get() as $row) {
            $rows[] = [(string) ($row->status ?? '-'), (string) $row->c];
        }
        $programs = (clone $query)->get();
        $rows[] = ['Toplam yoklama kaydi', (string) $programs->sum('attendances_count')];
        $rows[] = ['Toplam feedback', (string) $programs->sum('feedbacks_count')];
        $table = ['columns' => ['Metrik', 'Adet'], 'rows' => $rows];
        $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'program_ozet_' . $project->slug);
        return $this->response("{$project->name} program ozeti hazirlandi" . ($dateLabel ? " ({$dateLabel})" : '') . '.', 'program_summary', $table, null, $token);
    }

    private function buildVolunteerSummary(User $user, Project $project, string $normalized, int $limit): array
    {
        $query = VolunteerOpportunity::query()->where('project_id', $project->id)->withCount('applications')->latest('start_at');
        if ($this->wantsTableList($normalized)) {
            $rows = $query->limit($limit)->get()->map(fn (VolunteerOpportunity $item) => [
                (string) $item->id,
                $item->title,
                $item->status ?? '-',
                $item->location ?? '-',
                (string) ($item->quota ?? '-'),
                (string) ($item->applications_count ?? 0),
                $item->start_at?->format('Y-m-d H:i') ?? '-',
            ])->all();
            $table = ['columns' => ['ID', 'Baslik', 'Durum', 'Yer', 'Kontenjan', 'Basvuru', 'Baslangic'], 'rows' => $rows];
            $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'gonullu_' . $project->slug);
            return $this->response("{$project->name} gonullu firsatlari listelendi.", 'volunteer_list', $table, null, $token);
        }

        $rows = [];
        foreach ((clone $query)->reorder()->selectRaw('status, count(*) as c')->groupBy('status')->get() as $row) {
            $rows[] = [(string) ($row->status ?? '-'), (string) $row->c];
        }
        $table = ['columns' => ['Durum', 'Adet'], 'rows' => $rows];
        $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'gonullu_ozet_' . $project->slug);
        return $this->response("{$project->name} gonullu ozeti hazirlandi.", 'volunteer_summary', $table, null, $token);
    }

    private function buildDigitalBohcaSummary(User $user, Project $project, string $normalized, int $limit): array
    {
        $query = DigitalBohca::query()->where('project_id', $project->id)->with(['user:id,name,surname', 'period:id,name'])->latest();
        if ($this->wantsTableList($normalized)) {
            $rows = $query->limit($limit)->get()->map(fn (DigitalBohca $item) => [
                (string) $item->id,
                $item->title,
                $item->category ?? '-',
                $item->file_type ?? '-',
                $item->period?->name ?? '-',
                $item->user ? trim(($item->user->name ?? '') . ' ' . ($item->user->surname ?? '')) : '-',
                $item->visible_to_student ? 'evet' : 'hayir',
            ])->all();
            $table = ['columns' => ['ID', 'Baslik', 'Kategori', 'Dosya Tipi', 'Donem', 'Katilimci', 'Ogrenciye Gorunur'], 'rows' => $rows];
            $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'dijital_bohca_' . $project->slug);
            return $this->response("{$project->name} dijital bohca listesi hazirlandi.", 'digital_bohca_list', $table, null, $token);
        }

        $rows = [];
        foreach ((clone $query)->reorder()->selectRaw('category, count(*) as c')->groupBy('category')->get() as $row) {
            $rows[] = [(string) ($row->category ?? '-'), (string) $row->c];
        }
        $table = ['columns' => ['Kategori', 'Adet'], 'rows' => $rows];
        $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'dijital_bohca_ozet_' . $project->slug);
        return $this->response("{$project->name} dijital bohca ozeti hazirlandi.", 'digital_bohca_summary', $table, null, $token);
    }

    private function buildAssignmentSummary(User $user, Project $project, string $normalized, int $limit): array
    {
        $query = Assignment::query()->where('project_id', $project->id)->with(['period:id,name', 'program:id,title'])->withCount('submissions')->latest('due_date');
        if ($this->wantsTableList($normalized)) {
            $rows = $query->limit($limit)->get()->map(fn (Assignment $assignment) => [
                (string) $assignment->id,
                $assignment->title,
                $assignment->period?->name ?? '-',
                $assignment->program?->title ?? '-',
                $assignment->due_date?->format('Y-m-d H:i') ?? '-',
                (string) ($assignment->submissions_count ?? 0),
            ])->all();
            $table = ['columns' => ['ID', 'Odev', 'Donem', 'Program', 'Teslim Tarihi', 'Teslim'], 'rows' => $rows];
            $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'odevler_' . $project->slug);
            return $this->response("{$project->name} odev listesi hazirlandi.", 'assignment_list', $table, null, $token);
        }

        $assignments = (clone $query)->get();
        $table = ['columns' => ['Metrik', 'Adet'], 'rows' => [
            ['Odev sayisi', (string) $assignments->count()],
            ['Teslim sayisi', (string) $assignments->sum('submissions_count')],
        ]];
        $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'odev_ozet_' . $project->slug);
        return $this->response("{$project->name} odev ozeti hazirlandi.", 'assignment_summary', $table, null, $token);
    }

    private function buildCertificateSummary(User $user, Project $project, string $normalized, int $limit): array
    {
        $query = Certificate::query()->where('project_id', $project->id)->with(['user:id,name,surname,email', 'period:id,name'])->latest('issued_at');
        if ($this->wantsTableList($normalized)) {
            $rows = $query->limit($limit)->get()->map(fn (Certificate $certificate) => [
                (string) $certificate->id,
                $certificate->user ? trim(($certificate->user->name ?? '') . ' ' . ($certificate->user->surname ?? '')) : '-',
                $certificate->user?->email ?? '-',
                $certificate->type ?? '-',
                $certificate->period?->name ?? '-',
                $certificate->issued_at?->format('Y-m-d') ?? '-',
                $certificate->verification_code ?? '-',
            ])->all();
            $table = ['columns' => ['ID', 'Kisi', 'E-posta', 'Tip', 'Donem', 'Verilis', 'Dogrulama Kodu'], 'rows' => $rows];
            $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'sertifikalar_' . $project->slug);
            return $this->response("{$project->name} sertifika listesi hazirlandi.", 'certificate_list', $table, null, $token);
        }

        $rows = [];
        foreach ((clone $query)->reorder()->selectRaw('type, count(*) as c')->groupBy('type')->get() as $row) {
            $rows[] = [(string) ($row->type ?? '-'), (string) $row->c];
        }
        $table = ['columns' => ['Tip', 'Adet'], 'rows' => $rows];
        $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'sertifika_ozet_' . $project->slug);
        return $this->response("{$project->name} sertifika ozeti hazirlandi.", 'certificate_summary', $table, null, $token);
    }

    private function buildProjectSpecialModuleSummary(User $user, Project $project): array
    {
        $rows = [];
        if ($this->permissionResolver->canAccessProject($user, 'projects.internships.view', $project->id) || $this->permissionResolver->canAccessProject($user, 'projects.internships.manage', $project->id)) {
            $rows[] = ['Staj kayitlari', (string) Internship::query()->whereHas('participant', fn ($q) => $q->where('project_id', $project->id))->count()];
        }
        if ($this->permissionResolver->canAccessProject($user, 'projects.mentors.view', $project->id) || $this->permissionResolver->canAccessProject($user, 'projects.mentors.manage', $project->id)) {
            $rows[] = ['Mentorlar', (string) Mentor::query()->where('project_id', $project->id)->count()];
        }
        if ($this->permissionResolver->canAccessProject($user, 'projects.eurodesk.view', $project->id) || $this->permissionResolver->canAccessProject($user, 'projects.eurodesk.manage', $project->id)) {
            $rows[] = ['Eurodesk projeleri', (string) EurodeskProject::query()->where('project_id', $project->id)->count()];
        }
        if ($this->permissionResolver->canAccessProject($user, 'projects.rewards.view', $project->id) || $this->permissionResolver->canAccessProject($user, 'projects.rewards.manage', $project->id)) {
            $rows[] = ['Odul kademeleri', (string) RewardTier::query()->where('project_id', $project->id)->count()];
            $rows[] = ['Odul teslimleri', (string) RewardAward::query()->where('project_id', $project->id)->count()];
            $rows[] = ['Kademe modulleri', (string) ProjectModule::query()->where('project_id', $project->id)->count()];
        }

        if ($rows === []) {
            return $this->permissionDeniedResponse('projects.* special modules');
        }

        $table = ['columns' => ['Modul', 'Adet'], 'rows' => $rows];
        $token = $this->storeExportPayload($user, $table['columns'], $table['rows'], 'proje_ozel_moduller_' . $project->slug);
        return $this->response("{$project->name} proje ozgu modul ozeti hazirlandi. Bu rapor action+scope ve proje erisimi birlikte gecilerek uretilir.", 'project_special_modules_summary', $table, null, $token);
    }

    private function localizeTable(array $table): array
    {
        $columns = array_map(
            fn ($column) => $this->localizeColumn((string) $column),
            $table['columns'] ?? []
        );

        $rows = array_map(function ($row) use ($columns) {
            $row = is_array($row) ? $row : [$row];

            return array_map(
                fn ($cell, $index) => $this->localizeCell($cell, $columns[$index] ?? null),
                $row,
                array_keys($row)
            );
        }, $table['rows'] ?? []);

        return [
            'columns' => $columns,
            'rows' => $rows,
        ];
    }

    private function localizeColumn(string $column): string
    {
        $labels = [
            'Aktif Katilimci' => 'Aktif Katılımcı',
            'Aktif katılımcı' => 'Aktif Katılımcı',
            'Accepted' => 'Kabul Edilen',
            'Approved Tutar' => 'Onaylanan Tutar',
            'Baslangic' => 'Başlangıç',
            'Baslangic Kredi' => 'Başlangıç Kredisi',
            'Baslik' => 'Başlık',
            'Basvuru' => 'Başvuru',
            'Bitis' => 'Bitiş',
            'Bolum' => 'Bölüm',
            'Bölüm' => 'Bölüm',
            'Deger' => 'Değer',
            'Donem' => 'Dönem',
            'Dogrulama Kodu' => 'Doğrulama Kodu',
            'Esik' => 'Eşik',
            'Feedback' => 'Geri Bildirim',
            'Katilimci' => 'Katılımcı',
            'Kayit durumu' => 'Kayıt Durumu',
            'Kisi' => 'Kişi',
            'Kullanici' => 'Kullanıcı',
            'Modul' => 'Modül',
            'Odev' => 'Ödev',
            'Ogrenciye Gorunur' => 'Öğrenciye Görünür',
            'Olcum' => 'Ölçüm',
            'Olusturma' => 'Oluşturulma',
            'Paid Tutar' => 'Ödenen Tutar',
            'Pending' => 'Bekleyen',
            'Pending Tutar' => 'Bekleyen Tutar',
            'Rejected Tutar' => 'Reddedilen Tutar',
            'Son Calisma' => 'Son Çalışma',
            'Sonuc' => 'Sonuç',
            'Sozlesme' => 'Sözleşme',
            'Toplam Katilimci' => 'Toplam Katılımcı',
            'Toplam kayit' => 'Toplam Kayıt',
            'Tur' => 'Tür',
            'Universite' => 'Üniversite',
            'Unvan' => 'Unvan',
            'Uzmanlik' => 'Uzmanlık',
            'Verilis' => 'Veriliş',
            'Yayin' => 'Yayın',
        ];

        return $labels[$column] ?? $column;
    }
    private function localizeCell(mixed $cell, ?string $column = null): string
    {
        if ($cell === null || $cell === '') {
            return '-';
        }

        if (is_bool($cell)) {
            return $cell ? 'Evet' : 'Hayır';
        }

        $value = is_scalar($cell) ? (string) $cell : json_encode($cell, JSON_UNESCAPED_UNICODE);
        $value = $value === false ? '-' : trim($value);

        if ($value === '' || $value === '-') {
            return '-';
        }

        if (preg_match('/^-?\d+([.,]\d+)?$/', $value)) {
            return $value;
        }

        if (str_contains($value, ':')) {
            [$prefix, $suffix] = array_map('trim', explode(':', $value, 2));
            $localizedPrefix = $this->localizeMetricPrefix($prefix);
            $localizedSuffix = $this->localizeEnumValue($suffix, $column);

            return "{$localizedPrefix}: {$localizedSuffix}";
        }

        return $this->localizeEnumValue($value, $column);
    }

    private function localizeMetricPrefix(string $prefix): string
    {
        $labels = [
            'Aktif' => 'Aktif',
            'Aday' => 'Aday',
            'Basarili' => 'Başarılı',
            'Basarisiz / engellenen' => 'Başarısız / Engellenen',
            'E-posta bulunan' => 'E-posta Bulunan',
            'Eurodesk projeleri' => 'Eurodesk Projeleri',
            'Kademe modulleri' => 'Kademe Modülleri',
            'Kademe yorumu bulunan' => 'Kademe Yorumu Bulunan',
            'Kayit' => 'Kayıt',
            'Kaynak' => 'Kaynak',
            'Mentorlar' => 'Mentorlar',
            'Mezuniyet' => 'Mezuniyet',
            'Odul kademeleri' => 'Ödül Kademeleri',
            'Odul teslimleri' => 'Ödül Teslimleri',
            'Pasif' => 'Pasif',
            'Staj kayitlari' => 'Staj Kayıtları',
            'Toplam' => 'Toplam',
            'Toplam feedback' => 'Toplam Geri Bildirim',
            'Toplam kayit' => 'Toplam Kayıt',
            'Toplam yoklama kaydi' => 'Toplam Yoklama Kaydı',
        ];

        return $labels[$prefix] ?? $prefix;
    }
    private function localizeEnumValue(string $value, ?string $column = null): string
    {
        $normalized = Str::lower(trim($value));

        $labels = [
            '(bos)' => '(Boş)',
            'accepted' => 'Kabul Edildi',
            'active' => 'Aktif',
            'admin' => 'Yönetici',
            'alumni' => 'Mezun',
            'approved' => 'Onaylandı',
            'assigned' => 'Atanmış',
            'auto_rejected' => 'Otomatik Reddedildi',
            'blacklisted' => 'Kara Liste',
            'candidate' => 'Aday',
            'cancelled' => 'İptal Edildi',
            'closed' => 'Kapalı',
            'completed' => 'Tamamlandı',
            'coordinator' => 'Koordinatör',
            'csv' => 'CSV',
            'denied_or_failed' => 'Reddedildi / Başarısız',
            'docx' => 'Word',
            'draft' => 'Taslak',
            'failed' => 'Tamamlayamadı',
            'graduated' => 'Mezun',
            'hayir' => 'Hayır',
            'hidden' => 'Gizli',
            'in_progress' => 'İşlemde',
            'interview_failed' => 'Mülakat Olumsuz',
            'interview_passed' => 'Mülakat Geçildi',
            'interview_planned' => 'Mülakat Planlandı',
            'json' => 'JSON',
            'late' => 'Geç Teslim',
            'not_completed' => 'Tamamlanmadı',
            'not_submitted' => 'Teslim Edilmedi',
            'open' => 'Açık',
            'paid' => 'Ödendi',
            'passive' => 'Pasif',
            'pdf' => 'PDF',
            'pending' => 'Beklemede',
            'private' => 'Özel',
            'public' => 'Herkese Açık',
            'published' => 'Yayında',
            'rejected' => 'Reddedildi',
            'resolved' => 'Çözüldü',
            'reviewed' => 'İncelendi',
            'scheduled' => 'Planlandı',
            'self' => 'Kendi Kaydı',
            'staff' => 'Personel',
            'student' => 'Öğrenci',
            'submitted' => 'Teslim Edildi',
            'success' => 'Başarılı',
            'true' => 'Evet',
            'false' => 'Hayır',
            'var' => 'Var',
            'visible' => 'Görünür',
            'waitlist' => 'Yedek',
            'waitlisted' => 'Yedek Listede',
            'xlsx' => 'Excel',
            'yok' => 'Yok',
        ];

        return $labels[$normalized] ?? $value;
    }
    private function localizeReply(string $reply): string
    {
        return strtr($reply, [
            'Action scope' => 'Action scope',
            'Basarisiz' => 'Başarısız',
            'Basarili' => 'Başarılı',
            'Basvuru' => 'Başvuru',
            'Cikti' => 'Çıktı',
            'Disa Aktar' => 'Dışa Aktar',
            'Disa aktar' => 'Dışa aktar',
            'Donem' => 'Dönem',
            'Egitmen' => 'Eğitmen',
            'Erisim' => 'Erişim',
            'Gonullu' => 'Gönüllü',
            'Hazirlandi' => 'Hazırlandı',
            'Katilim' => 'Katılım',
            'Katilimci' => 'Katılımcı',
            'Kayit' => 'Kayıt',
            'Kullanici' => 'Kullanıcı',
            'Modul' => 'Modül',
            'Odev' => 'Ödev',
            'Ozet' => 'Özet',
            'Veri asistani' => 'Veri asistanı',
            'bagimsizdir' => 'bağımsızdır',
            'basarisiz' => 'başarısız',
            'basarili' => 'başarılı',
            'basvuru' => 'başvuru',
            'bolumlere' => 'bölümlere',
            'calisir' => 'çalışır',
            'calistirir' => 'çalıştırır',
            'cikarabilirim' => 'çıkarabilirim',
            'cikti' => 'çıktı',
            'dagilimi' => 'dağılımı',
            'detayi' => 'detayı',
            'donem' => 'dönem',
            'dugmeyi' => 'düğmeyi',
            'egitmen' => 'eğitmen',
            'erisimi' => 'erişimi',
            'erisim' => 'erişim',
            'evet' => 'evet',
            'firatlari' => 'fırsatları',
            'gecer' => 'geçer',
            'gecilerek' => 'geçilerek',
            'gecti' => 'geçti',
            'gonullu' => 'gönüllü',
            'gorunur' => 'görünür',
            'gorunurluk' => 'görünürlük',
            'gosterir' => 'gösterir',
            'guncel' => 'güncel',
            'hazirlandi' => 'hazırlandı',
            'icermiyor' => 'içermiyor',
            'icin' => 'için',
            'indirebilirsiniz' => 'indirebilirsiniz',
            'iznini' => 'iznini',
            'kapsami' => 'kapsamı',
            'karsilastirma' => 'karşılaştırma',
            'katilimci' => 'katılımcı',
            'katilim' => 'katılım',
            'kayit' => 'kayıt',
            'kaynagi' => 'kaynağı',
            'kullanici' => 'kullanıcı',
            'kullanilabilir' => 'kullanılabilir',
            'listelendi' => 'listelendi',
            'mantigi' => 'mantığı',
            'modul' => 'modül',
            'okunabilir' => 'okunabilir',
            'okunamadi' => 'okunamadı',
            'odev' => 'ödev',
            'olusturup' => 'oluşturup',
            'ozel' => 'özel',
            'ozeti' => 'özeti',
            'ozet' => 'özet',
            'ozgu' => 'özgü',
            'proje bazli' => 'proje bazlı',
            'saglayabilirim' => 'sağlayabilirim',
            'sinir' => 'sınır',
            'su anda' => 'şu anda',
            'sureci' => 'süreci',
            'tarafinda' => 'tarafında',
            'uretilir' => 'üretilir',
            'ust sinir' => 'üst sınır',
            'veri asistani' => 'veri asistanı',
            'Aktif (kayit durumu)' => 'Aktif katılımcı',
            'Basvuru durumlari' => 'Başvuru durumları',
            'Detay tabloda' => 'Detaylı kırılım tabloda',
            'Liste icin' => 'Liste için',
            'Mezuniyet alani "graduated" sayisi' => 'Mezun olarak işaretlenen katılımcı sayısı',
            'Toplam kayit' => 'Toplam kayıt',
            'alani' => 'alanı',
            'alti' => 'altı',
            'basvuru durumlari' => 'başvuru durumları',
            'dugmeyi kullanin' => 'düğmeyi kullanın',
            'en guncel' => 'en güncel',
            'kaydi' => 'kaydı',
            'kirilimi' => 'kırılımı',
            'sayisi' => 'sayısı',
            'yalnizca kayit durumu' => 'yalnızca kayıt durumu',            'yazdiginiz' => 'yazdığınız',
            'all tum sistem' => 'all tüm sistem',
            'anahtar kelimelerle' => 'anahtar kelimelerle',
            'aranir' => 'aranır',
            'ayni' => 'aynı',
            'ayrilir' => 'ayrılır',
            'baglamindaki' => 'bağlamındaki',
            'bazli' => 'bazlı',
            'basilmaz' => 'basılmaz',
            'bohca' => 'bohça',
            'degildir' => 'değildir',
            'erisebildigi' => 'erişebildiği',
            'gecen' => 'geçen',
            'gun' => 'gün',
            'icindir' => 'içindir',
            'iki katmanli' => 'iki katmanlı',
            'kontrolunden' => 'kontrolünden',
            'kontrolu' => 'kontrolü',
            'kullanicinin' => 'kullanıcının',
            'menuyu' => 'menüyü',
            'oldugu' => 'olduğu',
            'olmalidir' => 'olmalıdır',
            'sorgularini' => 'sorgularını',
            'tablolari' => 'tabloları',
            'tanimli' => 'tanımlı',
            'yalnizca' => 'yalnızca',
            'secili' => 'seçili',
        ]);
    }
    private function storeExportPayload(User $user, array $columns, array $rows, string $filenameBase): string
    {
        $token = Str::random(48);
        $table = $this->localizeTable([
            'columns' => $columns,
            'rows' => $rows,
        ]);

        Cache::put(
            $this->exportCacheKey($token),
            [
                'user_id' => $user->id,
                'headings' => $table['columns'],
                'rows' => $table['rows'],
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
        $payload = Cache::get($this->exportCacheKey($token));
        if (! is_array($payload)) {
            return null;
        }

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
            'reply' => $this->localizeReply($reply),
            'intent' => $intent,
            'table' => $table !== null ? $this->localizeTable($table) : null,
            'stats' => $stats,
            'export_token' => $exportToken,
            'export_available' => $exportToken !== null,
        ];
    }}







