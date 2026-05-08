<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Feedback;
use App\Models\Participant;
use App\Models\Program;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AttendanceController extends Controller
{
    /**
     * Ogrencinin QR kod okutarak yoklama vermesi
     */
    public function markQrAttendance(Request $request)
    {
        $validated = $request->validate([
            'qr_token' => 'required|string',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        $user = $request->user();
        abort_unless($user->role === 'student', 403, 'QR yoklama yalnizca ogrenci paneli icin kullanilabilir.');
        $tokenInput = trim((string) $validated['qr_token']);
        $qrToken = $this->extractQrToken($tokenInput);

        $program = Program::where('qr_token', $qrToken)
            ->where('status', 'active')
            ->first();

        if (! $program) {
            return response()->json(['message' => 'Gecersiz veya suresi dolmus QR kod.'], 400);
        }

        if ($program->qr_expires_at && now()->isAfter($program->qr_expires_at)) {
            return response()->json(['message' => 'Bu QR kodun suresi dolmus. Lutfen ekrandaki yeni kodu okutun.'], 400);
        }

        $participant = Participant::where('user_id', $user->id)
            ->where('project_id', $program->project_id)
            ->where('period_id', $program->period_id)
            ->where('status', 'active')
            ->first();

        if (! $participant) {
            return response()->json(['message' => 'Bu programa katilma yetkiniz bulunmuyor.'], 403);
        }

        $pendingFeedbackProgram = $this->pendingFeedbackBeforeProgram($user->id, $program);
        if ($pendingFeedbackProgram) {
            return response()->json([
                'message' => 'Onceki oturumun degerlendirmesini tamamlamadan yeni QR yoklama veremezsin.',
                'requires_feedback' => true,
                'program_id' => $pendingFeedbackProgram->id,
                'program_title' => $pendingFeedbackProgram->title,
                'redirect_to' => '/student/feedback',
            ], 423);
        }

        $existing = Attendance::where('program_id', $program->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            if (! $existing->is_valid) {
                return response()->json([
                    'message' => 'Bu oturum icin gecerli yoklama kaydin bulunmuyor. Lutfen etkinlik alaninda tekrar deneyin.',
                ], 422);
            }

            return response()->json(['message' => 'Yoklamaniz zaten alinmis.'], 200);
        }

        $latitude = $validated['latitude'] ?? null;
        $longitude = $validated['longitude'] ?? null;

        if ($program->latitude && $program->longitude) {
            if ($latitude === null || $longitude === null) {
                return response()->json(['message' => 'Bu yoklama icin konum izni zorunludur.'], 422);
            }

            $distance = $this->calculateDistance(
                $program->latitude,
                $program->longitude,
                $latitude,
                $longitude,
            );

            $radiusMeters = max((int) ($program->radius_meters ?? 100), 1);

            if ($distance > $radiusMeters) {
                return response()->json([
                    'message' => 'Konumunuz etkinlik alani disinda. Yoklama alinmadi.',
                ], 422);
            }
        }

        Attendance::create([
            'program_id' => $program->id,
            'user_id' => $user->id,
            'method' => 'qr',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'is_valid' => true,
        ]);

        return response()->json([
            'message' => 'Yoklamaniz basariyla alindi. Etkinlik tamamlandiktan sonra degerlendirme formu acilacaktir.',
            'current_credit' => $participant->credit,
        ]);
    }

    /**
     * Haversine formulu ile iki koordinat arasi metre hesabı
     */
    private function calculateDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371000;

        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($lonDelta / 2) * sin($lonDelta / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    private function extractQrToken(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        if (! Str::startsWith($raw, ['http://', 'https://'])) {
            return $raw;
        }

        $parts = parse_url($raw);
        if (! is_array($parts) || empty($parts['query'])) {
            return $raw;
        }

        parse_str((string) $parts['query'], $query);

        $token = isset($query['token']) ? trim((string) $query['token']) : '';

        return $token !== '' ? $token : $raw;
    }

    private function pendingFeedbackBeforeProgram(int $userId, Program $currentProgram): ?Program
    {
        if (! $currentProgram->start_at) {
            return null;
        }

        $attendedCompletedPrograms = Program::query()
            ->where('project_id', $currentProgram->project_id)
            ->when(
                $currentProgram->period_id,
                fn ($query) => $query->where('period_id', $currentProgram->period_id),
                fn ($query) => $query->whereNull('period_id')
            )
            ->where('id', '!=', $currentProgram->id)
            ->where('status', 'completed')
            ->where('start_at', '<', $currentProgram->start_at)
            ->whereHas('attendances', function ($query) use ($userId) {
                $query->where('user_id', $userId)->where('is_valid', true);
            })
            ->orderByDesc('start_at')
            ->get();

        foreach ($attendedCompletedPrograms as $program) {
            $anonymousToken = hash('sha256', sprintf('%s:%s:%s', $userId, $program->id, config('app.key')));
            $hasFeedback = Feedback::query()
                ->where('program_id', $program->id)
                ->where('anonymous_token', $anonymousToken)
                ->exists();

            if (! $hasFeedback) {
                return $program;
            }
        }

        return null;
    }
}
