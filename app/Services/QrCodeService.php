<?php

namespace App\Services;

use App\Models\Program;
use Illuminate\Support\Str;

class QrCodeService
{
    /**
     * Etkinlik için yeni bir QR kod üretir ve veritabanına işler
     */
    public function generateForProgram(Program $program): array
    {
        $qrToken = 'prg_' . $program->id . '_' . Str::random(12);
        
        $rotationSeconds = $program->qr_rotation_seconds ?? 30;
        $expiresAt = now()->addSeconds($rotationSeconds);

        $program->update([
            'status' => 'active',
            'qr_token' => $qrToken,
            'qr_expires_at' => $expiresAt
        ]);

        return [
            'qr_token' => $qrToken,
            'expires_at' => $expiresAt,
            'refresh_in_seconds' => $rotationSeconds
        ];
    }

    /**
     * Konumun etkinlik alanına uygun olup olmadığını Haversine ile hesaplar
     */
    public function validateLocation(Program $program, $userLat, $userLng): bool
    {
        if (!$program->latitude || !$program->longitude || !$userLat || !$userLng) {
            return true; // Lokasyon bilgisi eksikse varsayılan olarak doğru kabul et
        }

        $distance = $this->calculateHaversineDistance(
            $program->latitude, $program->longitude,
            $userLat, $userLng
        );
        
        $radius = $program->radius_meters ?? 100;

        return $distance <= $radius;
    }

    private function calculateHaversineDistance($lat1, $lon1, $lat2, $lon2)
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
}
