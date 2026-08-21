<?php

namespace App\Services\PeriodClosure;

use App\Models\Certificate;
use App\Models\Feedback;
use App\Models\KpdAppointment;
use App\Models\Period;
use App\Models\Program;
use App\Models\Request as ServiceRequest;
use App\Models\SupportTicket;

class AdvisoryOperationsChecker implements PeriodClosureChecker
{
    public function check(Period $period): array
    {
        $periodId = (int) $period->id;
        $openSupport = SupportTicket::query()->where('period_id', $periodId)
            ->whereIn('status', ['open', 'in_progress'])
            ->count();
        $openRequests = ServiceRequest::query()->where('period_id', $periodId)
            ->whereIn('status', ['pending', 'in_progress'])
            ->count();
        $completedProgramIds = Program::query()->where('period_id', $periodId)
            ->where('status', 'completed')
            ->pluck('id');
        $programsWithFeedback = Feedback::query()
            ->whereIn('program_id', $completedProgramIds)
            ->distinct()
            ->count('program_id');

        return [
            $this->result(
                'open_kpd_work',
                KpdAppointment::query()->where('period_id', $periodId)->where('status', 'scheduled')->count(),
                'Sonuclanmamis KPD randevulari var.',
            ),
            $this->result(
                'open_support_or_requests',
                $openSupport + $openRequests,
                'Acik destek kaydi veya hizmet talebi var.',
                ['support_tickets' => $openSupport, 'requests' => $openRequests],
            ),
            $this->result(
                'undelivered_certificates',
                Certificate::query()->where('period_id', $periodId)->whereNull('certificate_path')->count(),
                'Dosyasi henuz uretilmemis sertifika kayitlari var.',
            ),
            $this->result(
                'missing_feedback',
                max($completedProgramIds->count() - $programsWithFeedback, 0),
                'Geri bildirim bulunmayan tamamlanmis programlar var.',
            ),
        ];
    }

    private function result(string $code, int $count, string $message, array $metadata = []): array
    {
        return array_filter([
            'code' => $code,
            'count' => $count,
            'message' => $message,
            'metadata' => $metadata,
        ], fn ($value) => $value !== []);
    }
}
