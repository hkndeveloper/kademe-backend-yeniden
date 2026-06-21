<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Participant;
use App\Models\Program;
use App\Models\User;

class AttendanceService
{
    public function __construct(
        protected QrCodeService $qrCodeService
    ) {
    }

    public function markQrAttendance(User $user, string $qrToken, ?float $lat, ?float $lng): array
    {
        $program = Program::query()
            ->where('qr_token', $qrToken)
            ->where('status', 'active')
            ->first();

        if (! $program) {
            throw new \Exception('Gecersiz veya suresi dolmus QR kod.');
        }

        if ($program->qr_expires_at && now()->isAfter($program->qr_expires_at)) {
            throw new \Exception('Bu QR kodun suresi dolmus. Lutfen ekrandaki yeni kodu okutun.');
        }

        $participant = Participant::query()
            ->where('user_id', $user->id)
            ->where('project_id', $program->project_id)
            ->when(
                $program->period_id,
                fn ($query) => $query->where('period_id', $program->period_id),
                fn ($query) => $query->whereNull('period_id')
            )
            ->where('status', 'active')
            ->first();

        if (! $participant) {
            throw new \Exception('Bu programa katilma yetkiniz bulunmuyor.');
        }

        $existing = Attendance::query()
            ->where('program_id', $program->id)
            ->where('user_id', $user->id)
            ->exists();

        if ($existing) {
            throw new \Exception('Yoklamaniz zaten alinmis.');
        }

        $isValidLocation = $this->qrCodeService->validateLocation($program, $lat, $lng);

        $attendance = Attendance::query()->create([
            'program_id' => $program->id,
            'user_id' => $user->id,
            'method' => 'qr',
            'latitude' => $lat,
            'longitude' => $lng,
            'is_valid' => $isValidLocation,
        ]);

        return [
            'attendance' => $attendance,
            'location_warning' => ! $isValidLocation,
            'current_credit' => $participant->credit,
        ];
    }
}
