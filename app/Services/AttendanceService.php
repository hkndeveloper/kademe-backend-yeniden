<?php

namespace App\Services;

use App\Models\Program;
use App\Models\Attendance;
use App\Models\Participant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AttendanceService
{
    protected QrCodeService $qrCodeService;
    protected CreditService $creditService;

    public function __construct(QrCodeService $qrCodeService, CreditService $creditService)
    {
        $this->qrCodeService = $qrCodeService;
        $this->creditService = $creditService;
    }

    /**
     * QR Kod ile yoklama işlemi (Controllerdan buraya taşındı)
     */
    public function markQrAttendance(User $user, string $qrToken, ?float $lat, ?float $lng)
    {
        $program = Program::where('qr_token', $qrToken)
            ->where('status', 'active')
            ->first();

        if (!$program) {
            throw new \Exception('Geçersiz veya süresi dolmuş QR kod.');
        }

        if ($program->qr_expires_at && now()->isAfter($program->qr_expires_at)) {
            throw new \Exception('Bu QR kodun süresi dolmuş. Lütfen ekrandaki yeni kodu okutun.');
        }

        $participant = Participant::where('user_id', $user->id)
            ->where('project_id', $program->project_id)
            ->where('period_id', $program->period_id)
            ->where('status', 'active')
            ->first();

        if (!$participant) {
            throw new \Exception('Bu programa katılma yetkiniz bulunmuyor.');
        }

        $existing = Attendance::where('program_id', $program->id)
            ->where('user_id', $user->id)
            ->exists();

        if ($existing) {
            throw new \Exception('Yoklamanız zaten alınmış.');
        }

        // Lokasyon Doğrulama (Eğer programda kısıt varsa)
        $isValidLocation = $this->qrCodeService->validateLocation($program, $lat, $lng);

        DB::beginTransaction();
        try {
            $attendance = Attendance::create([
                'program_id' => $program->id,
                'user_id' => $user->id,
                'method' => 'qr',
                'latitude' => $lat,
                'longitude' => $lng,
                'is_valid' => $isValidLocation,
            ]);

            // Krediyi artır (Yoklama bonusu veya telafi)
            $this->creditService->reward(
                $participant, 
                10, // Program başına varsayılan katılım ödülü
                'Etkinlik Katılımı (QR)', 
                $program->id
            );

            DB::commit();

            return [
                'attendance' => $attendance,
                'location_warning' => !$isValidLocation,
                'current_credit' => $participant->credit
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
