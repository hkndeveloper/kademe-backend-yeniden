<?php

namespace App\Services\PeriodClosure;

use App\Models\Application;
use App\Models\ApplicationWindow;
use App\Models\AssignmentSubmission;
use App\Models\FinancialTransaction;
use App\Models\Period;
use App\Models\Program;

class CoreOperationsChecker implements PeriodClosureChecker
{
    public function check(Period $period): array
    {
        $periodId = (int) $period->id;

        return [
            $this->result(
                'open_application_window',
                ApplicationWindow::query()->where('period_id', $periodId)->where('is_open', true)->count(),
                'Donemin basvuru penceresi acik.',
            ),
            $this->result(
                'open_programs',
                Program::query()->where('period_id', $periodId)->whereIn('status', ['scheduled', 'active'])->count(),
                'Planlanmis veya devam eden programlar var.',
            ),
            $this->result(
                'unresolved_applications',
                Application::query()->where('period_id', $periodId)
                    ->whereIn('status', ['pending', 'interview_planned', 'waitlisted', 'interview_passed'])
                    ->count(),
                'Kesin karara baglanmamis basvurular var.',
            ),
            $this->result(
                'pending_financials',
                FinancialTransaction::query()->where('period_id', $periodId)
                    ->whereIn('status', ['pending', 'approved'])
                    ->count(),
                'Onay veya odeme sonucu bekleyen finans kayitlari var.',
            ),
            $this->result(
                'unreviewed_assignment_submissions',
                AssignmentSubmission::query()
                    ->whereHas('assignment', fn ($query) => $query->where('period_id', $periodId))
                    ->whereIn('status', ['submitted', 'reviewed'])
                    ->count(),
                'Kesin degerlendirme bekleyen odev teslimleri var.',
            ),
        ];
    }

    private function result(string $code, int $count, string $message): array
    {
        return compact('code', 'count', 'message');
    }
}
