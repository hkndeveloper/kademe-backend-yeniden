<?php

namespace App\Jobs;

use App\Models\Program;
use App\Services\QrCodeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RotateQrTokenJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(QrCodeService $qrService): void
    {
        // Statüsü 'active' olan tüm programları bul
        $activePrograms = Program::where('status', 'active')->get();

        foreach ($activePrograms as $program) {
            // Eğer qr kodu yoksa veya süresi 5 saniyeden az kaldıysa yenisini üret
            if (!$program->qr_expires_at || now()->diffInSeconds($program->qr_expires_at, false) < 5) {
                $qrService->generateForProgram($program);
            }
        }
    }
}
